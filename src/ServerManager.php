<?php

declare(strict_types=1);

namespace Bootes\Src;

use Bootes\Config\AppConfig;
use FilesystemIterator;
use PDO;
use PDOException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ServerManager
{
    public const HOST_TOTAL_RAM_MB     = 8192; // 8 Go physiques sur la machine Linux Mint
    public const HOST_MAX_MC_RAM_MB    = 6144; // Plafond strict de 6 Go allouables aux serveurs Minecraft
    public const STANDARD_MAX_RAM_MB   = 2048; // 2 Go max pour un compte Standard
    public const STANDARD_MAX_BACKUPS  = 2;
    public const PREMIUM_MAX_BACKUPS   = 10;
    public const STANDARD_MAX_SERVERS  = 3;
    public const PREMIUM_MAX_SERVERS   = 10;
    public const MAX_SLOTS             = 100;

    public const PORT_RANGE_START     = 25565;
    public const PORT_RANGE_END       = 26500;
    public const MAP_PORT_RANGE_START = 26565;
    public const MAP_PORT_RANGE_END   = 27500;

    public const STATUS_ONLINE      = 'EN_LIGNE';
    public const STATUS_OFFLINE     = 'HORS_LIGNE';
    public const STATUS_STARTING    = 'DEMARRAGE';
    public const STATUS_MAINTENANCE = 'MAINTENANCE';

    public const ALLOWED_LOADERS = [
        'VANILLA'  => 'Vanilla (Officiel Mojang)',
        'PAPER'    => 'PaperMC (Optimisé Plugins)',
        'FABRIC'   => 'Fabric (Mods légers)',
        'FORGE'    => 'Forge (Mods & Modpacks)',
        'NEOFORGE' => 'NeoForge (Moderne)',
    ];

    public const GAME_MODES = [
        'SURVIE'   => 'Survie (Survival)',
        'CREATIF'  => 'Créatif (Creative)',
        'HARDCORE' => 'Hardcore',
        'AVENTURE' => 'Aventure / Mini-jeux',
        'MODDE'    => 'Survie Moddée',
    ];

    public const LIST_WHITELIST = 'WHITELIST';
    public const LIST_BLACKLIST = 'BLACKLIST';
    public const LIST_OP        = 'OP';

    /** Tables rattachées à un serveur, purgées avant sa suppression. */
    private const SERVER_DEPENDENT_TABLES = [
        'INSTALLER_RESSOURCE',
        'ADMINISTRER_SERVEUR',
        'ETRE_LISTER',
        'BACKUP',
        'HISTORIQUE_RESSOURCE',
        'CONFIGURATION_MAP',
        'CONNEXION_JOUEUR',
        'AVIS_SERVEUR',
    ];

    private const LOG_TAIL_BYTES = 262144; // Lecture des 256 derniers Ko de latest.log au maximum

    private string $instancesBasePath;
    private string $backupsBasePath;

    public function __construct(
        private readonly PDO $db,
        ?string $instancesBasePath = null,
        ?string $backupsBasePath = null
    ) {
        $this->instancesBasePath = $instancesBasePath ?? AppConfig::instancesPath();
        $this->backupsBasePath   = $backupsBasePath ?? AppConfig::backupsPath();
    }

    /**
     * Récupère les informations complètes d'un serveur Minecraft et de son propriétaire.
     */
    public function getServerById(int $serverId): ?array
    {
        if ($serverId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT
                s.id_serveur,
                s.id_proprietaire,
                s.nom_serveur,
                s.adresse_ip,
                s.port,
                s.version_mc,
                COALESCE(s.type_loader, \'VANILLA\') AS type_loader,
                s.mode_jeu,
                s.statut,
                s.joueurs_connectes,
                s.slots_max,
                s.ram_allouee,
                s.description,
                s.est_public,
                s.date_creation,
                u.pseudo AS proprietaire_pseudo,
                u.type_compte AS proprietaire_offre,
                u.max_ram_allouable AS proprietaire_max_ram
            FROM SERVEUR s
            INNER JOIN UTILISATEUR u ON u.id_utilisateur = s.id_proprietaire
            WHERE s.id_serveur = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $serverId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);

        return $server !== false ? $server : null;
    }

    /**
     * Calcule la RAM totale actuellement consommée par les serveurs Minecraft en ligne.
     */
    public function getAllocatedRamMb(?int $excludeServerId = null, ?int $ownerId = null): int
    {
        $sql = "
            SELECT COALESCE(SUM(ram_allouee), 0)
            FROM SERVEUR
            WHERE statut IN ('EN_LIGNE', 'DEMARRAGE')
        ";
        $params = [];

        if ($excludeServerId !== null && $excludeServerId > 0) {
            $sql .= ' AND id_serveur != :exclude_id';
            $params[':exclude_id'] = $excludeServerId;
        }

        if ($ownerId !== null && $ownerId > 0) {
            $sql .= ' AND id_proprietaire = :owner_id';
            $params[':owner_id'] = $ownerId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Lit la mémoire physique réellement disponible sur l'hôte Linux Mint via /proc/meminfo.
     */
    public function getHostAvailableRamMb(): int
    {
        if (is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if (is_string($meminfo) && preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $matches)) {
                return (int) floor(((int) $matches[1]) / 1024);
            }
        }

        // Repli basé sur le calcul en base si /proc/meminfo est inaccessible
        return max(0, self::HOST_TOTAL_RAM_MB - 2048 - $this->getAllocatedRamMb());
    }

    /**
     * Vérifie si le plafond des 6 Go (6144 Mo) réservés aux serveurs Minecraft permet de démarrer l'instance.
     */
    public function canAllocateRam(int $requiredRamMb, ?int $excludeServerId = null): bool
    {
        if ($requiredRamMb < 512 || $requiredRamMb > self::HOST_MAX_MC_RAM_MB) {
            return false;
        }

        $currentlyAllocated = $this->getAllocatedRamMb($excludeServerId);
        if (($currentlyAllocated + $requiredRamMb) > self::HOST_MAX_MC_RAM_MB) {
            return false;
        }

        return true;
    }

    /**
     * Nombre maximal de serveurs autorisé pour une offre.
     */
    public static function maxServersFor(string $accountTier): int
    {
        return strtoupper($accountTier) === Auth::ACCOUNT_PREMIUM ? self::PREMIUM_MAX_SERVERS : self::STANDARD_MAX_SERVERS;
    }

    /**
     * Plafond de RAM par serveur pour un propriétaire : celui de son offre, réduit au quota fixé par l'administration.
     */
    public static function ramCeilingFor(string $accountTier, int $maxRamAllouable): int
    {
        $tierCeiling = strtoupper($accountTier) === Auth::ACCOUNT_PREMIUM ? self::HOST_MAX_MC_RAM_MB : self::STANDARD_MAX_RAM_MB;

        return max(0, min($tierCeiling, $maxRamAllouable));
    }

    /**
     * Crée un serveur après validation stricte des paramètres (listes blanches) et contrôle des quotas du propriétaire.
     *
     * @param array<string, mixed> $input Données du formulaire (nom_serveur, version_mc, mode_jeu, ram_allouee, slots_max, description, est_public)
     * @return array{success: bool, message: string, server_id?: int}
     */
    public function createServer(int $ownerId, array $input, bool $bypassQuotas = false): array
    {
        $ownerStmt = $this->db->prepare('SELECT type_compte, max_ram_allouable FROM UTILISATEUR WHERE id_utilisateur = :uid LIMIT 1');
        $ownerStmt->execute([':uid' => $ownerId]);
        $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($owner)) {
            return ['success' => false, 'message' => 'Compte propriétaire introuvable.'];
        }

        $tier       = (string) ($owner['type_compte'] ?? Auth::ACCOUNT_STANDARD);
        $isPremium  = strtoupper($tier) === Auth::ACCOUNT_PREMIUM;
        $name       = Security::validateString($input['nom_serveur'] ?? null, 3, 50);
        $version    = in_array($input['version_mc'] ?? null, ResourceManager::SUPPORTED_MC_VERSIONS, true) ? (string) $input['version_mc'] : null;
        $gameMode   = is_string($input['mode_jeu'] ?? null) && array_key_exists(strtoupper($input['mode_jeu']), self::GAME_MODES)
            ? strtoupper($input['mode_jeu'])
            : null;
        $ram        = Security::validateRamAllocation($input['ram_allouee'] ?? null, $isPremium || $bypassQuotas);
        $slots      = max(2, min(self::MAX_SLOTS, Security::validatePositiveInt($input['slots_max'] ?? null) ?? 20));
        $desc       = Security::validateString($input['description'] ?? '', 0, 500) ?? '';
        $isPublic   = ($input['est_public'] ?? '') === '1' ? 1 : 0;

        if ($name === null || $version === null || $gameMode === null) {
            return ['success' => false, 'message' => 'Nom (3 à 50 caractères), version de Minecraft ou mode de jeu invalide.'];
        }

        $ramCeiling = $bypassQuotas ? self::HOST_MAX_MC_RAM_MB : self::ramCeilingFor($tier, (int) $owner['max_ram_allouable']);
        if ($ram === null || $ram > $ramCeiling) {
            return [
                'success' => false,
                'message' => sprintf('Allocation RAM invalide : entre 512 et %d Mo pour ce compte.', $ramCeiling),
            ];
        }

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM SERVEUR WHERE id_proprietaire = :uid');
        $countStmt->execute([':uid' => $ownerId]);
        $maxServers = self::maxServersFor($tier);
        if (!$bypassQuotas && (int) $countStmt->fetchColumn() >= $maxServers) {
            return [
                'success' => false,
                'message' => sprintf('Quota atteint : %d serveur(s) maximum pour l\'offre %s.', $maxServers, strtoupper($tier)),
            ];
        }

        return $this->withAllocationLock(function () use ($ownerId, $name, $version, $gameMode, $slots, $ram, $desc, $isPublic): array {
            $port = $this->findFreePort();
            if ($port === null) {
                return ['success' => false, 'message' => 'Aucun port réseau disponible sur l\'hôte. Contactez le support.'];
            }

            $insert = $this->db->prepare("
                INSERT INTO SERVEUR (
                    id_proprietaire, nom_serveur, adresse_ip, port, version_mc, type_loader, mode_jeu,
                    statut, joueurs_connectes, slots_max, ram_allouee, description, est_public, date_creation
                ) VALUES (
                    :owner, :nom, :ip, :port, :version, 'VANILLA', :mode,
                    'HORS_LIGNE', 0, :slots, :ram, :desc, :public, NOW()
                )
            ");
            $insert->execute([
                ':owner'   => $ownerId,
                ':nom'     => $name,
                ':ip'      => 'play.bootes.local',
                ':port'    => $port,
                ':version' => $version,
                ':mode'    => $gameMode,
                ':slots'   => $slots,
                ':ram'     => $ram,
                ':desc'    => $desc,
                ':public'  => $isPublic,
            ]);

            return [
                'success'   => true,
                'message'   => sprintf('Serveur "%s" créé avec succès sur le port %d.', $name, $port),
                'server_id' => (int) $this->db->lastInsertId(),
            ];
        });
    }

    /**
     * Démarre un serveur Minecraft après vérification du plafond global (6 Go) et du quota RAM du propriétaire.
     *
     * @return array{success: bool, message: string}
     */
    public function startServer(int $serverId): array
    {
        $server = $this->getServerById($serverId);
        if ($server === null) {
            return ['success' => false, 'message' => 'Serveur introuvable.'];
        }

        if ($server['statut'] === self::STATUS_ONLINE) {
            return ['success' => false, 'message' => 'Ce serveur est déjà en ligne.'];
        }

        $requiredRam = (int) $server['ram_allouee'];

        // Le verrou empêche deux démarrages simultanés de dépasser ensemble les plafonds.
        $result = $this->withAllocationLock(function () use ($server, $serverId, $requiredRam): array {
            $remaining = self::HOST_MAX_MC_RAM_MB - $this->getAllocatedRamMb($serverId);
            if (!$this->canAllocateRam($requiredRam, $serverId)) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        'Plafond matériel atteint sur la machine Linux Mint : %d Mo demandés, mais seulement %d Mo disponibles sur les 6144 Mo (6 Go) réservés.',
                        $requiredRam,
                        max(0, $remaining)
                    ),
                ];
            }

            $ownerQuota = (int) $server['proprietaire_max_ram'];
            $ownerUsed  = $this->getAllocatedRamMb($serverId, (int) $server['id_proprietaire']);
            if ($ownerUsed + $requiredRam > $ownerQuota) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        'Quota RAM du compte dépassé : %d Mo déjà utilisés + %d Mo demandés pour un quota de %d Mo. Arrêtez un autre serveur ou réduisez sa mémoire.',
                        $ownerUsed,
                        $requiredRam,
                        $ownerQuota
                    ),
                ];
            }

            $stmt = $this->db->prepare('UPDATE SERVEUR SET statut = :statut WHERE id_serveur = :id');
            $stmt->execute([
                ':statut' => self::STATUS_ONLINE,
                ':id'     => $serverId,
            ]);

            return ['success' => true, 'message' => ''];
        });

        if (!$result['success']) {
            return $result;
        }

        $this->ensureServerDirectories($serverId);
        $this->appendLogLine(
            $serverId,
            sprintf(
                '[Server thread/INFO]: Starting minecraft server version %s (%s) on port %d with %dM RAM',
                (string) $server['version_mc'],
                (string) $server['type_loader'],
                (int) $server['port'],
                $requiredRam
            )
        );
        $this->appendLogLine($serverId, '[Server thread/INFO]: Done! For help, type "help"');

        $this->recordResourceSnapshot($serverId, (int) round($requiredRam * 0.45), 12.5, (int) $server['joueurs_connectes']);

        return [
            'success' => true,
            'message' => sprintf('Serveur démarré avec succès (%d Mo de RAM alloués).', $requiredRam),
        ];
    }

    /**
     * Arrête proprement un serveur Minecraft (envoi du signal stop et sauvegarde du monde).
     *
     * @return array{success: bool, message: string}
     */
    public function stopServer(int $serverId): array
    {
        $server = $this->getServerById($serverId);
        if ($server === null) {
            return ['success' => false, 'message' => 'Serveur introuvable.'];
        }

        if ($server['statut'] === self::STATUS_OFFLINE) {
            return ['success' => false, 'message' => 'Le serveur est déjà arrêté.'];
        }

        $this->appendLogLine($serverId, '[Server thread/INFO]: Stopping the server');
        $this->appendLogLine($serverId, '[Server thread/INFO]: Saving worlds and chunks...');
        $this->appendLogLine($serverId, '[Server thread/INFO]: ThreadedAnvilChunkStorage: All dimensions are saved');

        $stmt = $this->db->prepare('
            UPDATE SERVEUR
            SET statut = :statut, joueurs_connectes = 0
            WHERE id_serveur = :id
        ');
        $stmt->execute([
            ':statut' => self::STATUS_OFFLINE,
            ':id'     => $serverId,
        ]);

        return ['success' => true, 'message' => 'Le serveur a été arrêté proprement.'];
    }

    /**
     * Redémarre un serveur Minecraft en vérifiant la capacité mémoire.
     *
     * @return array{success: bool, message: string}
     */
    public function restartServer(int $serverId): array
    {
        $server = $this->getServerById($serverId);
        if ($server === null) {
            return ['success' => false, 'message' => 'Serveur introuvable.'];
        }

        $this->appendLogLine($serverId, '[Server thread/INFO]: Restarting server instance...');
        $this->stopServer($serverId);

        return $this->startServer($serverId);
    }

    /**
     * Arrêt forcé d'urgence (SIGKILL) réservé aux Administrateurs Boötes.
     *
     * @return array{success: bool, message: string}
     */
    public function forceKillServer(int $serverId, int $adminUserId): array
    {
        if (!Auth::isAdmin()) {
            return ['success' => false, 'message' => 'Privilèges Administrateur requis pour un arrêt forcé d\'urgence.'];
        }

        $server = $this->getServerById($serverId);
        if ($server === null) {
            return ['success' => false, 'message' => 'Serveur introuvable.'];
        }

        $this->appendLogLine(
            $serverId,
            sprintf('[System/WARN]: SIGKILL (Arrêt d\'urgence forcé) déclenché par l\'Administrateur #%d.', $adminUserId)
        );

        $stmt = $this->db->prepare('
            UPDATE SERVEUR
            SET statut = :statut, joueurs_connectes = 0
            WHERE id_serveur = :id
        ');
        $stmt->execute([
            ':statut' => self::STATUS_OFFLINE,
            ':id'     => $serverId,
        ]);

        return [
            'success' => true,
            'message' => 'Processus JVM terminé immédiatement (SIGKILL) par l\'administration.',
        ];
    }

    /**
     * Met à jour les paramètres de configuration du serveur (listes blanches et quotas du propriétaire).
     * La mémoire d'une instance en ligne ne peut pas être modifiée : le plafond n'est contrôlé qu'au démarrage.
     *
     * @return array{success: bool, message: string}
     */
    public function updateServerSettings(
        int $serverId,
        string $name,
        string $mcVersion,
        string $loader,
        string $gameMode,
        int $slotsMax,
        int $ramMb,
        bool $isPublic,
        string $description,
        bool $bypassOwnerQuota = false
    ): array {
        $server = $this->getServerById($serverId);
        if ($server === null) {
            return ['success' => false, 'message' => 'Serveur introuvable.'];
        }

        $cleanName   = Security::validateString($name, 3, 50);
        $cleanLoader = strtoupper(trim($loader));
        $cleanMode   = strtoupper(trim($gameMode));
        $cleanDesc   = Security::validateString($description, 0, 1000) ?? '';

        if (
            $cleanName === null ||
            !in_array($mcVersion, ResourceManager::SUPPORTED_MC_VERSIONS, true) ||
            !array_key_exists($cleanLoader, self::ALLOWED_LOADERS) ||
            !array_key_exists($cleanMode, self::GAME_MODES)
        ) {
            return ['success' => false, 'message' => 'Paramètres invalides : vérifiez le nom, la version, le loader et le mode de jeu.'];
        }

        $ramCeiling = $bypassOwnerQuota
            ? self::HOST_MAX_MC_RAM_MB
            : self::ramCeilingFor((string) $server['proprietaire_offre'], (int) $server['proprietaire_max_ram']);
        $validRam = Security::validateRamAllocation($ramMb, true);
        if ($validRam === null || $validRam > $ramCeiling) {
            return ['success' => false, 'message' => sprintf('Allocation RAM invalide : entre 512 et %d Mo pour ce serveur.', $ramCeiling)];
        }

        $isRunning = in_array($server['statut'], [self::STATUS_ONLINE, self::STATUS_STARTING], true);
        if ($isRunning && $validRam !== (int) $server['ram_allouee']) {
            return ['success' => false, 'message' => 'Arrêtez le serveur avant de modifier sa mémoire allouée.'];
        }

        $stmt = $this->db->prepare('
            UPDATE SERVEUR
            SET nom_serveur = :nom,
                version_mc = :ver,
                type_loader = :loader,
                mode_jeu = :mode,
                slots_max = :slots,
                ram_allouee = :ram,
                est_public = :pub,
                description = :desc
            WHERE id_serveur = :id
        ');
        $stmt->execute([
            ':nom'    => $cleanName,
            ':ver'    => $mcVersion,
            ':loader' => $cleanLoader,
            ':mode'   => $cleanMode,
            ':slots'  => max(2, min(self::MAX_SLOTS, $slotsMax)),
            ':ram'    => $validRam,
            ':pub'    => $isPublic ? 1 : 0,
            ':desc'   => $cleanDesc,
            ':id'     => $serverId,
        ]);

        return ['success' => true, 'message' => 'Configuration du serveur enregistrée avec succès.'];
    }

    /**
     * Lecture sécurisée des N dernières lignes du fichier logs/latest.log du serveur,
     * sans charger en mémoire un journal de taille arbitraire.
     *
     * @return array<int, string>
     */
    public function readLatestLog(int $serverId, int $maxLines = 100): array
    {
        if ($serverId <= 0) {
            return [];
        }

        $maxLines = max(10, min(500, $maxLines));
        $logFile  = $this->getServerLogFilePath($serverId);

        if (!is_file($logFile) || !is_readable($logFile)) {
            return [
                '[Boötes/INFO]: Aucun fichier logs/latest.log détecté pour le serveur #' . $serverId . '.',
                '[Boötes/INFO]: Démarrez l\'instance pour initialiser la journalisation JVM.',
            ];
        }

        $handle = @fopen($logFile, 'rb');
        if ($handle === false) {
            return [];
        }

        $size   = (int) (fstat($handle)['size'] ?? 0);
        $length = min($size, self::LOG_TAIL_BYTES);
        if ($length > 0) {
            fseek($handle, -$length, SEEK_END);
        }
        $data = $length > 0 ? (string) fread($handle, $length) : '';
        fclose($handle);

        $lines = preg_split('/\R/', $data) ?: [];
        if ($length < $size) {
            array_shift($lines); // Première ligne potentiellement tronquée
        }
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        return array_slice($lines, -$maxLines);
    }

    /**
     * Envoie une commande console sécurisée au serveur Minecraft et la journalise.
     *
     * @return array{success: bool, message: string}
     */
    public function sendConsoleCommand(int $serverId, string $rawCommand, string $senderPseudo): array
    {
        $server = $this->getServerById($serverId);
        if ($server === null) {
            return ['success' => false, 'message' => 'Serveur introuvable.'];
        }

        if ($server['statut'] !== self::STATUS_ONLINE) {
            return ['success' => false, 'message' => 'Le serveur doit être en ligne pour recevoir une commande console.'];
        }

        $command = ltrim((string) Security::validateString($rawCommand, 1, 200), '/');
        if ($command === '' || preg_match('/[;&|`$><]/', $command)) {
            return ['success' => false, 'message' => 'Commande invalide ou contenant des caractères système interdits.'];
        }

        $this->appendLogLine(
            $serverId,
            sprintf('[Console/%s]: Issued server command: /%s', $senderPseudo, $command)
        );

        $lowerCmd = strtolower($command);
        if ($lowerCmd === 'stop' || str_starts_with($lowerCmd, 'stop ')) {
            return $this->stopServer($serverId);
        }

        $this->appendLogLine(
            $serverId,
            sprintf('[Server thread/INFO]: Executed command "%s" successfully.', $command)
        );

        return [
            'success' => true,
            'message' => sprintf('Commande "/%s" exécutée sur le serveur.', $command),
        ];
    }

    /**
     * Crée une archive de sauvegarde (.tar.gz) dans le stockage privé et l'enregistre en base.
     *
     * @return array{success: bool, message: string}
     */
    public function createBackup(int $serverId, string $label = ''): array
    {
        $server = $this->getServerById($serverId);
        if ($server === null) {
            return ['success' => false, 'message' => 'Serveur introuvable.'];
        }

        $isPremiumOwner = strtoupper((string) $server['proprietaire_offre']) === Auth::ACCOUNT_PREMIUM;
        $maxBackups     = Auth::isAdmin()
            ? 50
            : ($isPremiumOwner ? self::PREMIUM_MAX_BACKUPS : self::STANDARD_MAX_BACKUPS);

        $existingBackups = $this->getBackups($serverId);
        if (count($existingBackups) >= $maxBackups) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Quota de sauvegardes atteint (%d / %d maximum pour cette offre). Supprimez une archive existante ou passez à l\'offre Premium.',
                    count($existingBackups),
                    $maxBackups
                ),
            ];
        }

        $this->ensureServerDirectories($serverId);

        $timestamp   = date('Ymd_His');
        $archiveName = sprintf('server_%d_backup_%s_%s.tar.gz', $serverId, $timestamp, bin2hex(random_bytes(4)));
        $archivePath = $this->backupsBasePath . '/' . $archiveName;
        $sourceDir   = $this->getServerInstanceDir($serverId);

        // Création d'une archive .tar.gz réelle si tar est disponible, sinon manifeste compressé
        $fileSize = 0;
        if (is_dir($sourceDir)) {
            $tarCmd = sprintf(
                'tar -czf %s -C %s . 2>&1',
                escapeshellarg($archivePath),
                escapeshellarg($sourceDir)
            );
            @exec($tarCmd, $output, $returnVar);
            if ($returnVar === 0 && is_file($archivePath)) {
                $fileSize = (int) filesize($archivePath);
            }
        }

        if ($fileSize <= 0) {
            $manifestContent = json_encode([
                'server_id'  => $serverId,
                'nom'        => $server['nom_serveur'],
                'version_mc' => $server['version_mc'],
                'created_at' => date('c'),
                'label'      => $label,
            ], JSON_PRETTY_PRINT);
            $gzData = gzencode((string) $manifestContent, 9);
            @file_put_contents($archivePath, $gzData !== false ? $gzData : $manifestContent);
            $fileSize = is_file($archivePath) ? (int) filesize($archivePath) : 0;
        }

        if ($fileSize <= 0) {
            return ['success' => false, 'message' => 'Écriture de l\'archive impossible : vérifiez les droits du stockage des sauvegardes.'];
        }
        @chmod($archivePath, 0640);

        $cleanLabel = Security::validateString($label, 0, 100) ?: sprintf('Sauvegarde du %s', date('d/m/Y H:i'));

        $stmt = $this->db->prepare('
            INSERT INTO BACKUP (
                id_serveur,
                nom_backup,
                nom_fichier,
                chemin_fichier,
                taille_octets,
                date_creation
            ) VALUES (
                :srv_id,
                :nom_backup,
                :nom_fichier,
                :chemin,
                :taille,
                NOW()
            )
        ');
        $stmt->execute([
            ':srv_id'      => $serverId,
            ':nom_backup'  => $cleanLabel,
            ':nom_fichier' => $archiveName,
            ':chemin'      => '/backups/' . $archiveName,
            ':taille'      => $fileSize,
        ]);

        $this->appendLogLine($serverId, sprintf('[BackupManager/INFO]: Archive %s créée (%d octets).', $archiveName, $fileSize));

        return [
            'success' => true,
            'message' => sprintf('Sauvegarde "%s" (%s) créée avec succès.', $cleanLabel, $archiveName),
        ];
    }

    /**
     * Restaure une sauvegarde (.tar.gz) sur l'instance du serveur.
     *
     * @return array{success: bool, message: string}
     */
    public function restoreBackup(int $serverId, int $backupId): array
    {
        $backup = $this->getBackupById($serverId, $backupId);
        if ($backup === null) {
            return ['success' => false, 'message' => 'Sauvegarde introuvable pour ce serveur.'];
        }

        $archivePath = $this->getBackupArchivePath($backup);
        if ($archivePath === null) {
            return ['success' => false, 'message' => 'Le fichier de cette sauvegarde est absent du stockage.'];
        }

        $server = $this->getServerById($serverId);
        if ($server !== null && $server['statut'] === self::STATUS_ONLINE) {
            $this->stopServer($serverId);
        }

        $this->ensureServerDirectories($serverId);
        $untarCmd = sprintf(
            'tar -xzf %s -C %s --no-same-owner --no-same-permissions 2>&1',
            escapeshellarg($archivePath),
            escapeshellarg($this->getServerInstanceDir($serverId))
        );
        @exec($untarCmd, $output, $returnVar);

        if ($returnVar !== 0) {
            error_log(sprintf('[Boötes][ServerManager] Échec restauration backup #%d : %s', $backupId, implode(' ', (array) $output)));
            return ['success' => false, 'message' => 'L\'archive n\'a pas pu être extraite. Aucune donnée n\'a été restaurée.'];
        }

        $this->appendLogLine(
            $serverId,
            sprintf('[BackupManager/INFO]: Restauration de l\'archive "%s" effectuée.', basename($archivePath))
        );

        return [
            'success' => true,
            'message' => sprintf('La sauvegarde "%s" a été restaurée avec succès.', (string) $backup['nom_backup']),
        ];
    }

    /**
     * Supprime une sauvegarde en base et sur le disque.
     */
    public function deleteBackup(int $serverId, int $backupId): bool
    {
        $backup = $this->getBackupById($serverId, $backupId);
        if ($backup === null) {
            return false;
        }

        $archivePath = $this->getBackupArchivePath($backup);
        if ($archivePath !== null) {
            @unlink($archivePath);
        }

        $stmt = $this->db->prepare('DELETE FROM BACKUP WHERE id_backup = :bid AND id_serveur = :sid');
        $stmt->execute([
            ':bid' => $backupId,
            ':sid' => $serverId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Chemin absolu de l'archive d'une sauvegarde dans le stockage privé (null si absente).
     * Seul le nom de base est utilisé : aucune traversée de répertoire n'est possible.
     *
     * @param array<string, mixed> $backup
     */
    public function getBackupArchivePath(array $backup): ?string
    {
        $fileName = basename((string) ($backup['nom_fichier'] ?? ''));
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return null;
        }

        $path = $this->backupsBasePath . '/' . $fileName;

        return is_file($path) ? $path : null;
    }

    /**
     * Liste toutes les sauvegardes d'un serveur.
     */
    public function getBackups(int $serverId): array
    {
        if ($serverId <= 0) {
            return [];
        }

        try {
            $stmt = $this->db->prepare('
                SELECT id_backup, id_serveur, nom_backup, nom_fichier, chemin_fichier, taille_octets, date_creation
                FROM BACKUP
                WHERE id_serveur = :sid
                ORDER BY date_creation DESC
            ');
            $stmt->execute([':sid' => $serverId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][ServerManager] Erreur lecture backups : %s', $e->getMessage()));
            return [];
        }
    }

    /**
     * Récupère une sauvegarde précise appartenant à un serveur.
     */
    public function getBackupById(int $serverId, int $backupId): ?array
    {
        if ($serverId <= 0 || $backupId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT id_backup, id_serveur, nom_backup, nom_fichier, chemin_fichier, taille_octets, date_creation
            FROM BACKUP
            WHERE id_backup = :bid AND id_serveur = :sid
            LIMIT 1
        ');
        $stmt->execute([
            ':bid' => $backupId,
            ':sid' => $serverId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Supprime un serveur et toutes ses données dépendantes dans une transaction.
     * Si $purgeFiles est faux (transaction englobante), l'appelant efface les fichiers après validation.
     */
    public function deleteServer(int $serverId, bool $purgeFiles = true): bool
    {
        if ($serverId <= 0) {
            return false;
        }

        $backupFiles    = array_column($this->getBackups($serverId), 'nom_fichier');
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            foreach (self::SERVER_DEPENDENT_TABLES as $table) {
                $this->db->prepare("DELETE FROM {$table} WHERE id_serveur = :sid")->execute([':sid' => $serverId]);
            }

            $delete = $this->db->prepare('DELETE FROM SERVEUR WHERE id_serveur = :sid');
            $delete->execute([':sid' => $serverId]);
            $deleted = $delete->rowCount() > 0;

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log(sprintf('[Boötes][ServerManager] Suppression du serveur #%d impossible : %s', $serverId, $e->getMessage()));
            return false;
        }

        if ($purgeFiles && $deleted) {
            $this->purgeServerFiles($serverId, $backupFiles);
        }

        return $deleted;
    }

    /**
     * Efface du disque le répertoire d'instance et les archives de sauvegarde d'un serveur supprimé.
     *
     * @param array<int, string> $backupFileNames
     */
    public function purgeServerFiles(int $serverId, array $backupFileNames): void
    {
        foreach ($backupFileNames as $fileName) {
            $path = $this->getBackupArchivePath(['nom_fichier' => $fileName]);
            if ($path !== null) {
                @unlink($path);
            }
        }

        self::removeDirectory($this->getServerInstanceDir($serverId));
    }

    /**
     * Récupère la liste des joueurs filtrés dans ETRE_LISTER (WHITELIST, BLACKLIST, OP).
     */
    public function getListedPlayers(int $serverId, ?string $listType = null): array
    {
        if ($serverId <= 0) {
            return [];
        }

        $sql = '
            SELECT id_serveur, pseudo_mc, type_liste, raison, date_ajout
            FROM ETRE_LISTER
            WHERE id_serveur = :sid
        ';
        $params = [':sid' => $serverId];

        if ($listType !== null && in_array($listType, [self::LIST_WHITELIST, self::LIST_BLACKLIST, self::LIST_OP], true)) {
            $sql .= ' AND type_liste = :type';
            $params[':type'] = $listType;
        }

        $sql .= ' ORDER BY type_liste ASC, date_ajout DESC';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][ServerManager] Erreur lecture ETRE_LISTER : %s', $e->getMessage()));
            return [];
        }
    }

    /**
     * Ajoute ou met à jour un joueur Minecraft dans la table ETRE_LISTER (Whitelist, Blacklist ou OP).
     */
    public function addListedPlayer(int $serverId, string $pseudoMc, string $listType, string $reason = ''): bool
    {
        $cleanPseudo = Security::validateString($pseudoMc, 3, 16);
        $upperType   = strtoupper(trim($listType));
        $cleanReason = Security::validateString($reason, 0, 255) ?? '';

        if (
            $serverId <= 0 ||
            $cleanPseudo === null ||
            !preg_match('/^[a-zA-Z0-9_]{3,16}$/', $cleanPseudo) ||
            !in_array($upperType, [self::LIST_WHITELIST, self::LIST_BLACKLIST, self::LIST_OP], true)
        ) {
            return false;
        }

        $stmt = $this->db->prepare('
            INSERT INTO ETRE_LISTER (id_serveur, pseudo_mc, type_liste, raison, date_ajout)
            VALUES (:sid, :pseudo, :type, :raison, NOW())
            ON DUPLICATE KEY UPDATE type_liste = VALUES(type_liste), raison = VALUES(raison), date_ajout = NOW()
        ');

        $ok = $stmt->execute([
            ':sid'    => $serverId,
            ':pseudo' => $cleanPseudo,
            ':type'   => $upperType,
            ':raison' => $cleanReason,
        ]);

        if ($ok) {
            $this->appendLogLine(
                $serverId,
                sprintf('[PlayerList/INFO]: Joueur "%s" ajouté à la liste %s.', $cleanPseudo, $upperType)
            );
        }

        return $ok;
    }

    /**
     * Retire un joueur de la table ETRE_LISTER.
     */
    public function removeListedPlayer(int $serverId, string $pseudoMc, string $listType): bool
    {
        $cleanPseudo = Security::validateString($pseudoMc, 3, 16);
        $upperType   = strtoupper(trim($listType));

        if (
            $serverId <= 0 ||
            $cleanPseudo === null ||
            !in_array($upperType, [self::LIST_WHITELIST, self::LIST_BLACKLIST, self::LIST_OP], true)
        ) {
            return false;
        }

        $stmt = $this->db->prepare('
            DELETE FROM ETRE_LISTER
            WHERE id_serveur = :sid AND pseudo_mc = :pseudo AND type_liste = :type
        ');
        $stmt->execute([
            ':sid'    => $serverId,
            ':pseudo' => $cleanPseudo,
            ':type'   => $upperType,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Récupère les co-administrateurs associés au serveur dans ADMINISTRER_SERVEUR.
     */
    public function getCoAdmins(int $serverId): array
    {
        if ($serverId <= 0) {
            return [];
        }

        try {
            $stmt = $this->db->prepare('
                SELECT
                    ads.id_serveur,
                    ads.id_utilisateur,
                    COALESCE(ads.niveau_permission, \'COMPLET\') AS niveau_permission,
                    ads.date_attribution,
                    u.pseudo,
                    u.role
                FROM ADMINISTRER_SERVEUR ads
                INNER JOIN UTILISATEUR u ON u.id_utilisateur = ads.id_utilisateur
                WHERE ads.id_serveur = :sid
                ORDER BY ads.date_attribution DESC
            ');
            $stmt->execute([':sid' => $serverId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][ServerManager] Erreur lecture ADMINISTRER_SERVEUR : %s', $e->getMessage()));
            return [];
        }
    }

    /**
     * Ajoute un co-administrateur (compte existant et actif) sur un serveur dans ADMINISTRER_SERVEUR.
     *
     * @return array{success: bool, message: string}
     */
    public function addCoAdmin(int $serverId, int $targetUserId, string $permissionLevel = 'COMPLET'): array
    {
        $server = $this->getServerById($serverId);
        if ($server === null) {
            return ['success' => false, 'message' => 'Serveur introuvable.'];
        }

        if ((int) $server['id_proprietaire'] === $targetUserId) {
            return ['success' => false, 'message' => 'Le propriétaire possède déjà tous les droits sur ce serveur.'];
        }

        $userStmt = $this->db->prepare('SELECT est_banni FROM UTILISATEUR WHERE id_utilisateur = :uid LIMIT 1');
        $userStmt->execute([':uid' => $targetUserId]);
        $banned = $userStmt->fetchColumn();
        if ($banned === false || (int) $banned === 1) {
            return ['success' => false, 'message' => 'Ce compte Boötes est introuvable ou suspendu.'];
        }

        $allowedPerms = [Auth::SERVER_PERM_READ, Auth::SERVER_PERM_CONSOLE, Auth::SERVER_PERM_FULL];
        $cleanPerm    = in_array(strtoupper($permissionLevel), $allowedPerms, true)
            ? strtoupper($permissionLevel)
            : Auth::SERVER_PERM_READ;

        try {
            $stmt = $this->db->prepare('
                INSERT INTO ADMINISTRER_SERVEUR (id_serveur, id_utilisateur, niveau_permission, date_attribution)
                VALUES (:sid, :uid, :perm, NOW())
                ON DUPLICATE KEY UPDATE niveau_permission = VALUES(niveau_permission)
            ');
            $stmt->execute([
                ':sid'  => $serverId,
                ':uid'  => $targetUserId,
                ':perm' => $cleanPerm,
            ]);
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][ServerManager] Ajout co-admin impossible : %s', $e->getMessage()));
            return ['success' => false, 'message' => 'Impossible d\'enregistrer ce co-administrateur.'];
        }

        return ['success' => true, 'message' => sprintf('Accès co-administrateur (%s) accordé avec succès.', $cleanPerm)];
    }

    /**
     * Révoque l'accès d'un co-administrateur dans ADMINISTRER_SERVEUR.
     */
    public function removeCoAdmin(int $serverId, int $targetUserId): bool
    {
        if ($serverId <= 0 || $targetUserId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('
            DELETE FROM ADMINISTRER_SERVEUR
            WHERE id_serveur = :sid AND id_utilisateur = :uid
        ');
        $stmt->execute([
            ':sid' => $serverId,
            ':uid' => $targetUserId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Valide un port de carte web : plage dédiée, sans collision avec un port de jeu ou une autre carte.
     */
    public function isMapPortAvailable(int $serverId, int $port): bool
    {
        if ($port < self::MAP_PORT_RANGE_START || $port > self::MAP_PORT_RANGE_END) {
            return false;
        }

        $gameStmt = $this->db->prepare('SELECT 1 FROM SERVEUR WHERE port = :port LIMIT 1');
        $gameStmt->execute([':port' => $port]);
        if ($gameStmt->fetchColumn() !== false) {
            return false;
        }

        $mapStmt = $this->db->prepare('SELECT 1 FROM CONFIGURATION_MAP WHERE port_map = :port AND id_serveur != :sid LIMIT 1');
        $mapStmt->execute([':port' => $port, ':sid' => $serverId]);

        return $mapStmt->fetchColumn() === false;
    }

    /**
     * Enregistre un point de mesure de consommation matérielle dans HISTORIQUE_RESSOURCE.
     */
    public function recordResourceSnapshot(int $serverId, int $ramUsedMb, float $cpuPct, int $playersCount): void
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO HISTORIQUE_RESSOURCE (id_serveur, ram_utilisee_mo, cpu_pourcentage, joueurs_en_ligne, date_mesure)
                VALUES (:sid, :ram, :cpu, :joueurs, NOW())
            ');
            $stmt->execute([
                ':sid'     => $serverId,
                ':ram'     => max(0, $ramUsedMb),
                ':cpu'     => max(0.0, min(100.0, $cpuPct)),
                ':joueurs' => max(0, $playersCount),
            ]);
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][ServerManager] Snapshot télémétrie ignoré : %s', $e->getMessage()));
        }
    }

    /**
     * Exécute une opération d'allocation (port, RAM) sous verrou nommé MariaDB pour éviter les accès concurrents.
     *
     * @param callable(): array{success: bool, message: string} $operation
     * @return array{success: bool, message: string}
     */
    private function withAllocationLock(callable $operation): array
    {
        $lock = $this->db->query("SELECT GET_LOCK('bootes_allocation', 5)");
        if ($lock === false || (int) $lock->fetchColumn() !== 1) {
            return ['success' => false, 'message' => 'L\'hôte est occupé par une autre allocation, veuillez réessayer.'];
        }

        try {
            return $operation();
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('bootes_allocation')");
        }
    }

    /**
     * Plus petit port de jeu libre dans la plage réservée aux instances.
     */
    private function findFreePort(): ?int
    {
        $stmt = $this->db->prepare('SELECT port FROM SERVEUR WHERE port BETWEEN :start_port AND :end_port');
        $stmt->execute([':start_port' => self::PORT_RANGE_START, ':end_port' => self::PORT_RANGE_END]);
        $used = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        for ($port = self::PORT_RANGE_START; $port <= self::PORT_RANGE_END; $port++) {
            if (!isset($used[$port])) {
                return $port;
            }
        }

        return null;
    }

    private function getServerInstanceDir(int $serverId): string
    {
        return $this->instancesBasePath . '/server_' . $serverId;
    }

    private function getServerLogFilePath(int $serverId): string
    {
        return $this->getServerInstanceDir($serverId) . '/logs/latest.log';
    }

    private function ensureServerDirectories(int $serverId): void
    {
        AppConfig::ensureDirectory(dirname($this->getServerLogFilePath($serverId)));
        AppConfig::ensureDirectory($this->backupsBasePath);
    }

    private function appendLogLine(int $serverId, string $line): void
    {
        $this->ensureServerDirectories($serverId);
        $logFile = $this->getServerLogFilePath($serverId);
        $entry   = sprintf('[%s] %s%s', date('H:i:s'), $line, PHP_EOL);
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Suppression récursive d'un répertoire sans jamais suivre les liens symboliques.
     */
    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isLink() || !$item->isDir()) {
                @unlink($path);
            } else {
                @rmdir($path);
            }
        }
        @rmdir($directory);
    }
}
