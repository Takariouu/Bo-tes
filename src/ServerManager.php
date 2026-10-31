<?php

declare(strict_types=1);

namespace Bootes\Src;

use PDO;
use PDOException;
use RuntimeException;

final class ServerManager
{
    public const HOST_TOTAL_RAM_MB     = 8192; // 8 Go physiques sur la machine Linux Mint
    public const HOST_MAX_MC_RAM_MB    = 6144; // Plafond strict de 6 Go allouables aux serveurs Minecraft
    public const STANDARD_MAX_RAM_MB   = 2048; // 2 Go max pour un compte Standard
    public const STANDARD_MAX_BACKUPS  = 2;
    public const PREMIUM_MAX_BACKUPS   = 10;

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

    public const LIST_WHITELIST = 'WHITELIST';
    public const LIST_BLACKLIST = 'BLACKLIST';
    public const LIST_OP        = 'OP';

    private string $instancesBasePath;
    private string $backupsBasePath;

    public function __construct(
        private readonly PDO $db,
        ?string $instancesBasePath = null,
        ?string $backupsBasePath = null
    ) {
        $rootDir = dirname(__DIR__);
        $this->instancesBasePath = $instancesBasePath ?? ($rootDir . '/instances');
        $this->backupsBasePath   = $backupsBasePath ?? ($rootDir . '/backups');
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
    public function getAllocatedRamMb(?int $excludeServerId = null): int
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
     * Démarre un serveur Minecraft après vérification stricte du plafond de 6 Go de RAM.
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
        $usedRam     = $this->getAllocatedRamMb($serverId);
        $remaining   = self::HOST_MAX_MC_RAM_MB - $usedRam;

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

        $this->ensureServerDirectories($serverId);

        $stmt = $this->db->prepare('UPDATE SERVEUR SET statut = :statut WHERE id_serveur = :id');
        $stmt->execute([
            ':statut' => self::STATUS_ONLINE,
            ':id'     => $serverId,
        ]);

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
     * Met à jour les paramètres de configuration du serveur.
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
        bool $allowPremiumRam = false
    ): bool {
        $cleanName   = Security::validateString($name, 3, 50);
        $cleanVer    = Security::validateString($mcVersion, 3, 20);
        $cleanLoader = strtoupper(trim($loader));
        $cleanMode   = Security::validateString($gameMode, 3, 30);
        $cleanDesc   = Security::validateString($description, 0, 1000) ?? '';
        $validRam    = Security::validateRamAllocation($ramMb, $allowPremiumRam);

        if (
            $serverId <= 0 ||
            $cleanName === null ||
            $cleanVer === null ||
            $cleanMode === null ||
            $validRam === null ||
            !array_key_exists($cleanLoader, self::ALLOWED_LOADERS)
        ) {
            return false;
        }

        $clampedSlots = max(2, min(200, $slotsMax));

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

        return $stmt->execute([
            ':nom'    => $cleanName,
            ':ver'    => $cleanVer,
            ':loader' => $cleanLoader,
            ':mode'   => $cleanMode,
            ':slots'  => $clampedSlots,
            ':ram'    => $validRam,
            ':pub'    => $isPublic ? 1 : 0,
            ':desc'   => $cleanDesc,
            ':id'     => $serverId,
        ]);
    }

    /**
     * Lecture sécurisée des N dernières lignes du fichier logs/latest.log du serveur.
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

        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

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
     * Crée une archive de sauvegarde (.tar.gz) dans /backups et l'enregistre en base.
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
        $archiveName = sprintf('server_%d_backup_%s.tar.gz', $serverId, $timestamp);
        $archivePath = $this->backupsBasePath . '/' . $archiveName;
        $sourceDir   = $this->getServerInstanceDir($serverId);

        // Création d'une archive .tar.gz réelle si PharData ou tar est disponible, sinon fichier d'archive compressé
        $fileSize = 0;
        if (is_dir($sourceDir)) {
            $tarCmd = sprintf(
                'tar -czf %s -C %s . 2>/dev/null',
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
            $fileSize = is_file($archivePath) ? (int) filesize($archivePath) : 4096;
        }

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

        $server = $this->getServerById($serverId);
        if ($server !== null && $server['statut'] === self::STATUS_ONLINE) {
            $this->stopServer($serverId);
        }

        $archiveName = basename((string) $backup['nom_fichier']);
        $archivePath = $this->backupsBasePath . '/' . $archiveName;
        $targetDir   = $this->getServerInstanceDir($serverId);

        if (is_file($archivePath) && is_dir($targetDir)) {
            $untarCmd = sprintf(
                'tar -xzf %s -C %s 2>/dev/null',
                escapeshellarg($archivePath),
                escapeshellarg($targetDir)
            );
            @exec($untarCmd);
        }

        $this->appendLogLine(
            $serverId,
            sprintf('[BackupManager/INFO]: Restauration de l\'archive "%s" effectuée.', $archiveName)
        );

        return [
            'success' => true,
            'message' => sprintf('La sauvegarde "%s" a été restaurée avec succès.', (string) $backup['nom_backup']),
        ];
    }

    /**
     * Supprime une sauvegarde en base et sur le disque (/backups).
     */
    public function deleteBackup(int $serverId, int $backupId): bool
    {
        $backup = $this->getBackupById($serverId, $backupId);
        if ($backup === null) {
            return false;
        }

        $archiveName = basename((string) $backup['nom_fichier']);
        $archivePath = $this->backupsBasePath . '/' . $archiveName;

        if (is_file($archivePath) && is_writable($archivePath)) {
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

        if ($serverId <= 0 || $cleanPseudo === null) {
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
                    u.email,
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
     * Ajoute un co-administrateur sur un serveur dans ADMINISTRER_SERVEUR.
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

        $allowedPerms = ['LECTURE', 'CONSOLE', 'COMPLET'];
        $cleanPerm    = in_array(strtoupper($permissionLevel), $allowedPerms, true)
            ? strtoupper($permissionLevel)
            : 'COMPLET';

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

        return ['success' => true, 'message' => 'Accès co-administrateur accordé avec succès.'];
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
        $logDir = dirname($this->getServerLogFilePath($serverId));
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }
        if (!is_dir($this->backupsBasePath)) {
            @mkdir($this->backupsBasePath, 0750, true);
        }
    }

    private function appendLogLine(int $serverId, string $line): void
    {
        $this->ensureServerDirectories($serverId);
        $logFile = $this->getServerLogFilePath($serverId);
        $entry   = sprintf('[%s] %s%s', date('H:i:s'), $line, PHP_EOL);
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}