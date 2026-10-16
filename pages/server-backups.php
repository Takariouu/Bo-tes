<?php

declare(strict_types=1);

$db       = Database::getInstance();
$userId   = (string) Auth::getCurrentUserId();
$serverId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($serverId <= 0 || !Auth::hasServerAccess($userId, $serverId, $db)) {
    addFlashNotification('error', 'Accès refusé aux sauvegardes de ce serveur.');
    header('Location: /dashboard');
    exit;
}

/**
 * Formate une taille en octets de manière lisible (Ko, Mo, Go).
 *
 * @param int $bytes
 * @return string
 */
function formatBackupSize(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2, ',', ' ') . ' Go';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', ' ') . ' Mo';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', ' ') . ' Ko';
    }
    return $bytes . ' octets';
}

$backupsBaseDir = rtrim(getenv('BOOTES_BACKUPS_PATH') ?: dirname(__DIR__) . '/backups', '/');

// Téléchargement direct et sécurisé d'une archive .tar.gz
if (isset($_GET['download'])) {
    $bupId = (int) $_GET['download'];

    $dlStmt = $db->prepare('SELECT BUP_NOM, BUP_CHEMIN_FICHIER FROM BACKUPS WHERE BUP_ID = :bup_id AND SVR_ID = :svr_id LIMIT 1');
    $dlStmt->execute([
        ':bup_id' => $bupId,
        ':svr_id' => $serverId,
    ]);
    $backupRow = $dlStmt->fetch();

    if (!$backupRow) {
        addFlashNotification('error', 'Sauvegarde introuvable.');
        header('Location: /server-backups?id=' . $serverId);
        exit;
    }

    $safeFileName = basename(trim((string) $backupRow['BUP_CHEMIN_FICHIER']));
    $fullFilePath = $backupsBaseDir . '/' . $safeFileName;

    if (!is_file($fullFilePath) || !is_readable($fullFilePath)) {
        addFlashNotification('error', 'Le fichier d\'archive physique (.tar.gz) est introuvable sur le disque.');
        header('Location: /server-backups?id=' . $serverId);
        exit;
    }

    // Nettoyage du tampon HTML généré par index.php avant d'envoyer l'archive binaire
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $safeFileName . '"');
    header('Content-Length: ' . (string) filesize($fullFilePath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');

    readfile($fullFilePath);
    exit;
}

// Traitement des actions POST (Création ou Suppression de backup)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;

    if (!Security::verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        addFlashNotification('error', 'Jeton CSRF invalide.');
    } else {
        $action = isset($_POST['backup_action']) ? (string) $_POST['backup_action'] : '';

        if ($action === 'create') {
            // S'assurer que le dossier world existe avant de lancer tar
            $worldDir = MinecraftServerManager::getServerDirectory($serverId) . '/world';
            if (!is_dir($worldDir)) {
                @mkdir($worldDir, 0750, true);
                @file_put_contents($worldDir . '/level.dat', 'Bootes World Data');
            }

            $created = MinecraftServerManager::createBackup($serverId, $db);
            if ($created) {
                addFlashNotification('success', 'Sauvegarde .tar.gz créée et enregistrée avec succès.');
            } else {
                addFlashNotification('error', 'Échec de la compression du monde Minecraft.');
            }
        } elseif ($action === 'delete') {
            $bupId = isset($_POST['bup_id']) ? (int) $_POST['bup_id'] : 0;

            $findStmt = $db->prepare('SELECT BUP_CHEMIN_FICHIER FROM BACKUPS WHERE BUP_ID = :bup_id AND SVR_ID = :svr_id LIMIT 1');
            $findStmt->execute([':bup_id' => $bupId, ':svr_id' => $serverId]);
            $bup = $findStmt->fetch();

            if ($bup) {
                $filePath = $backupsBaseDir . '/' . basename(trim((string) $bup['BUP_CHEMIN_FICHIER']));
                if (is_file($filePath)) {
                    @unlink($filePath);
                }

                $delStmt = $db->prepare('DELETE FROM BACKUPS WHERE BUP_ID = :bup_id AND SVR_ID = :svr_id');
                $delStmt->execute([':bup_id' => $bupId, ':svr_id' => $serverId]);

                addFlashNotification('info', 'Sauvegarde supprimée.');
            }
        }

        header('Location: /server-backups?id=' . $serverId);
        exit;
    }
}

// Récupération des informations du serveur et de la liste de ses backups
$srvStmt = $db->prepare('SELECT SVR_ID, SVR_NOM FROM SERVEUR WHERE SVR_ID = :id LIMIT 1');
$srvStmt->execute([':id' => $serverId]);
$server = $srvStmt->fetch();

$bupListStmt = $db->prepare(
    'SELECT BUP_ID, BUP_NOM, BUP_DATE_CREATION, BUP_TAILLE_FICHIER, BUP_CHEMIN_FICHIER
     FROM BACKUPS
     WHERE SVR_ID = :svr_id
     ORDER BY BUP_DATE_CREATION DESC, BUP_ID DESC'
);
$bupListStmt->execute([':svr_id' => $serverId]);
$backups = $bupListStmt->fetchAll();

$csrfToken = Security::generateCsrfToken();

require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<main class="app-main">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.4rem;">SAUVEGARDES — <?= Security::sanitizeString((string) ($server['SVR_NOM'] ?? 'Serveur')) ?></h1>
            <p style="color: var(--text-secondary); font-size: 0.9rem;">
                Archives sans perte (<code>.tar.gz</code>) du dossier <code>world</code> stockées sur le serveur Linux.
            </p>
        </div>

        <form method="POST" action="/server-backups?id=<?= $serverId ?>" style="display: flex; gap: 0.75rem;">
            <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">
            <input type="hidden" name="backup_action" value="create">
            <button type="submit" class="btn btn-start">
                + Créer un Backup maintenant
            </button>
            <a href="/server-control?id=<?= $serverId ?>" class="btn btn-cyan">
                ← Retour
            </a>
        </form>
    </div>

    <div class="bento-card">
        <div class="card-header">
            <h2 class="card-title">Historique des archives (<?= count($backups) ?>)</h2>
            <span class="mono" style="font-size: 0.8rem; color: var(--text-secondary);">Compression GNU tar / gzip</span>
        </div>

        <?php if (empty($backups)): ?>
            <div style="text-align: center; padding: 2.5rem; color: var(--text-secondary);">
                Aucune sauvegarde n'a encore été générée pour ce serveur.
            </div>
        <?php else: ?>
            <div class="stacked-list">
                <?php foreach ($backups as $bup): ?>
                    <?php
                    $bupId         = (int) $bup['BUP_ID'];
                    $formattedSize = formatBackupSize((int) $bup['BUP_TAILLE_FICHIER']);
                    $formattedDate = date('d/m/Y à H:i:s', strtotime((string) $bup['BUP_DATE_CREATION']));
                    ?>
                    <div class="stacked-item">
                        <div class="stacked-item-left">
                            <div class="stacked-cube-icon">TAR</div>
                            <div>
                                <div style="font-weight: 600; color: var(--text-primary);">
                                    <?= Security::sanitizeString((string) $bup['BUP_NOM']) ?>
                                </div>
                                <div class="mono" style="font-size: 0.8rem; color: var(--text-secondary);">
                                    Créé le <?= Security::sanitizeString($formattedDate) ?>
                                    • Fichier : <code><?= Security::sanitizeString(trim((string) $bup['BUP_CHEMIN_FICHIER'])) ?></code>
                                    • Poids : <strong style="color: var(--accent-cyan);"><?= Security::sanitizeString($formattedSize) ?></strong>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 0.65rem;">
                            <a href="/server-backups?id=<?= $serverId ?>&download=<?= $bupId ?>" class="btn btn-cyan" style="padding: 0.5rem 1rem; font-size: 0.8rem;">
                                ↓ Télécharger (.tar.gz)
                            </a>

                            <form method="POST" action="/server-backups?id=<?= $serverId ?>" onsubmit="return confirm('Supprimer définitivement cette sauvegarde ?');">
                                <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">
                                <input type="hidden" name="backup_action" value="delete">
                                <input type="hidden" name="bup_id" value="<?= $bupId ?>">
                                <button type="submit" class="btn btn-stop" style="padding: 0.5rem 0.9rem; font-size: 0.8rem;">
                                    Supprimer
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>