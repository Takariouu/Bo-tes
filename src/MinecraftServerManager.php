<?php

declare(strict_types=1);

require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Auth.php';

/**
 * Gestionnaire système des serveurs Minecraft (Fabric) sous Linux Mint.
 * Conçu pour une machine hôte de 8 Go de RAM (6 Go max allouables aux instances).
 */
final class MinecraftServerManager
{
    /**
     * Plafonds de mémoire vive (en Mo).
     */
    public const MAX_GLOBAL_RAM_MO   = 6144; // 6,0 Go max cumulés pour tous les serveurs allumés
    public const MIN_SERVER_RAM_MO   = 512;  // 512 Mo minimum pour démarrer Fabric
    public const MAX_STANDARD_RAM_MO = 2048; // 2,0 Go max pour un compte Standard
    public const MAX_PREMIUM_RAM_MO  = 6144; // Jusqu'à 6,0 Go pour un compte Premium / Admin

    /**
     * Identifiants des états dans la table ETAT.
     */
    public const STATE_STOPPED  = 1; // Éteint
    public const STATE_RUNNING  = 2; // Allumé
    public const STATE_STARTING = 3; // Démarrage en cours
    public const STATE_CRASHED  = 4; // Crash / Erreur

    /**
     * Chemins système par défaut (ajustables via variables d'environnement).
     */
    private static function getServersBasePath(): string
    {
        return rtrim(getenv('BOOTES_SERVERS_PATH') ?: dirname(__DIR__) . '/servers', '/');
    }

    private static function getBackupsBasePath(): string
    {
        return rtrim(getenv('BOOTES_BACKUPS_PATH') ?: dirname(__DIR__) . '/backups', '/');
    }

    private static function getModsRepositoryPath(): string
    {
        return rtrim(getenv('BOOTES_MODS_PATH') ?: dirname(__DIR__) . '/data/mods', '/');
    }

    /**
     * Retourne le chemin absolu du dossier d'un serveur spécifique.
     *
     * @param int $serverId
     * @return string
     */
    public static function getServerDirectory(int $serverId): string
    {
        if ($serverId <= 0) {
            throw new InvalidArgumentException('Identifiant de serveur invalide.');
        }

        return self::getServersBasePath() . '/' . $serverId;
    }

    /**
     * Calcule la somme totale de la RAM (en Mo) actuellement réservée par les serveurs allumés.
     *
     * @param PDO $db
     * @return int
     */
    public static function getCurrentAllocatedRamMo(PDO $db): int
    {
        $stmt = $db->prepare( 'SELECT COALESCE(SUM(SVR_RAM_ALLOUEE_MO), 0) FROM SERVEUR WHERE ETA_ID IN (:running, :starting)' );$stmt->execute([
            ':running'  => self::STATE_RUNNING,
            ':starting' => self::STATE_STARTING,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * 1. Vérifie si la machine hôte dispose d'assez de RAM sur le quota global de 6 Go (6144 Mo).
     *
     * @param int $requestedRamMo RAM demandée par le serveur à démarrer (en Mo).
     * @param PDO $db Instance PDO.
     * @return bool True si le démarrage est autorisé, False si le plafond de 6144 Mo est dépassé.
     */
    public static function checkGlobalRamAvailability(int $requestedRamMo, PDO$db): bool
    {
        if ($requestedRamMo < self::MIN_SERVER_RAM_MO) {
            return false;
        }

        $currentlyUsedRam = self::getCurrentAllocatedRamMo($db);

        return ($currentlyUsedRam +$requestedRamMo) <= self::MAX_GLOBAL_RAM_MO;
    }

    /**
     * 2. Démarre un serveur Minecraft Fabric en tâche de fond sous Linux.
     *
     * @param int $serverId Identifiant du serveur (SVR_ID).
     * @param PDO $db Instance PDO.
     * @return array{success: bool, message: string, pid?: int}
     */
    public static function startServer(int $serverId, PDO$db): array
    {
        // Récupération des informations du serveur et du type de compte du propriétaire
        $stmt =$db->prepare(
            'SELECT s.SVR_ID, s.ETA_ID, s.SVR_RAM_ALLOUEE_MO, s.SVR_PORT, u.TDC_ID
             FROM SERVEUR s
             INNER JOIN UTILISATEUR u ON s.UTI_ID = u.UTI_ID
             WHERE s.SVR_ID = :id
             LIMIT 1'
        );
        $stmt->execute([':id' =>$serverId]);
        $server =$stmt->fetch();

        if (!$server) {
            return [
                'success' => false,
                'message' => 'Serveur introuvable dans la base de données.',
            ];
        }

        if ((int) $server['ETA_ID'] === self::STATE_RUNNING && self::isProcessRunning($serverId)) {
            return [
                'success' => false,
                'message' => 'Ce serveur est déjà en cours d\'exécution.',
            ];
        }

        $allocatedRam = (int)$server['SVR_RAM_ALLOUEE_MO'];
        $accountType  = (int)$server['TDC_ID'];

        // Vérification de la limite par type de compte (2 Go max pour Standard)
        $maxAllowedForUser = ($accountType === Auth::ROLE_STANDARD)
            ? self::MAX_STANDARD_RAM_MO
            : self::MAX_PREMIUM_RAM_MO;

        if ($allocatedRam >$maxAllowedForUser) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Quota dépassé : votre offre limite la RAM à %d Mo par serveur (%d Mo configurés).',
                    $maxAllowedForUser,$allocatedRam
                ),
            ];
        }

        // Vérification du plafond physique de la machine hôte (6144 Mo max cumulés)
        if (!self::checkGlobalRamAvailability($allocatedRam,$db)) {
            $availableRam = max(0, self::MAX_GLOBAL_RAM_MO - self::getCurrentAllocatedRamMo($db));
            return [
                'success' => false,
                'message' => sprintf(
                    'Ressources hôte insuffisantes : %d Mo disponibles sur les %d Mo dédiés aux serveurs.',
                    $availableRam,
                    self::MAX_GLOBAL_RAM_MO
                ),
            ];
        }

        $serverDir = self::getServerDirectory($serverId);
        $jarPath   =$serverDir . '/fabric-server-launch.jar';

        if (!is_dir($serverDir) \vert{}\vert{} !is_file($jarPath)) {
            return [
                'success' => false,
                'message' => 'Les fichiers système Fabric (fabric-server-launch.jar) sont introuvables pour ce serveur.',
            ];
        }

        // Validation automatique de l'EULA Minecraft si absente
        $eulaFile =$serverDir . '/eula.txt';
        if (!is_file($eulaFile)) {
            file_put_contents($eulaFile, "eula=true\n");
        }

        // Construction sécurisée de la commande de lancement en arrière-plan
        $escapedDir = escapeshellarg($serverDir);
        $xmxArg     = '-Xmx' .$allocatedRam . 'M';

        $command = sprintf(
            'cd %s && nohup java -Xms512M %s -jar fabric-server-launch.jar nogui > /dev/null 2>&1 & echo $!',$escapedDir,
            escapeshellarg($xmxArg)
        );

        $output = [];$returnCode = 0;
        exec($command, $output,$returnCode);

        $pid = isset($output[0]) ? (int) trim($output[0]) : 0;

        if ($returnCode !== 0 \vert{}\vert{}$pid <= 0) {
            self::updateServerState($serverId, self::STATE_CRASHED,$db);
            return [
                'success' => false,
                'message' => 'Échec du lancement du processus Java sous Linux.',
            ];
        }

        // Enregistrement du PID dans un fichier système dédié à l'instance
        file_put_contents($serverDir . '/server.pid', (string)$pid, LOCK_EX);

        // Mise à jour de l'état à "Allumé" en base de données
        self::updateServerState($serverId, self::STATE_RUNNING,$db);

        return [
            'success' => true,
            'message' => 'Le serveur Fabric a démarré avec succès.',
            'pid'     => $pid,
        ];
    }

    /**
     * 3. Arrête proprement un serveur Minecraft via son PID (SIGTERM) et met à jour son état.
     *
     * @param int $serverId Identifiant du serveur (SVR_ID).
     * @param PDO $db Instance PDO.
     * @return bool True si le serveur est arrêté et mis à jour en BDD.
     */
    public static function stopServer(int $serverId, PDO$db): bool
    {
        $serverDir = self::getServerDirectory($serverId);
        $pidFile   =$serverDir . '/server.pid';

        if (is_file($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));

            if ($pid > 0 && self::isPidAlive($pid)) {
                // Envoi du signal SIGTERM (-15) pour laisser Fabric sauvegarder le monde proprement
                exec(sprintf('kill -15 %d 2>/dev/null', $pid));

                // Attente maximale de 5 secondes pour l'extinction propre
                $ waited = 0;
                while (self::isPidAlive($pid) &&$waited < 50) {
                    usleep(100000); // 100 ms
                    $waited++;
                }

                // Si le processus est bloqué après le délai, arrêt forcé (SIGKILL -9)
                if (self::isPidAlive($pid)) {
                    exec(sprintf('kill -9 %d 2>/dev/null', $pid));
                }
            }

            @unlink($pidFile);
        }

        self::updateServerState($serverId, self::STATE_STOPPED,$db);

        return true;
    }

    /**
     * 4. Lit les dernières lignes du fichier logs/latest.log sans saturer la mémoire PHP.
     *
     * @param int $serverId Identifiant du serveur.
     * @param int $lines Nombre de lignes à récupérer (défaut : 100).
     * @return array<int, string> Liste des lignes de log.
     */
    public static function getLatestLogs(int $serverId, int$lines = 100): array
    {
        $lines = max(1, min($lines, 500));
        $logFile = self::getServerDirectory($serverId) . '/logs/latest.log';

        if (!is_file($logFile) \vert{}\vert{} !is_readable($logFile)) {
            return ['[INFO] Aucun fichier de log disponible pour le moment.'];
        }

        $handle = @fopen($logFile, 'rb');
        if ($handle === false) {
            return ['[ERROR] Impossible d\'ouvrir le fichier logs/latest.log.'];
        }

        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);

        if ($fileSize === false \vert{}\vert{}$fileSize === 0) {
            fclose($handle);
            return ['[INFO] Le fichier de log est vide.'];
        }

        $bufferSize = 4096;
        $position   =$fileSize;
        $chunkData  = '';$lineCount  = 0;

        while ($position > 0 &&$lineCount <= $lines) {$readSize = min($position,$bufferSize);
            $position -=$readSize;

            fseek($handle, $position, SEEK_SET);$chunk = fread($handle,$readSize);
            if ($chunk === false) {
                break;
            }

            $chunkData = $chunk .$chunkData;
            $lineCount = substr_count($chunkData, "\n");
        }

        fclose($handle);

        $allLines = explode("\n", rtrim($chunkData, "\r\n"));

        return array_slice($allLines, -$lines);
    }

    /**
     * 5. Compresse le dossier "world" en archive .tar.gz et enregistre la sauvegarde dans la table BACKUPS.
     *
     * @param int $serverId Identifiant du serveur (SVR_ID).
     * @param PDO $db Instance PDO.
     * @return bool True en cas de succès.
     */
    public static function createBackup(int $serverId, PDO$db): bool
    {
        $serverDir = self::getServerDirectory($serverId);
        $worldDir  =$serverDir . '/world';

        if (!is_dir($worldDir)) {
            return false;
        }

        $backupBaseDir = self::getBackupsBasePath();
        if (!is_dir($backupBaseDir) && !mkdir($backupBaseDir, 0750, true) && !is_dir($backupBaseDir)) {
            return false;
        }

        $timestamp   = date('Ymd_His');
        $archiveName = sprintf('srv\%d_\%s.tar.gz',$serverId, $timestamp);$archivePath = $backupBaseDir . '/' .$archiveName;
        $displayName = sprintf('Backup #\%d (\%s)', $serverId, date('d/m/Y H:i'));

        // Compression optimisée sans perte du dossier world
        $command = sprintf(
            'tar -czf %s -C %s world 2>/dev/null',
            escapeshellarg($archivePath),
            escapeshellarg($serverDir)
        );

        $output = [];$returnCode = 0;
        exec($command, $output,$returnCode);

        if ($returnCode !== 0 \vert{}\vert{} !is_file($archivePath)) {
            return false;
        }

        clearstatcache(true, $archivePath);
        $fileSizeOctets = (int) filesize($archivePath);

        // Insertion dans la table BACKUPS (gère le cas avec ou sans AUTO_INCREMENT sur BUP_ID)
        $nextIdStmt =$db->query('SELECT COALESCE(MAX(BUP_ID), 0) + 1 FROM BACKUPS');
        $nextBupId  = (int)$nextIdStmt->fetchColumn();

        $stmt =$db->prepare(
            'INSERT INTO BACKUPS (
                BUP_ID, SVR_ID, BUP_NOM, BUP_DATE_CREATION, BUP_TAILLE_FICHIER, BUP_CHEMIN_FICHIER
            ) VALUES (
                :bup_id, :svr_id, :nom, NOW(), :taille, :chemin
            )'
        );

        return $stmt->execute([
            ':bup_id' => $nextBupId,
            ':svr_id' => $serverId,
            ':nom'    => substr($displayName, 0, 32), ':taille' =>$fileSizeOctets,
            ':chemin' => $archiveName,
        ]);
    }

    /**
     * 6. Installe un mod sur le serveur via un lien symbolique (symlink) pour économiser l'espace disque.
     *
     * @param int $serverId Identifiant du serveur (SVR_ID).
     * @param int $modId Identifiant du mod (MOD_ID).
     * @param PDO $db Instance PDO.
     * @return bool True si le lien symbolique a été créé avec succès.
     */
    public static function installMod(int $serverId, int $modId, PDO$db): bool
    {
        // Récupérer la version Minecraft (VER_ID) et le ModPack (MOPA_ID) du serveur
        $srvStmt =$db->prepare('SELECT VER_ID, MOPA_ID FROM SERVEUR WHERE SVR_ID = :svr_id LIMIT 1');
        $srvStmt->execute([':svr_id' =>$serverId]);
        $server =$srvStmt->fetch();

        if (!$server) {
            return false;
        }

        // Récupérer le fichier du mod correspondant à la version du serveur (ou le plus récent à défaut)
        $modStmt =$db->prepare(
            'SELECT m.MOD_CHEMIN_DOSSIER, fm.FMOD_ID, fm.FMOD_NOM_FICHIER
             FROM `MOD` m
             INNER JOIN FICHIER_MOD fm ON m.MOD_ID = fm.MOD_ID
             WHERE m.MOD_ID = :mod_id
             ORDER BY (fm.VER_ID = :ver_id) DESC, fm.FMOD_ID DESC
             LIMIT 1'
        );
        $modStmt->execute([
            ':mod_id' => $modId,
            ':ver_id' => (int) $server['VER_ID'],
        ]);
        $modFile =$modStmt->fetch();

        if (!$modFile) {
            return false;
        }

        // Nettoyage strict des noms pour empêcher toute traversée de répertoire (Path Traversal)
        $modFolder   = basename(trim((string)$modFile['MOD_CHEMIN_DOSSIER'], '/\\'));
        $jarFileName = basename(trim((string)$modFile['FMOD_NOM_FICHIER']));

        if (! str_ends_with(strtolower($jarFileName), '.jar')) {$jarFileName .= '.jar';
        }

        $sourcePath = self::getModsRepositoryPath() . '/' . $modFolder . '/' .$jarFileName;

        // Fallback si le fichier .jar est placé directement à la racine de /data/mods/
        if (!is_file($sourcePath)) {
            $flatSourcePath = self::getModsRepositoryPath() . '/' .$jarFileName;
            if (is_file($flatSourcePath)) {
                $sourcePath =$flatSourcePath;
            } else {
                return false;
            }
        }

        $serverModsDir = self::getServerDirectory($serverId) . '/mods';
        if (!is_dir($serverModsDir) && !mkdir($serverModsDir, 0750, true) && !is_dir($serverModsDir)) {
            return false;
        }

        $targetLink = $serverModsDir . '/' .$jarFileName;

        // Si le lien symbolique ou le fichier existe déjà, on le remplace proprement
        if (is_link($targetLink) \vert{}\vert{} is_file($targetLink)) {
            @unlink($targetLink);
        }

        if (!symlink($sourcePath,$targetLink)) {
            return false;
        }

        // Incrémentation du compteur de téléchargements et liaison au ModPack du serveur
        $updateDl =$db->prepare(
            'UPDATE FICHIER_MOD SET FMOD_NB_DOWNLOAD = FMOD_NB_DOWNLOAD + 1 WHERE FMOD_ID = :fmod_id'
        );
        $updateDl->execute([':fmod_id' => (int)$modFile['FMOD_ID']]);

        if (!empty($server['MOPA_ID'])) {$linkModPack = $db->prepare( 'INSERT IGNORE INTO CONTENIR_MOD (MOD_ID, MOPA_ID) VALUES (:mod_id, :mopa_id)' );$linkModPack->execute([
                ':mod_id'  => $modId,
                ':mopa_id' => (int) $server['MOPA_ID'],
            ]);
        }

        return true;
    }

    /**
     * Vérifie si le processus Linux associé à un serveur est réellement actif.
     *
     * @param int $serverId
     * @return bool
     */
    public static function isProcessRunning(int $serverId): bool
    {
        $pidFile = self::getServerDirectory($serverId) . '/server.pid';
        if (!is_file($pidFile)) {
            return false;
        }

        $pid = (int) trim((string) file_get_contents($pidFile));

        return $pid > 0 && self::isPidAlive($pid);
    }

    /**
     * Vérifie l'existence d'un PID Java actif via le système de fichiers /proc de Linux.
     *
     * @param int $pid
     * @return bool
     */
    private static function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $procCmdline = '/proc/' .$pid . '/cmdline';
        if (!is_readable($procCmdline)) {
            return false;
        }

        $cmdline = (string) file_get_contents($procCmdline);

        return str_contains($cmdline, 'java') \vert{}\vert{} str_contains($cmdline, 'fabric');
    }

    /**
     * Met à jour l'état du serveur dans la table SERVEUR.
     *
     * @param int $serverId
     * @param int $stateId
     * @param PDO $db
     */
    private static function updateServerState(int $serverId, int $stateId, PDO$db): void
    {
        $stmt = $db->prepare('UPDATE SERVEUR SET ETA_ID = :eta_id WHERE SVR_ID = :svr_id');$stmt->execute([
            ':eta_id' => $stateId,
            ':svr_id' => $serverId,
        ]);
    }
}