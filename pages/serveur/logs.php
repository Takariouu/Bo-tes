<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/ServerManager.php';

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Security;
use Bootes\Src\ServerManager;

$db            = $db ?? Database::getInstance();
$serverManager = new ServerManager($db);

$serverId      = isset($activeServerId) && is_int($activeServerId)
    ? $activeServerId
    : Security::validatePositiveInt($_GET['id'] ?? null);
$currentUserId = Auth::getUserId();
$accessLevel   = $serverId !== null && $currentUserId !== null
    ? Auth::getServerAccessLevel($currentUserId, $serverId, $db)
    : null;

if ($serverId === null || $currentUserId === null || $accessLevel === null) {
    http_response_code(403);
    ?>
    <section class="server-logs server-logs--forbidden">
        <h1 class="server-logs__title">Accès refusé aux journaux</h1>
    </section>
    <?php
    return;
}

$server = $serverManager->getServerById($serverId);
if ($server === null) {
    ?>
    <section class="server-logs server-logs--not-found">
        <h1 class="server-logs__title">Serveur introuvable</h1>
    </section>
    <?php
    return;
}

$levelFilter  = strtoupper(Security::validateString($_GET['level'] ?? 'ALL', 1, 10) ?? 'ALL');
$playerSearch = Security::validateString($_GET['player'] ?? '', 0, 32) ?? '';

$allLogLines  = $serverManager->readLatestLog($serverId, 300);
$filteredLogs = [];

foreach ($allLogLines as $line) {
    if ($levelFilter !== 'ALL' && !str_contains(strtoupper($line), '/' . $levelFilter)) {
        continue;
    }
    if ($playerSearch !== '' && stripos($line, $playerSearch) === false) {
        continue;
    }
    $filteredLogs[] = $line;
}

// Historique des connexions/déconnexions des joueurs (CONNEXION_JOUEUR)
$playerConnections = [];
try {
    $sql = '
        SELECT 
            id_connexion,
            pseudo_mc,
            uuid_mc,
            adresse_ip,
            date_connexion,
            date_deconnexion,
            TIMESTAMPDIFF(MINUTE, date_connexion, COALESCE(date_deconnexion, NOW())) AS duree_minutes,
            COALESCE(motif_deconnexion, \'Session normale\') AS motif_deconnexion
        FROM CONNEXION_JOUEUR
        WHERE id_serveur = :sid
    ';
    $params = [':sid' => $serverId];

    if ($playerSearch !== '') {
        $sql .= ' AND pseudo_mc LIKE :player ESCAPE \'!\'';
        $params[':player'] = Security::likeContains($playerSearch);
    }

    $sql .= ' ORDER BY date_connexion DESC LIMIT 100';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $playerConnections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Logs] Erreur lecture CONNEXION_JOUEUR : %s', $e->getMessage()));
}

$isGlobalAdmin = Auth::isAdmin();
?>
<div class="server-logs">
    <header class="server-logs__header">
        <div class="server-logs__heading">
            <h1 class="server-logs__title">
                Journaux &amp; Audit Anti-Triche — <?= Security::e((string) $server['nom_serveur']) ?>
            </h1>
            <p class="server-logs__subtitle">
                Inspection de <code>logs/latest.log</code> et traçabilité des sessions joueurs (<code>CONNEXION_JOUEUR</code>).
            </p>
        </div>

        <form action="/serveur/logs" method="get" class="server-logs__filter-form" role="search">
            <input type="hidden" name="id" value="<?= $serverId ?>">

            <div class="server-logs__field">
                <label for="log-level" class="server-logs__label">Niveau de log</label>
                <select id="log-level" name="level" class="server-logs__select">
                    <option value="ALL" <?= $levelFilter === 'ALL' ? 'selected' : '' ?>>Tous les niveaux</option>
                    <option value="INFO" <?= $levelFilter === 'INFO' ? 'selected' : '' ?>>INFO uniquement</option>
                    <option value="WARN" <?= $levelFilter === 'WARN' ? 'selected' : '' ?>>WARN (Avertissements)</option>
                    <option value="ERROR" <?= $levelFilter === 'ERROR' ? 'selected' : '' ?>>ERROR (Erreurs critiques)</option>
                </select>
            </div>

            <div class="server-logs__field">
                <label for="log-player" class="server-logs__label">Filtrer par pseudo joueur</label>
                <input
                    type="search"
                    id="log-player"
                    name="player"
                    value="<?= Security::e($playerSearch) ?>"
                    placeholder="Ex : Alex"
                    maxlength="32"
                    class="server-logs__input"
                >
            </div>

            <button type="submit" class="server-logs__submit">Filtrer</button>
            <a href="/serveur/logs?id=<?= $serverId ?>" class="server-logs__reset">Réinitialiser</a>
        </form>
    </header>

    <section class="server-logs__section" aria-labelledby="connections-history-title">
        <h2 id="connections-history-title" class="server-logs__section-title">
            Historique de connexion et déconnexion des joueurs (<?= count($playerConnections) ?>)
        </h2>

        <?php if (empty($playerConnections)): ?>
            <p class="server-logs__empty">Aucun événement de connexion joueur enregistré pour les critères sélectionnés.</p>
        <?php else: ?>
            <table class="connections-table">
                <caption class="connections-table__caption">
                    Traçabilité des sessions sur <?= Security::e((string) $server['nom_serveur']) ?>
                </caption>
                <thead class="connections-table__head">
                    <tr class="connections-table__row">
                        <th scope="col" class="connections-table__cell">Pseudo Minecraft</th>
                        <th scope="col" class="connections-table__cell">UUID</th>
                        <?php if ($isGlobalAdmin): ?>
                            <th scope="col" class="connections-table__cell">Adresse IP (Admin)</th>
                        <?php endif; ?>
                        <th scope="col" class="connections-table__cell">Connexion</th>
                        <th scope="col" class="connections-table__cell">Déconnexion</th>
                        <th scope="col" class="connections-table__cell">Durée</th>
                        <th scope="col" class="connections-table__cell">Motif / État</th>
                    </tr>
                </thead>
                <tbody class="connections-table__body">
                    <?php foreach ($playerConnections as $conn): ?>
                        <tr class="connections-table__row">
                            <th scope="row" class="connections-table__cell">
                                <?= Security::e((string) $conn['pseudo_mc']) ?>
                            </th>
                            <td class="connections-table__cell">
                                <code><?= Security::e((string) ($conn['uuid_mc'] ?? 'N/A')) ?></code>
                            </td>
                            <?php if ($isGlobalAdmin): ?>
                                <td class="connections-table__cell">
                                    <code><?= Security::e((string) ($conn['adresse_ip'] ?? 'Masquée')) ?></code>
                                </td>
                            <?php endif; ?>
                            <td class="connections-table__cell"><?= Security::e((string) $conn['date_connexion']) ?></td>
                            <td class="connections-table__cell">
                                <?= $conn['date_deconnexion'] !== null ? Security::e((string) $conn['date_deconnexion']) : 'En jeu' ?>
                            </td>
                            <td class="connections-table__cell"><?= (int) $conn['duree_minutes'] ?> min</td>
                            <td class="connections-table__cell"><?= Security::e((string) $conn['motif_deconnexion']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="server-logs__section" aria-labelledby="raw-logs-title">
        <h2 id="raw-logs-title" class="server-logs__section-title">
            Journaux système du serveur (<?= count($filteredLogs) ?> ligne(s))
        </h2>
        <div class="log-viewer" role="region" aria-label="Contenu filtré de latest.log">
            <pre class="log-viewer__pre"><code class="log-viewer__code"><?php
                if (empty($filteredLogs)) {
                    echo 'Aucune entrée de journal ne correspond à votre filtre.';
                } else {
                    foreach ($filteredLogs as $line) {
                        echo Security::e($line) . "\n";
                    }
                }
            ?></code></pre>
        </div>
    </section>
</div>