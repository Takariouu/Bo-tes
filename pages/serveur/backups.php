<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/ServerManager.php';

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Http;
use Bootes\Src\Security;
use Bootes\Src\ServerManager;

$db            = $db ?? Database::getInstance();
$serverManager = new ServerManager($db);

$serverId      = isset($activeServerId) && is_int($activeServerId)
    ? $activeServerId
    : Security::validatePositiveInt($_GET['id'] ?? $_POST['server_id'] ?? null);
$currentUserId = Auth::getUserId();
$accessLevel   = $serverId !== null && $currentUserId !== null
    ? Auth::getServerAccessLevel($currentUserId, $serverId, $db)
    : null;
$canConfigure  = Auth::serverLevelAllows($accessLevel, Auth::SERVER_PERM_FULL);

if ($serverId === null || $currentUserId === null || $accessLevel === null) {
    http_response_code(403);
    ?>
    <section class="server-backups server-backups--forbidden">
        <h1 class="server-backups__title">Accès refusé aux sauvegardes</h1>
    </section>
    <?php
    return;
}

$server = $serverManager->getServerById($serverId);
if ($server === null) {
    ?>
    <section class="server-backups server-backups--not-found">
        <h1 class="server-backups__title">Serveur introuvable</h1>
    </section>
    <?php
    return;
}

$csrfToken       = Security::generateCsrfToken();
$feedbackMessage = null;
$feedbackError   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 30) ?? '';

    if (!Security::verifyCsrfToken($postCsrf)) {
        $feedbackError = 'Jeton CSRF invalide.';
    } elseif (!$canConfigure) {
        // Les archives contiennent le monde et la configuration complète : niveau COMPLET exigé.
        $feedbackError = Auth::serverPermissionError($accessLevel);
    } else {
        switch ($action) {
            case 'create_backup':
                $label = Security::validateString($_POST['nom_backup'] ?? '', 0, 100) ?? '';
                $res   = $serverManager->createBackup($serverId, $label);
                if ($res['success']) {
                    $feedbackMessage = $res['message'];
                } else {
                    $feedbackError = $res['message'];
                }
                break;

            case 'restore_backup':
                $backupId = Security::validatePositiveInt($_POST['backup_id'] ?? null);
                if ($backupId !== null) {
                    $res = $serverManager->restoreBackup($serverId, $backupId);
                    if ($res['success']) {
                        $feedbackMessage = $res['message'];
                    } else {
                        $feedbackError = $res['message'];
                    }
                }
                break;

            case 'delete_backup':
                $backupId = Security::validatePositiveInt($_POST['backup_id'] ?? null);
                if ($backupId !== null && $serverManager->deleteBackup($serverId, $backupId)) {
                    $feedbackMessage = 'L\'archive de sauvegarde a été supprimée.';
                } else {
                    $feedbackError = 'Impossible de supprimer cette sauvegarde.';
                }
                break;

            case 'download_backup':
                $backupId = Security::validatePositiveInt($_POST['backup_id'] ?? null);
                $backup   = $backupId !== null ? $serverManager->getBackupById($serverId, $backupId) : null;
                $archive  = $backup !== null ? $serverManager->getBackupArchivePath($backup) : null;
                if ($archive !== null && is_readable($archive)) {
                    Http::sendFile($archive, basename($archive), 'application/gzip');
                }
                $feedbackError = 'Archive introuvable sur le stockage.';
                break;
        }
    }
}

$backups        = $serverManager->getBackups($serverId);
$isPremiumOwner = strtoupper((string) $server['proprietaire_offre']) === Auth::ACCOUNT_PREMIUM;
$maxQuota       = Auth::isAdmin()
    ? 50
    : ($isPremiumOwner ? ServerManager::PREMIUM_MAX_BACKUPS : ServerManager::STANDARD_MAX_BACKUPS);
?>
<div class="server-backups">
    <header class="server-backups__header">
        <div class="server-backups__heading">
            <h1 class="server-backups__title">
                Gestionnaire de Sauvegardes (Backups) — <?= Security::e((string) $server['nom_serveur']) ?>
            </h1>
            <p class="server-backups__subtitle">
                Archives compressées <code>.tar.gz</code> conservées dans le stockage privé de l'hôte (inaccessible depuis le web).
                Quota utilisé : <strong><?= count($backups) ?> / <?= $maxQuota ?></strong>.
            </p>
        </div>
    </header>

    <?php if ($feedbackMessage !== null): ?>
        <div class="server-backups__alert server-backups__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="server-backups__alert server-backups__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!$canConfigure): ?>
        <p class="server-backups__notice" role="note">
            Votre accès (<?= Security::e((string) $accessLevel) ?>) permet de consulter les sauvegardes, pas de les créer, restaurer, télécharger ou supprimer.
        </p>
    <?php else: ?>
    <section class="server-backups__create-section" aria-labelledby="create-backup-title">
        <h2 id="create-backup-title" class="server-backups__section-title">Créer une nouvelle sauvegarde</h2>
        <form action="/serveur/backups?id=<?= $serverId ?>" method="post" class="backup-create-form">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="server_id" value="<?= $serverId ?>">
            <input type="hidden" name="action" value="create_backup">

            <div class="backup-create-form__field">
                <label for="backup-label" class="backup-create-form__label">Libellé de la sauvegarde (ex: Avant combat Ender Dragon)</label>
                <input
                    type="text"
                    id="backup-label"
                    name="nom_backup"
                    maxlength="100"
                    placeholder="Sauvegarde manuelle"
                    class="backup-create-form__input"
                >
            </div>

            <button type="submit" class="backup-create-form__submit" <?= count($backups) >= $maxQuota ? 'disabled' : '' ?>>
                Générer l'archive .tar.gz
            </button>
        </form>
    </section>
    <?php endif; ?>

    <section class="server-backups__list-section" aria-labelledby="backups-list-title">
        <h2 id="backups-list-title" class="server-backups__section-title">
            Archives disponibles (<?= count($backups) ?>)
        </h2>

        <?php if (empty($backups)): ?>
            <p class="server-backups__empty">Aucune sauvegarde n'a encore été créée pour ce serveur.</p>
        <?php else: ?>
            <table class="backups-table">
                <caption class="backups-table__caption">Historique des archives .tar.gz du serveur</caption>
                <thead class="backups-table__head">
                    <tr class="backups-table__row">
                        <th scope="col" class="backups-table__cell">Libellé</th>
                        <th scope="col" class="backups-table__cell">Fichier Archive</th>
                        <th scope="col" class="backups-table__cell">Taille</th>
                        <th scope="col" class="backups-table__cell">Date de création</th>
                        <th scope="col" class="backups-table__cell">Actions</th>
                    </tr>
                </thead>
                <tbody class="backups-table__body">
                    <?php foreach ($backups as $bkp): ?>
                        <?php $bkpId = (int) $bkp['id_backup']; ?>
                        <tr class="backups-table__row">
                            <th scope="row" class="backups-table__cell"><?= Security::e((string) $bkp['nom_backup']) ?></th>
                            <td class="backups-table__cell"><code><?= Security::e((string) $bkp['nom_fichier']) ?></code></td>
                            <td class="backups-table__cell"><?= round(((int) $bkp['taille_octets']) / 1024, 1) ?> Ko</td>
                            <td class="backups-table__cell"><?= Security::e((string) $bkp['date_creation']) ?></td>
                            <td class="backups-table__cell backups-table__cell--actions">
                                <?php if ($canConfigure): ?>
                                <form action="/serveur/backups?id=<?= $serverId ?>" method="post" class="backups-table__form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="server_id" value="<?= $serverId ?>">
                                    <input type="hidden" name="backup_id" value="<?= $bkpId ?>">
                                    <input type="hidden" name="action" value="restore_backup">
                                    <button type="submit" class="backups-table__btn backups-table__btn--restore" data-confirm="Restaurer cette sauvegarde ? Le serveur sera arrêté et ses fichiers actuels écrasés.">Restaurer</button>
                                </form>

                                <form action="/serveur/backups?id=<?= $serverId ?>" method="post" class="backups-table__form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="server_id" value="<?= $serverId ?>">
                                    <input type="hidden" name="backup_id" value="<?= $bkpId ?>">
                                    <input type="hidden" name="action" value="download_backup">
                                    <button type="submit" class="backups-table__btn backups-table__btn--download">Télécharger</button>
                                </form>

                                <form action="/serveur/backups?id=<?= $serverId ?>" method="post" class="backups-table__form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="server_id" value="<?= $serverId ?>">
                                    <input type="hidden" name="backup_id" value="<?= $bkpId ?>">
                                    <input type="hidden" name="action" value="delete_backup">
                                    <button type="submit" class="backups-table__btn backups-table__btn--delete" data-confirm="Supprimer définitivement cette sauvegarde ?">Supprimer</button>
                                </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>