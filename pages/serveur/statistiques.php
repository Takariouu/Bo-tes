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
    <section class="server-stats server-stats--forbidden">
        <h1 class="server-stats__title">Accès refusé aux statistiques</h1>
    </section>
    <?php
    return;
}

$server = $serverManager->getServerById($serverId);
if ($server === null) {
    ?>
    <section class="server-stats server-stats--not-found">
        <h1 class="server-stats__title">Serveur introuvable</h1>
    </section>
    <?php
    return;
}

// 1. Historique de consommation RAM / CPU (HISTORIQUE_RESSOURCE)
$resourceHistory = [];
$peakPlayers     = (int) $server['joueurs_connectes'];
$avgRamMb        = 0.0;
$avgCpuPct       = 0.0;

try {
    $histStmt = $db->prepare('
        SELECT id_mesure, ram_utilisee_mo, cpu_pourcentage, joueurs_en_ligne, date_mesure
        FROM HISTORIQUE_RESSOURCE
        WHERE id_serveur = :sid
        ORDER BY date_mesure DESC
        LIMIT 30
    ');
    $histStmt->execute([':sid' => $serverId]);
    $resourceHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($resourceHistory)) {
        $totalRam = 0;
        $totalCpu = 0.0;
        foreach ($resourceHistory as $h) {
            $totalRam += (int) $h['ram_utilisee_mo'];
            $totalCpu += (float) $h['cpu_pourcentage'];
            if ((int) $h['joueurs_en_ligne'] > $peakPlayers) {
                $peakPlayers = (int) $h['joueurs_en_ligne'];
            }
        }
        $count    = count($resourceHistory);
        $avgRamMb = round($totalRam / $count, 1);
        $avgCpuPct= round($totalCpu / $count, 1);
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][ServerStats] Erreur lecture HISTORIQUE_RESSOURCE : %s', $e->getMessage()));
}

// 2. Top joueurs par temps de jeu cumulé et nombre de sessions (CONNEXION_JOUEUR)
$topPlayers  = [];
$totalUptime = 0;

try {
    $topStmt = $db->prepare('
        SELECT 
            pseudo_mc,
            COUNT(*) AS nb_sessions,
            COALESCE(SUM(TIMESTAMPDIFF(MINUTE, date_connexion, COALESCE(date_deconnexion, NOW()))), 0) AS total_minutes,
            MAX(date_connexion) AS derniere_connexion
        FROM CONNEXION_JOUEUR
        WHERE id_serveur = :sid
        GROUP BY pseudo_mc
        ORDER BY total_minutes DESC, nb_sessions DESC
        LIMIT 10
    ');
    $topStmt->execute([':sid' => $serverId]);
    $topPlayers = $topStmt->fetchAll(PDO::FETCH_ASSOC);

    $uptimeStmt = $db->prepare('
        SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, date_connexion, COALESCE(date_deconnexion, NOW()))), 0)
        FROM CONNEXION_JOUEUR
        WHERE id_serveur = :sid
    ');
    $uptimeStmt->execute([':sid' => $serverId]);
    $totalUptime = (int) $uptimeStmt->fetchColumn();
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][ServerStats] Erreur lecture top joueurs : %s', $e->getMessage()));
}

$uptimeHours = round($totalUptime / 60, 1);
$maxRam      = max(1, (int) $server['ram_allouee']);
?>
<div class="server-stats">
    <header class="server-stats__header">
        <h1 class="server-stats__title">
            Statistiques &amp; Télémétrie — <?= Security::e((string) $server['nom_serveur']) ?>
        </h1>
        <p class="server-stats__subtitle">
            Suivi de consommation RAM/CPU (<code>HISTORIQUE_RESSOURCE</code>), temps d'activité et fréquentation des joueurs.
        </p>
    </header>

    <section class="server-stats__kpis" aria-label="Indicateurs de performance du serveur">
        <dl class="kpi-grid">
            <div class="kpi-card">
                <dt class="kpi-card__label">RAM Moyenne / Allouée</dt>
                <dd class="kpi-card__value"><?= Security::e((string) $avgRamMb) ?> Mo / <?= $maxRam ?> Mo</dd>
            </div>
            <div class="kpi-card">
                <dt class="kpi-card__label">Charge CPU Moyenne</dt>
                <dd class="kpi-card__value"><?= Security::e((string) $avgCpuPct) ?> %</dd>
            </div>
            <div class="kpi-card">
                <dt class="kpi-card__label">Pic de joueurs simultané</dt>
                <dd class="kpi-card__value"><?= $peakPlayers ?> / <?= (int) $server['slots_max'] ?></dd>
            </div>
            <div class="kpi-card">
                <dt class="kpi-card__label">Temps d'ouverture cumulé (Uptime)</dt>
                <dd class="kpi-card__value"><?= Security::e((string) $uptimeHours) ?> h</dd>
            </div>
        </dl>
    </section>

    <div class="server-stats__grid">
        <!-- CONSOMMATION RAM / CPU (HISTORIQUE_RESSOURCE) -->
        <section class="server-stats__panel" aria-labelledby="telemetry-heading">
            <h2 id="telemetry-heading" class="server-stats__panel-title">
                Historique de consommation RAM &amp; CPU (30 derniers relevés)
            </h2>

            <?php if (empty($resourceHistory)): ?>
                <p class="server-stats__empty">Aucun relevé disponible dans <code>HISTORIQUE_RESSOURCE</code>.</p>
            <?php else: ?>
                <table class="telemetry-table">
                    <caption class="telemetry-table__caption">Mesures périodiques des ressources de l'instance</caption>
                    <thead class="telemetry-table__head">
                        <tr class="telemetry-table__row">
                            <th scope="col" class="telemetry-table__cell">Horodatage</th>
                            <th scope="col" class="telemetry-table__cell">RAM Utilisée</th>
                            <th scope="col" class="telemetry-table__cell">Charge CPU</th>
                            <th scope="col" class="telemetry-table__cell">Joueurs en ligne</th>
                        </tr>
                    </thead>
                    <tbody class="telemetry-table__body">
                        <?php foreach ($resourceHistory as $row): ?>
                            <?php
                            $ramMo  = (int) $row['ram_utilisee_mo'];
                            $ramPct = min(100, round(($ramMo / $maxRam) * 100, 1));
                            $cpuVal = round((float) $row['cpu_pourcentage'], 1);
                            ?>
                            <tr class="telemetry-table__row">
                                <td class="telemetry-table__cell"><?= Security::e((string) $row['date_mesure']) ?></td>
                                <td class="telemetry-table__cell">
                                    <meter class="telemetry-table__meter" min="0" max="<?= $maxRam ?>" value="<?= $ramMo ?>">
                                        <?= Security::e((string) $ramPct) ?> %
                                    </meter>
                                    <span><?= $ramMo ?> Mo (<?= Security::e((string) $ramPct) ?> %)</span>
                                </td>
                                <td class="telemetry-table__cell">
                                    <progress class="telemetry-table__progress" value="<?= Security::e((string) $cpuVal) ?>" max="100">
                                        <?= Security::e((string) $cpuVal) ?> %
                                    </progress>
                                    <span><?= Security::e((string) $cpuVal) ?> %</span>
                                </td>
                                <td class="telemetry-table__cell"><?= (int) $row['joueurs_en_ligne'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <!-- TOP JOUEURS -->
        <section class="server-stats__panel" aria-labelledby="top-players-heading">
            <h2 id="top-players-heading" class="server-stats__panel-title">
                Top 10 des joueurs les plus actifs
            </h2>

            <?php if (empty($topPlayers)): ?>
                <p class="server-stats__empty">Aucune session joueur enregistrée sur ce serveur.</p>
            <?php else: ?>
                <table class="top-players-table">
                    <caption class="top-players-table__caption">Classement par temps de présence cumulé</caption>
                    <thead class="top-players-table__head">
                        <tr class="top-players-table__row">
                            <th scope="col" class="top-players-table__cell">Rang</th>
                            <th scope="col" class="top-players-table__cell">Joueur</th>
                            <th scope="col" class="top-players-table__cell">Sessions</th>
                            <th scope="col" class="top-players-table__cell">Temps cumulé</th>
                            <th scope="col" class="top-players-table__cell">Dernière visite</th>
                        </tr>
                    </thead>
                    <tbody class="top-players-table__body">
                        <?php foreach ($topPlayers as $idx => $tp): ?>
                            <tr class="top-players-table__row">
                                <td class="top-players-table__cell">#<?= $idx + 1 ?></td>
                                <th scope="row" class="top-players-table__cell"><?= Security::e((string) $tp['pseudo_mc']) ?></th>
                                <td class="top-players-table__cell"><?= (int) $tp['nb_sessions'] ?></td>
                                <td class="top-players-table__cell"><?= (int) $tp['total_minutes'] ?> min</td>
                                <td class="top-players-table__cell"><?= Security::e((string) $tp['derniere_connexion']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</div>