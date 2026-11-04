<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/ServerManager.php';
require_once __DIR__ . '/../../src/ResourceManager.php';

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\ResourceManager;
use Bootes\Src\Security;
use Bootes\Src\ServerManager;
use Bootes\Src\View;

$db            = $db ?? Database::getInstance();
$serverManager = new ServerManager($db);

$serverId      = isset($activeServerId) && is_int($activeServerId)
    ? $activeServerId
    : Security::validatePositiveInt($_GET['id'] ?? $_POST['server_id'] ?? null);
$currentUserId = Auth::getUserId();
$accessLevel   = $serverId !== null && $currentUserId !== null
    ? Auth::getServerAccessLevel($currentUserId, $serverId, $db)
    : null;
$canOperate    = Auth::serverLevelAllows($accessLevel, Auth::SERVER_PERM_CONSOLE);
$canConfigure  = Auth::serverLevelAllows($accessLevel, Auth::SERVER_PERM_FULL);

if ($serverId === null || $currentUserId === null || $accessLevel === null) {
    http_response_code(403);
    ?>
    <section class="server-dashboard server-dashboard--forbidden">
        <h1 class="server-dashboard__title">Accès refusé</h1>
        <p class="server-dashboard__message">Vous n'avez pas l'autorisation d'administrer ce serveur Minecraft.</p>
        <a href="/user/compte" class="server-dashboard__btn">Retour à mon compte</a>
    </section>
    <?php
    return;
}

$csrfToken       = Security::generateCsrfToken();
$feedbackMessage = null;
$feedbackError   = null;

$server = $serverManager->getServerById($serverId);
if ($server === null) {
    ?>
    <section class="server-dashboard server-dashboard--not-found">
        <h1 class="server-dashboard__title">Serveur introuvable</h1>
        <a href="/user/compte" class="server-dashboard__btn">Retour à mes serveurs</a>
    </section>
    <?php
    return;
}

$ramCeiling = Auth::isAdmin()
    ? ServerManager::HOST_MAX_MC_RAM_MB
    : ServerManager::ramCeilingFor((string) $server['proprietaire_offre'], (int) $server['proprietaire_max_ram']);

// Niveau minimal requis par action : CONSOLE pour le cycle de vie, COMPLET pour la configuration.
$actionPermissions = [
    'start'                  => Auth::SERVER_PERM_CONSOLE,
    'stop'                   => Auth::SERVER_PERM_CONSOLE,
    'restart'                => Auth::SERVER_PERM_CONSOLE,
    'force_kill'             => Auth::SERVER_PERM_OWNER,
    'update_settings'        => Auth::SERVER_PERM_FULL,
    'quick_install_resource' => Auth::SERVER_PERM_FULL,
    'uninstall_resource'     => Auth::SERVER_PERM_FULL,
];

// Traitement des actions POST (Cycle de vie, Paramètres, Installation rapide de ressources)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 35) ?? '';

    if (!Security::verifyCsrfToken($postCsrf)) {
        $feedbackError = 'Jeton CSRF invalide ou expiré. Veuillez réessayer.';
    } elseif (isset($actionPermissions[$action]) && !Auth::serverLevelAllows($accessLevel, $actionPermissions[$action])) {
        $feedbackError = Auth::serverPermissionError($accessLevel);
    } else {
        switch ($action) {
            case 'start':
                $res = $serverManager->startServer($serverId);
                if ($res['success']) {
                    $feedbackMessage = $res['message'];
                } else {
                    $feedbackError = $res['message'];
                }
                break;

            case 'stop':
                $res = $serverManager->stopServer($serverId);
                if ($res['success']) {
                    $feedbackMessage = $res['message'];
                } else {
                    $feedbackError = $res['message'];
                }
                break;

            case 'restart':
                $res = $serverManager->restartServer($serverId);
                if ($res['success']) {
                    $feedbackMessage = $res['message'];
                } else {
                    $feedbackError = $res['message'];
                }
                break;

            case 'force_kill':
                $res = $serverManager->forceKillServer($serverId, $currentUserId);
                if ($res['success']) {
                    $feedbackMessage = $res['message'];
                } else {
                    $feedbackError = $res['message'];
                }
                break;

            case 'update_settings':
                $nom        = Security::validateString($_POST['nom_serveur'] ?? '', 3, 50) ?? '';
                $versionMc  = Security::validateString($_POST['version_mc'] ?? '1.21.4', 3, 20) ?? '1.21.4';
                $loader     = Security::validateString($_POST['type_loader'] ?? 'VANILLA', 3, 20) ?? 'VANILLA';
                $modeJeu    = Security::validateString($_POST['mode_jeu'] ?? 'SURVIE', 3, 30) ?? 'SURVIE';
                $slotsMax   = Security::validatePositiveInt($_POST['slots_max'] ?? 20) ?? 20;
                $ramAllouee = Security::validatePositiveInt($_POST['ram_allouee'] ?? 2048) ?? 2048;
                $estPublic  = isset($_POST['est_public']) && $_POST['est_public'] === '1';
                $desc       = Security::validateString($_POST['description'] ?? '', 0, 1000) ?? '';

                $res = $serverManager->updateServerSettings(
                    $serverId,
                    $nom,
                    $versionMc,
                    $loader,
                    $modeJeu,
                    $slotsMax,
                    $ramAllouee,
                    $estPublic,
                    $desc,
                    Auth::isAdmin()
                );
                if ($res['success']) {
                    $feedbackMessage = $res['message'];
                } else {
                    $feedbackError = $res['message'];
                }
                break;

            case 'quick_install_resource':
                $resId = Security::validatePositiveInt($_POST['resource_id'] ?? null);
                if ($resId !== null) {
                    try {
                        $res = (new ResourceManager($db))->installOnServer($serverId, $resId);
                        if ($res['success']) {
                            $feedbackMessage = $res['message'];
                        } else {
                            $feedbackError = $res['message'];
                        }
                    } catch (PDOException $e) {
                        error_log(sprintf('[Boötes][Dashboard] Erreur installation ressource : %s', $e->getMessage()));
                        $feedbackError = 'Impossible d\'installer cette ressource.';
                    }
                }
                break;

            case 'uninstall_resource':
                $resId = Security::validatePositiveInt($_POST['resource_id'] ?? null);
                if ($resId !== null) {
                    try {
                        $delStmt = $db->prepare('DELETE FROM INSTALLER_RESSOURCE WHERE id_serveur = :sid AND id_ressource = :rid');
                        $delStmt->execute([':sid' => $serverId, ':rid' => $resId]);
                        $feedbackMessage = 'Ressource désinstallée du serveur.';
                    } catch (PDOException $e) {
                        error_log(sprintf('[Boötes][Dashboard] Erreur retrait ressource : %s', $e->getMessage()));
                        $feedbackError = 'Erreur lors de la désinstallation.';
                    }
                }
                break;
        }

        // Rechargement des données fraîches après modification
        $server = $serverManager->getServerById($serverId) ?? $server;
    }
}

$isOnline         = $server['statut'] === ServerManager::STATUS_ONLINE;
$allocatedGlobal  = $serverManager->getAllocatedRamMb();
$installedItems   = [];
$compatibleItems  = [];

try {
    $instStmt = $db->prepare('
        SELECT r.id_ressource, r.nom_ressource, r.type_ressource, r.version_compatible, ir.date_installation
        FROM INSTALLER_RESSOURCE ir
        INNER JOIN RESSOURCE r ON r.id_ressource = ir.id_ressource
        WHERE ir.id_serveur = :sid
        ORDER BY ir.date_installation DESC
    ');
    $instStmt->execute([':sid' => $serverId]);
    $installedItems = $instStmt->fetchAll(PDO::FETCH_ASSOC);

    $compatStmt = $db->prepare("
        SELECT id_ressource, nom_ressource, type_ressource, version_compatible
        FROM RESSOURCE
        WHERE statut_moderation = 'VALIDE'
          AND version_compatible = :ver
        ORDER BY nb_telechargements DESC
        LIMIT 15
    ");
    $compatStmt->execute([':ver' => (string) $server['version_mc']]);
    $compatibleItems = $compatStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Dashboard] Erreur chargement ressources : %s', $e->getMessage()));
}
?>
<div class="server-dashboard">
    <header class="server-dashboard__header">
        <div class="server-dashboard__identity">
            <?= View::badge('statut_serveur', $server['statut']) ?>
            <h1 class="server-dashboard__title"><?= Security::e((string) $server['nom_serveur']) ?></h1>
            <p class="server-dashboard__meta">
                Adresse : <code><?= Security::e((string) $server['adresse_ip']) ?>:<?= (int) $server['port'] ?></code>
                &bull; Loader : <strong><?= Security::e(View::label('loader', $server['type_loader'])) ?></strong> (MC <?= Security::e((string) $server['version_mc']) ?>)
                &bull; Propriétaire : <strong><?= Security::e((string) $server['proprietaire_pseudo']) ?></strong>
            </p>
        </div>

        <div class="server-dashboard__power-controls" role="group" aria-label="Contrôle d'alimentation du serveur">
            <form action="/serveur/dashboard?id=<?= $serverId ?>" method="post" class="server-dashboard__power-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="server_id" value="<?= $serverId ?>">
                <input type="hidden" name="action" value="start">
                <button type="submit" class="server-dashboard__btn server-dashboard__btn--start" <?= $isOnline || !$canOperate ? 'disabled' : '' ?>><?= View::icon('play') ?> Démarrer</button>
            </form>

            <form action="/serveur/dashboard?id=<?= $serverId ?>" method="post" class="server-dashboard__power-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="server_id" value="<?= $serverId ?>">
                <input type="hidden" name="action" value="restart">
                <button type="submit" class="server-dashboard__btn server-dashboard__btn--restart" <?= !$isOnline || !$canOperate ? 'disabled' : '' ?>><?= View::icon('rotate-cw') ?> Redémarrer</button>
            </form>

            <form action="/serveur/dashboard?id=<?= $serverId ?>" method="post" class="server-dashboard__power-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="server_id" value="<?= $serverId ?>">
                <input type="hidden" name="action" value="stop">
                <button type="submit" class="server-dashboard__btn server-dashboard__btn--stop" <?= !$isOnline || !$canOperate ? 'disabled' : '' ?> data-confirm="Arrêter le serveur ? Les joueurs connectés seront déconnectés."><?= View::icon('square') ?> Arrêter</button>
            </form>

            <?php if (Auth::isAdmin()): ?>
                <form action="/serveur/dashboard?id=<?= $serverId ?>" method="post" class="server-dashboard__power-form">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="server_id" value="<?= $serverId ?>">
                    <input type="hidden" name="action" value="force_kill">
                    <button type="submit" class="server-dashboard__btn server-dashboard__btn--kill" title="Réservé aux Administrateurs Boötes" data-confirm="Forcer l'arrêt immédiat (SIGKILL) ? Les données non sauvegardées seront perdues."><?= View::icon('zap') ?> Arrêt forcé</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$canConfigure): ?>
        <p class="server-dashboard__notice" role="note">
            Accès co-administrateur <strong><?= Security::e((string) $accessLevel) ?></strong> :
            <?= $canOperate ? 'démarrage, arrêt et console autorisés ; configuration en lecture seule.' : 'consultation uniquement.' ?>
        </p>
    <?php endif; ?>

    <?php if ($feedbackMessage !== null): ?>
        <div class="server-dashboard__alert server-dashboard__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="server-dashboard__alert server-dashboard__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <section class="server-dashboard__overview" aria-label="Métriques rapides de l'instance">
        <dl class="server-metrics">
            <div class="server-metrics__card">
                <dt class="server-metrics__label">Joueurs connectés</dt>
                <dd class="server-metrics__value"><?= (int) $server['joueurs_connectes'] ?> / <?= (int) $server['slots_max'] ?></dd>
            </div>
            <div class="server-metrics__card">
                <dt class="server-metrics__label">RAM allouée à l'instance</dt>
                <dd class="server-metrics__value"><?= (int) $server['ram_allouee'] ?> Mo</dd>
            </div>
            <div class="server-metrics__card">
                <dt class="server-metrics__label">Charge globale Hôte Linux Mint</dt>
                <dd class="server-metrics__value"><?= $allocatedGlobal ?> Mo / <?= ServerManager::HOST_MAX_MC_RAM_MB ?> Mo max</dd>
            </div>
            <div class="server-metrics__card">
                <dt class="server-metrics__label">Visibilité catalogue</dt>
                <dd class="server-metrics__value"><?= (int) $server['est_public'] === 1 ? 'Public' : 'Privé' ?></dd>
            </div>
        </dl>
    </section>

    <div class="server-dashboard__columns">
        <!-- PARAMÈTRES DU SERVEUR -->
        <section class="server-dashboard__panel" aria-labelledby="settings-title">
            <h2 id="settings-title" class="server-dashboard__panel-title">Configuration du serveur</h2>

            <form action="/serveur/dashboard?id=<?= $serverId ?>" method="post" class="server-settings-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="server_id" value="<?= $serverId ?>">
                <input type="hidden" name="action" value="update_settings">
                <fieldset class="server-settings-form__fieldset" <?= $canConfigure ? '' : 'disabled' ?>>

                <div class="server-settings-form__field">
                    <label for="cfg-nom" class="server-settings-form__label">Nom du serveur</label>
                    <input
                        type="text"
                        id="cfg-nom"
                        name="nom_serveur"
                        value="<?= Security::e((string) $server['nom_serveur']) ?>"
                        minlength="3"
                        maxlength="50"
                        required
                        class="server-settings-form__input"
                    >
                </div>

                <div class="server-settings-form__field">
                    <label for="cfg-loader" class="server-settings-form__label">Moteur / Loader Minecraft</label>
                    <select id="cfg-loader" name="type_loader" class="server-settings-form__select">
                        <?php foreach (ServerManager::ALLOWED_LOADERS as $loaderKey => $loaderLabel): ?>
                            <option value="<?= Security::e($loaderKey) ?>" <?= $server['type_loader'] === $loaderKey ? 'selected' : '' ?>>
                                <?= Security::e($loaderLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="server-settings-form__field">
                    <label for="cfg-version" class="server-settings-form__label">Version de Minecraft</label>
                    <select id="cfg-version" name="version_mc" class="server-settings-form__select">
                        <?php foreach (ResourceManager::SUPPORTED_MC_VERSIONS as $mcVer): ?>
                            <option value="<?= Security::e($mcVer) ?>" <?= $server['version_mc'] === $mcVer ? 'selected' : '' ?>>
                                Minecraft <?= Security::e($mcVer) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="server-settings-form__field">
                    <label for="cfg-mode" class="server-settings-form__label">Mode de jeu</label>
                    <select id="cfg-mode" name="mode_jeu" class="server-settings-form__select">
                        <?php foreach (ServerManager::GAME_MODES as $mode => $modeLabel): ?>
                            <option value="<?= Security::e($mode) ?>" <?= strtoupper((string) $server['mode_jeu']) === $mode ? 'selected' : '' ?>>
                                <?= Security::e($modeLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="server-settings-form__field">
                    <label for="cfg-slots" class="server-settings-form__label">Slots joueurs maximum</label>
                    <input
                        type="number"
                        id="cfg-slots"
                        name="slots_max"
                        min="2"
                        max="<?= ServerManager::MAX_SLOTS ?>"
                        value="<?= (int) $server['slots_max'] ?>"
                        required
                        class="server-settings-form__input"
                    >
                </div>

                <div class="server-settings-form__field">
                    <label for="cfg-ram" class="server-settings-form__label">
                        Mémoire RAM dédiée (Mo) — Plafond autorisé : <?= $ramCeiling ?> Mo<?= $isOnline ? ' (modifiable une fois le serveur arrêté)' : '' ?>
                    </label>
                    <input
                        type="number"
                        id="cfg-ram"
                        name="ram_allouee"
                        min="512"
                        max="<?= max(512, $ramCeiling) ?>"
                        step="256"
                        value="<?= (int) $server['ram_allouee'] ?>"
                        required
                        class="server-settings-form__input"
                    >
                </div>

                <div class="server-settings-form__field">
                    <label for="cfg-desc" class="server-settings-form__label">Description publique (MOTD &amp; Annuaire)</label>
                    <textarea
                        id="cfg-desc"
                        name="description"
                        rows="3"
                        maxlength="1000"
                        class="server-settings-form__textarea"
                    ><?= Security::e((string) ($server['description'] ?? '')) ?></textarea>
                </div>

                <div class="server-settings-form__field server-settings-form__field--checkbox">
                    <input
                        type="checkbox"
                        id="cfg-public"
                        name="est_public"
                        value="1"
                        class="server-settings-form__checkbox"
                        <?= (int) $server['est_public'] === 1 ? 'checked' : '' ?>
                    >
                    <label for="cfg-public" class="server-settings-form__checkbox-label">
                        Afficher ce serveur dans l'annuaire public Boötes
                    </label>
                </div>

                <button type="submit" class="server-settings-form__submit">Enregistrer les paramètres</button>
                </fieldset>
            </form>
        </section>

        <!-- INSTALLATION RAPIDE DE MODS / PLUGINS / MODPACKS -->
        <section class="server-dashboard__panel" aria-labelledby="addons-title">
            <h2 id="addons-title" class="server-dashboard__panel-title">Mods, Plugins et Modpacks</h2>

            <?php if ($canConfigure): ?>
            <form action="/serveur/dashboard?id=<?= $serverId ?>" method="post" class="quick-install-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="server_id" value="<?= $serverId ?>">
                <input type="hidden" name="action" value="quick_install_resource">

                <label for="quick-res-select" class="quick-install-form__label">
                    Installation rapide (ressources compatibles MC <?= Security::e((string) $server['version_mc']) ?>) :
                </label>
                <?php if (empty($compatibleItems)): ?>
                    <p class="quick-install-form__empty">
                        Aucune ressource spécifique à la version <?= Security::e((string) $server['version_mc']) ?> trouvée.
                        <a href="/catalogue">Parcourir le catalogue complet</a>.
                    </p>
                <?php else: ?>
                    <div class="quick-install-form__controls">
                        <select id="quick-res-select" name="resource_id" required class="quick-install-form__select">
                            <?php foreach ($compatibleItems as $ci): ?>
                                <option value="<?= (int) $ci['id_ressource'] ?>">
                                    [<?= Security::e(View::label('type_ressource', $ci['type_ressource'])) ?>] <?= Security::e((string) $ci['nom_ressource']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="quick-install-form__submit">Installer</button>
                    </div>
                <?php endif; ?>
            </form>
            <?php endif; ?>

            <h3 class="server-dashboard__subtitle">Ressources actuellement installées (<?= count($installedItems) ?>)</h3>
            <?php if (empty($installedItems)): ?>
                <p class="server-dashboard__empty">Aucun mod, plugin ou modpack n'est installé sur cette instance.</p>
            <?php else: ?>
                <ul class="installed-list">
                    <?php foreach ($installedItems as $inst): ?>
                        <li class="installed-list__item">
                            <div class="installed-list__info">
                                <span class="installed-list__badge"><?= Security::e(View::label('type_ressource', $inst['type_ressource'])) ?></span>
                                <a href="/ressource-detail?id=<?= (int) $inst['id_ressource'] ?>" class="installed-list__name">
                                    <?= Security::e((string) $inst['nom_ressource']) ?>
                                </a>
                                <small class="installed-list__meta">(MC <?= Security::e((string) $inst['version_compatible']) ?>)</small>
                            </div>
                            <?php if ($canConfigure): ?>
                            <form action="/serveur/dashboard?id=<?= $serverId ?>" method="post" class="installed-list__form">
                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                <input type="hidden" name="server_id" value="<?= $serverId ?>">
                                <input type="hidden" name="action" value="uninstall_resource">
                                <input type="hidden" name="resource_id" value="<?= (int) $inst['id_ressource'] ?>">
                                <button type="submit" class="installed-list__remove-btn">Désinstaller</button>
                            </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>