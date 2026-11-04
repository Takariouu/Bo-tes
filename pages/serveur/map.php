<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/ServerManager.php';

use Bootes\Config\Database;
use Bootes\Src\Auth;
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
$canConfigure  = Auth::serverLevelAllows($accessLevel, Auth::SERVER_PERM_FULL);

if ($serverId === null || $currentUserId === null || $accessLevel === null) {
    http_response_code(403);
    ?>
    <section class="server-map server-map--forbidden">
        <h1 class="server-map__title">Accès refusé à la Map 3D</h1>
    </section>
    <?php
    return;
}

$server = $serverManager->getServerById($serverId);
if ($server === null) {
    ?>
    <section class="server-map server-map--not-found">
        <h1 class="server-map__title">Serveur introuvable</h1>
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
    } elseif ($action === 'save_map_config' && !$canConfigure) {
        $feedbackError = Auth::serverPermissionError($accessLevel);
    } elseif ($action === 'save_map_config') {
        $estActive        = isset($_POST['est_active']) && $_POST['est_active'] === '1' ? 1 : 0;
        $modeRendu        = in_array($_POST['mode_rendu'] ?? '3D', ['2D', '3D'], true) ? (string) $_POST['mode_rendu'] : '3D';
        $afficherJoueurs  = isset($_POST['afficher_joueurs']) && $_POST['afficher_joueurs'] === '1' ? 1 : 0;
        $afficherMarqueurs= isset($_POST['afficher_marqueurs']) && $_POST['afficher_marqueurs'] === '1' ? 1 : 0;
        $portMap          = Security::validatePort($_POST['port_map'] ?? ((int) $server['port'] + 1000)) ?? ((int) $server['port'] + 1000);

        if (!$serverManager->isMapPortAvailable($serverId, $portMap)) {
            $feedbackError = sprintf(
                'Port de carte refusé : choisissez un port libre entre %d et %d.',
                ServerManager::MAP_PORT_RANGE_START,
                ServerManager::MAP_PORT_RANGE_END
            );
        } else {
            try {
                $stmt = $db->prepare('
                    INSERT INTO CONFIGURATION_MAP (
                        id_serveur,
                        est_active,
                        mode_rendu,
                        afficher_joueurs,
                        afficher_marqueurs,
                        port_map,
                        date_maj
                    ) VALUES (
                        :sid,
                        :active,
                        :mode,
                        :joueurs,
                        :marqueurs,
                        :port,
                        NOW()
                    )
                    ON DUPLICATE KEY UPDATE
                        est_active = VALUES(est_active),
                        mode_rendu = VALUES(mode_rendu),
                        afficher_joueurs = VALUES(afficher_joueurs),
                        afficher_marqueurs = VALUES(afficher_marqueurs),
                        port_map = VALUES(port_map),
                        date_maj = NOW()
                ');
                $stmt->execute([
                    ':sid'       => $serverId,
                    ':active'    => $estActive,
                    ':mode'      => $modeRendu,
                    ':joueurs'   => $afficherJoueurs,
                    ':marqueurs' => $afficherMarqueurs,
                    ':port'      => $portMap,
                ]);
                $feedbackMessage = 'Configuration de la Map interactive enregistrée.';
            } catch (PDOException $e) {
                error_log(sprintf('[Boötes][Map] Erreur sauvegarde CONFIGURATION_MAP : %s', $e->getMessage()));
                $feedbackError = 'Impossible d\'enregistrer la configuration de la carte.';
            }
        }
    }
}

// Lecture de la configuration actuelle dans CONFIGURATION_MAP
$mapConfig = [
    'est_active'         => 1,
    'mode_rendu'         => '3D',
    'afficher_joueurs'   => 1,
    'afficher_marqueurs' => 1,
    'port_map'           => (int) $server['port'] + 1000,
    'date_maj'           => date('Y-m-d H:i:s'),
];

try {
    $cfgStmt = $db->prepare('
        SELECT est_active, mode_rendu, afficher_joueurs, afficher_marqueurs, port_map, date_maj
        FROM CONFIGURATION_MAP
        WHERE id_serveur = :sid
        LIMIT 1
    ');
    $cfgStmt->execute([':sid' => $serverId]);
    $row = $cfgStmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        $mapConfig = $row;
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Map] Lecture CONFIGURATION_MAP par défaut : %s', $e->getMessage()));
}

$isMapEnabled = (int) $mapConfig['est_active'] === 1;
$renderMode   = (string) $mapConfig['mode_rendu'];
$showPlayers  = (int) $mapConfig['afficher_joueurs'] === 1;
$showMarkers  = (int) $mapConfig['afficher_marqueurs'] === 1;
$mapPort      = (int) $mapConfig['port_map'];
?>
<div class="server-map">
    <header class="server-map__header">
        <div class="server-map__heading">
            <h1 class="server-map__title">
                Carte du monde (<?= Security::e($renderMode) ?>)
            </h1>
            <p class="server-map__subtitle">
                Visualisation spatiale du monde Minecraft et configuration temps réel.
            </p>
        </div>
    </header>

    <?php if ($feedbackMessage !== null): ?>
        <div class="server-map__alert server-map__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="server-map__alert server-map__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <div class="server-map__layout">
        <!-- CONTENEUR D'INTÉGRATION DE LA MAP 3D -->
        <section class="server-map__viewport-section" aria-labelledby="map-viewport-title">
            <header class="server-map__viewport-header">
                <h2 id="map-viewport-title" class="server-map__section-title">
                    Aperçu du monde (Rendu <?= Security::e($renderMode) ?>)
                </h2>
                <div class="server-map__badges">
                    <span class="server-map__badge">
                        État : <?= $isMapEnabled ? 'Activée' : 'Désactivée' ?>
                    </span>
                    <span class="server-map__badge">
                        Joueurs temps réel : <?= $showPlayers ? 'Visibles' : 'Masqués' ?>
                    </span>
                    <span class="server-map__badge">
                        Port WebMap : <?= $mapPort ?>
                    </span>
                </div>
            </header>

            <?php if (!$isMapEnabled): ?>
                <div class="server-map__disabled-placeholder">
                    <p class="server-map__disabled-text">
                        La visualisation de la carte est actuellement désactivée pour ce serveur. Activez-la dans le panneau de configuration ci-contre.
                    </p>
                </div>
            <?php else: ?>
                <figure class="map-viewer" data-server-id="<?= $serverId ?>" data-render-mode="<?= Security::e($renderMode) ?>" data-show-players="<?= $showPlayers ? 'true' : 'false' ?>">
                    <div class="map-viewer__canvas-container" role="application" aria-label="Carte interactive du monde Minecraft">
                        <canvas id="bootes-3d-map-canvas" class="map-viewer__canvas" width="1280" height="720">
                            Votre navigateur ne supporte pas le rendu WebGL/Canvas pour la Map 3D Boötes.
                        </canvas>
                    </div>
                    <figcaption class="map-viewer__caption">
                        Flux cartographique du serveur <strong><?= Security::e((string) $server['nom_serveur']) ?></strong>
                        — Mode <?= Security::e($renderMode) ?>
                        (<?= $showPlayers ? 'Positions des joueurs affichées en direct' : 'Positions des joueurs anonymisées' ?>).
                    </figcaption>
                </figure>
            <?php endif; ?>
        </section>

        <!-- FORMULAIRE DE CONFIGURATION (CONFIGURATION_MAP) -->
        <aside class="server-map__config-panel" aria-labelledby="map-config-title">
            <h2 id="map-config-title" class="server-map__section-title">Paramètres de la Map</h2>

            <form action="/serveur/map?id=<?= $serverId ?>" method="post" class="map-config-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="server_id" value="<?= $serverId ?>">
                <input type="hidden" name="action" value="save_map_config">
                <fieldset class="map-config-form__fieldset" <?= $canConfigure ? '' : 'disabled' ?>>

                <div class="map-config-form__field map-config-form__field--checkbox">
                    <input
                        type="checkbox"
                        id="map-active"
                        name="est_active"
                        value="1"
                        class="map-config-form__checkbox"
                        <?= $isMapEnabled ? 'checked' : '' ?>
                    >
                    <label for="map-active" class="map-config-form__checkbox-label">
                        Activer la vue cartographique web
                    </label>
                </div>

                <fieldset class="map-config-form__fieldset">
                    <legend class="map-config-form__legend">Moteur de rendu</legend>
                    <div class="map-config-form__radio-group">
                        <input
                            type="radio"
                            id="render-2d"
                            name="mode_rendu"
                            value="2D"
                            class="map-config-form__radio"
                            <?= $renderMode === '2D' ? 'checked' : '' ?>
                        >
                        <label for="render-2d" class="map-config-form__radio-label">
                            Vue 2D (Carte aérienne plate légère en ressources)
                        </label>
                    </div>
                    <div class="map-config-form__radio-group">
                        <input
                            type="radio"
                            id="render-3d"
                            name="mode_rendu"
                            value="3D"
                            class="map-config-form__radio"
                            <?= $renderMode === '3D' ? 'checked' : '' ?>
                        >
                        <label for="render-3d" class="map-config-form__radio-label">
                            Vue 3D Isométrique / WebGL (Relief complet et rotation libre)
                        </label>
                    </div>
                </fieldset>

                <div class="map-config-form__field map-config-form__field--checkbox">
                    <input
                        type="checkbox"
                        id="map-players"
                        name="afficher_joueurs"
                        value="1"
                        class="map-config-form__checkbox"
                        <?= $showPlayers ? 'checked' : '' ?>
                    >
                    <label for="map-players" class="map-config-form__checkbox-label">
                        Afficher les joueurs connectés en temps réel sur la carte
                    </label>
                </div>

                <div class="map-config-form__field map-config-form__field--checkbox">
                    <input
                        type="checkbox"
                        id="map-markers"
                        name="afficher_marqueurs"
                        value="1"
                        class="map-config-form__checkbox"
                        <?= $showMarkers ? 'checked' : '' ?>
                    >
                    <label for="map-markers" class="map-config-form__checkbox-label">
                        Afficher les points d'intérêt (Spawn, portails, régions)
                    </label>
                </div>

                <div class="map-config-form__field">
                    <label for="map-port" class="map-config-form__label">
                        Port dédié au flux Map (<?= ServerManager::MAP_PORT_RANGE_START ?>–<?= ServerManager::MAP_PORT_RANGE_END ?>)
                    </label>
                    <input
                        type="number"
                        id="map-port"
                        name="port_map"
                        min="<?= ServerManager::MAP_PORT_RANGE_START ?>"
                        max="<?= ServerManager::MAP_PORT_RANGE_END ?>"
                        value="<?= $mapPort ?>"
                        required
                        class="map-config-form__input"
                    >
                </div>

                <button type="submit" class="map-config-form__submit">Enregistrer la configuration</button>
                </fieldset>
            </form>
        </aside>
    </div>
</div>