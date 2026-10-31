<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/ServerManager.php';

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Security;
use Bootes\Src\ServerManager;

if (!Auth::isAdmin()) {
    http_response_code(403);
    ?>
    <section class="admin-hardware admin-hardware--forbidden">
        <h1 class="admin-hardware__title">Accès refusé</h1>
        <p class="admin-hardware__message">La supervision matérielle de l'hôte Linux Mint est réservée aux Administrateurs.</p>
    </section>
    <?php
    return;
}

$db            = $db ?? Database::getInstance();
$serverManager = new ServerManager($db);
$currentUserId = Auth::getUserId() ?? 0;
$csrfToken     = Security::generateCsrfToken();

$feedbackMessage   = null;
$feedbackError     = null;
$diagnosticOutput  = [];
$selectedDiagCmd   = 'uptime';

// Liste blanche stricte de commandes système non destructrices pour le diagnostic de l'hôte Linux Mint
$allowedDiagCommands = [
    'uptime'   => ['label' => 'Uptime & Charge moyenne du système',    'cmd' => 'uptime'],
    'free'     => ['label' => 'État détaillé de la mémoire (free -m)', 'cmd' => 'free -m'],
    'df'       => ['label' => 'Occupation des partitions (df -h)',     'cmd' => 'df -h'],
    'jvm_ps'   => ['label' => 'Processus Java / Minecraft actifs',     'cmd' => 'ps -C java -o pid,pcpu,pmem,rss,comm --no-headers'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 35) ?? '';

    if (!Security::verifyCsrfToken($postCsrf)) {
        $feedbackError = 'Jeton CSRF invalide ou expiré.';
    } else {
        if ($action === 'force_kill_server') {
            $targetServerId = Security::validatePositiveInt($_POST['server_id'] ?? null);
            if ($targetServerId === null) {
                $feedbackError = 'Identifiant de serveur invalide.';
            } else {
                $res = $serverManager->forceKillServer($targetServerId, $currentUserId);
                if ($res['success']) {
                    $feedbackMessage = $res['message'];
                    try {
                        $logStmt = $db->prepare('
                            INSERT INTO LOG_SYSTEME (id_utilisateur, niveau, action, details, adresse_ip, date_log)
                            VALUES (:uid, \'CRITICAL\', \'FORCE_KILL_JVM\', :details, :ip, NOW())
                        ');
                        $logStmt->execute([
                            ':uid'     => $currentUserId,
                            ':details' => sprintf('Arrêt forcé d\'urgence (SIGKILL) du serveur #%d depuis la console Hardware.', $targetServerId),
                            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        ]);
                    } catch (PDOException $e) {
                        error_log(sprintf('[Boötes][Hardware] Journalisation SIGKILL : %s', $e->getMessage()));
                    }
                } else {
                    $feedbackError = $res['message'];
                }
            }
        } elseif ($action === 'force_kill_all_overloaded') {
            try {
                $onlineStmt = $db->query("SELECT id_serveur FROM SERVEUR WHERE statut = 'EN_LIGNE'");
                $killedCount = 0;
                if ($onlineStmt !== false) {
                    foreach ($onlineStmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                        $killRes = $serverManager->forceKillServer((int) $sid, $currentUserId);
                        if ($killRes['success']) {
                            $killedCount++;
                        }
                    }
                }
                $feedbackMessage = sprintf('Arrêt d\'urgence global exécuté : %d instance(s) Minecraft interrompue(s) pour libérer la RAM de l\'hôte.', $killedCount);
            } catch (PDOException $e) {
                error_log(sprintf('[Boötes][Hardware] Erreur kill global : %s', $e->getMessage()));
                $feedbackError = 'Erreur lors de l\'arrêt d\'urgence global.';
            }
        } elseif ($action === 'run_diag_command') {
            $cmdKey = Security::validateString($_POST['diag_cmd'] ?? 'uptime', 2, 20) ?? 'uptime';
            if (array_key_exists($cmdKey, $allowedDiagCommands)) {
                $selectedDiagCmd = $cmdKey;
                $rawCmd          = $allowedDiagCommands[$cmdKey]['cmd'];
                @exec($rawCmd . ' 2>&1', $diagnosticOutput, $retCode);
                if (empty($diagnosticOutput)) {
                    $diagnosticOutput = ['[Système Linux Mint] Aucune sortie retournée (Code retour : ' . $retCode . ').'];
                }
            } else {
                $feedbackError = 'Commande système non autorisée par la politique de sécurité OWASP.';
            }
        }
    }
}

// Métriques temps réel de la machine hôte Linux Mint
$hostTotalRamMb     = ServerManager::HOST_TOTAL_RAM_MB;   // 8192 Mo (8 Go)
$mcMaxCeilingMb     = ServerManager::HOST_MAX_MC_RAM_MB;  // 6144 Mo (6 Go)
$mcAllocatedRamMb   = $serverManager->getAllocatedRamMb();
$hostAvailableRamMb = $serverManager->getHostAvailableRamMb();
$osReservedRamMb    = $hostTotalRamMb - $mcMaxCeilingMb;  // 2048 Mo (2 Go pour Linux Mint + MariaDB)

$loadAvg = function_exists('sys_getloadavg') ? sys_getloadavg() : [0.25, 0.20, 0.15];
$load1m  = is_array($loadAvg) && isset($loadAvg[0]) ? round((float) $loadAvg[0], 2) : 0.0;
$load5m  = is_array($loadAvg) && isset($loadAvg[1]) ? round((float) $loadAvg[1], 2) : 0.0;
$load15m = is_array($loadAvg) && isset($loadAvg[2]) ? round((float) $loadAvg[2], 2) : 0.0;

$backupsDir = dirname(__DIR__, 2) . '/backups';
if (!is_dir($backupsDir)) {
    $backupsDir = __DIR__;
}

$diskFreeBytes  = @disk_free_space($backupsDir);
$diskTotalBytes = @disk_total_space($backupsDir);
$diskFreeGb     = $diskFreeBytes !== false ? round($diskFreeBytes / 1073741824, 2) : 0.0;
$diskTotalGb    = $diskTotalBytes !== false && $diskTotalBytes > 0 ? round($diskTotalBytes / 1073741824, 2) : 100.0;
$diskUsedGb     = max(0.0, round($diskTotalGb - $diskFreeGb, 2));

// Liste des processus serveurs Minecraft actifs et de leur télémétrie
$runningServers = [];
try {
    $runStmt = $db->query("
        SELECT 
            s.id_serveur,
            s.nom_serveur,
            s.port,
            s.version_mc,
            COALESCE(s.type_loader, 'VANILLA') AS type_loader,
            s.ram_allouee,
            s.joueurs_connectes,
            s.slots_max,
            s.statut,
            u.pseudo AS proprietaire_pseudo,
            (
                SELECT hr.ram_utilisee_mo
                FROM HISTORIQUE_RESSOURCE hr
                WHERE hr.id_serveur = s.id_serveur
                ORDER BY hr.date_mesure DESC
                LIMIT 1
            ) AS derniere_ram_reelle,
            (
                SELECT hr.cpu_pourcentage
                FROM HISTORIQUE_RESSOURCE hr
                WHERE hr.id_serveur = s.id_serveur
                ORDER BY hr.date_mesure DESC
                LIMIT 1
            ) AS dernier_cpu_reel
        FROM SERVEUR s
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = s.id_proprietaire
        ORDER BY s.statut = 'EN_LIGNE' DESC, s.ram_allouee DESC
    ");
    if ($runStmt !== false) {
        $runningServers = $runStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Hardware] Erreur lecture serveurs actifs : %s', $e->getMessage()));
}
?>
<div class="admin-hardware">
    <header class="admin-hardware__header">
        <div class="admin-hardware__heading">
            <p class="admin-hardware__eyebrow">Supervision Matérielle &amp; Ordonnancement JVM</p>
            <h1 class="admin-hardware__title">Monitoring Hôte Linux Mint (8 Go RAM)</h1>
            <p class="admin-hardware__subtitle">
                Contrôle en temps réel du plafond des 6 Go allouables aux instances Java/Minecraft, de l'espace disque <code>/backups</code> et arrêt forcé des processus saturants.
            </p>
        </div>

        <form action="/admin/hardware" method="post" class="admin-hardware__emergency-form">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="action" value="force_kill_all_overloaded">
            <button type="submit" class="admin-hardware__btn admin-hardware__btn--emergency">
                Arrêt d'urgence global (Libérer toute la RAM MC)
            </button>
        </form>
    </header>

    <?php if ($feedbackMessage !== null): ?>
        <div class="admin-hardware__alert admin-hardware__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="admin-hardware__alert admin-hardware__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <!-- JAUGES MATÉRIELLES DE LA MACHINE HÔTE -->
    <section class="admin-hardware__gauges" aria-labelledby="gauges-heading">
        <h2 id="gauges-heading" class="admin-hardware__section-title">État physique du serveur hôte</h2>

        <div class="hardware-cards">
            <article class="hardware-card">
                <h3 class="hardware-card__title">Allocation RAM Minecraft (Plafond 6 Go)</h3>
                <p class="hardware-card__reading">
                    <strong><?= $mcAllocatedRamMb ?> Mo</strong> alloués sur <strong><?= $mcMaxCeilingMb ?> Mo</strong> autorisés
                </p>
                <meter
                    class="hardware-card__meter"
                    min="0"
                    max="<?= $mcMaxCeilingMb ?>"
                    low="2048"
                    high="5120"
                    optimum="1024"
                    value="<?= $mcAllocatedRamMb ?>"
                >
                    <?= $mcAllocatedRamMb ?> Mo / <?= $mcMaxCeilingMb ?> Mo
                </meter>
                <p class="hardware-card__note">
                    Marge restante pour démarrer de nouveaux serveurs : <strong><?= max(0, $mcMaxCeilingMb - $mcAllocatedRamMb) ?> Mo</strong>.
                </p>
            </article>

            <article class="hardware-card">
                <h3 class="hardware-card__title">Mémoire Physique Totale (8 Go Linux Mint)</h3>
                <p class="hardware-card__reading">
                    RAM système libre détectée : <strong><?= $hostAvailableRamMb ?> Mo</strong> / <?= $hostTotalRamMb ?> Mo
                </p>
                <meter
                    class="hardware-card__meter"
                    min="0"
                    max="<?= $hostTotalRamMb ?>"
                    value="<?= max(0, $hostTotalRamMb - $hostAvailableRamMb) ?>"
                >
                    <?= max(0, $hostTotalRamMb - $hostAvailableRamMb) ?> Mo utilisés
                </meter>
                <p class="hardware-card__note">
                    Réserve sanctuarisée pour l'OS Linux Mint &amp; MariaDB : <strong><?= $osReservedRamMb ?> Mo (2 Go)</strong>.
                </p>
            </article>

            <article class="hardware-card">
                <h3 class="hardware-card__title">Charge Processeur (CPU Load Average)</h3>
                <p class="hardware-card__reading">
                    1 min : <strong><?= Security::e((string) $load1m) ?></strong> &bull;
                    5 min : <strong><?= Security::e((string) $load5m) ?></strong> &bull;
                    15 min : <strong><?= Security::e((string) $load15m) ?></strong>
                </p>
                <progress class="hardware-card__progress" value="<?= Security::e((string) min(100, $load1m * 25)) ?>" max="100">
                    Charge : <?= Security::e((string) $load1m) ?>
                </progress>
                <p class="hardware-card__note">
                    Ordonnancement temps réel des threads JVM sur l'hôte.
                </p>
            </article>

            <article class="hardware-card">
                <h3 class="hardware-card__title">Stockage Disque &amp; Partition <code>/backups</code></h3>
                <p class="hardware-card__reading">
                    Disponible : <strong><?= Security::e((string) $diskFreeGb) ?> Go</strong> sur <?= Security::e((string) $diskTotalGb) ?> Go
                </p>
                <meter
                    class="hardware-card__meter"
                    min="0"
                    max="<?= Security::e((string) $diskTotalGb) ?>"
                    value="<?= Security::e((string) $diskUsedGb) ?>"
                >
                    <?= Security::e((string) $diskUsedGb) ?> Go occupés
                </meter>
                <p class="hardware-card__note">
                    Utilisé par les mondes Minecraft et les archives <code>.tar.gz</code> : <?= Security::e((string) $diskUsedGb) ?> Go.
                </p>
            </article>
        </div>
    </section>

    <!-- TABLEAU DES PROCESSUS SERVEURS ET ARRÊT FORCÉ (SIGKILL) -->
    <section class="admin-hardware__processes" aria-labelledby="processes-heading">
        <h2 id="processes-heading" class="admin-hardware__section-title">
            Processus Serveurs Minecraft &amp; Contrôle d'Urgence
        </h2>

        <?php if (empty($runningServers)): ?>
            <p class="admin-hardware__empty">Aucune instance Minecraft configurée.</p>
        <?php else: ?>
            <table class="hardware-table">
                <caption class="hardware-table__caption">
                    Instances hébergées et consommation individuelle de ressources
                </caption>
                <thead class="hardware-table__head">
                    <tr class="hardware-table__row">
                        <th scope="col" class="hardware-table__cell">ID</th>
                        <th scope="col" class="hardware-table__cell">Instance &amp; Loader</th>
                        <th scope="col" class="hardware-table__cell">Propriétaire</th>
                        <th scope="col" class="hardware-table__cell">Port</th>
                        <th scope="col" class="hardware-table__cell">Statut</th>
                        <th scope="col" class="hardware-table__cell">RAM Allouée / Réelle</th>
                        <th scope="col" class="hardware-table__cell">CPU</th>
                        <th scope="col" class="hardware-table__cell">Joueurs</th>
                        <th scope="col" class="hardware-table__cell">Actions Matérielles</th>
                    </tr>
                </thead>
                <tbody class="hardware-table__body">
                    <?php foreach ($runningServers as $srv): ?>
                        <?php
                        $sid      = (int) $srv['id_serveur'];
                        $isOnline = $srv['statut'] === ServerManager::STATUS_ONLINE;
                        $realRam  = $isOnline ? (int) ($srv['derniere_ram_reelle'] ?? round(((int) $srv['ram_allouee']) * 0.6)) : 0;
                        $realCpu  = $isOnline ? round((float) ($srv['dernier_cpu_reel'] ?? 8.5), 1) : 0.0;
                        ?>
                        <tr class="hardware-table__row">
                            <td class="hardware-table__cell">#<?= $sid ?></td>
                            <th scope="row" class="hardware-table__cell">
                                <a href="/serveur/dashboard?id=<?= $sid ?>" class="hardware-table__link">
                                    <?= Security::e((string) $srv['nom_serveur']) ?>
                                </a>
                                <small>(<?= Security::e((string) $srv['type_loader']) ?> <?= Security::e((string) $srv['version_mc']) ?>)</small>
                            </th>
                            <td class="hardware-table__cell"><?= Security::e((string) $srv['proprietaire_pseudo']) ?></td>
                            <td class="hardware-table__cell"><code>:<?= (int) $srv['port'] ?></code></td>
                            <td class="hardware-table__cell">
                                <span class="hardware-table__status"><?= Security::e((string) $srv['statut']) ?></span>
                            </td>
                            <td class="hardware-table__cell">
                                <?= $realRam ?> Mo / <strong><?= (int) $srv['ram_allouee'] ?> Mo</strong>
                            </td>
                            <td class="hardware-table__cell"><?= Security::e((string) $realCpu) ?> %</td>
                            <td class="hardware-table__cell"><?= (int) $srv['joueurs_connectes'] ?> / <?= (int) $srv['slots_max'] ?></td>
                            <td class="hardware-table__cell hardware-table__cell--actions">
                                <a href="/serveur/console?id=<?= $sid ?>" class="hardware-table__btn">Console</a>
                                <?php if ($isOnline): ?>
                                    <form action="/admin/hardware" method="post" class="hardware-table__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="force_kill_server">
                                        <input type="hidden" name="server_id" value="<?= $sid ?>">
                                        <button type="submit" class="hardware-table__btn hardware-table__btn--kill">
                                            Kill Processus (SIGKILL)
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <!-- CONSOLE SYSTÈME SÉCURISÉE DE DIAGNOSTIC LINUX MINT -->
    <section class="admin-hardware__console" aria-labelledby="sys-console-heading">
        <h2 id="sys-console-heading" class="admin-hardware__section-title">
            Console Système Sécurisée (Sondes Linux Mint)
        </h2>

        <form action="/admin/hardware" method="post" class="sys-console-form">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="action" value="run_diag_command">

            <label for="diag-cmd-select" class="sys-console-form__label">
                Exécuter une sonde système pré-autorisée :
            </label>
            <div class="sys-console-form__controls">
                <select id="diag-cmd-select" name="diag_cmd" class="sys-console-form__select">
                    <?php foreach ($allowedDiagCommands as $cmdKey => $cmdMeta): ?>
                        <option value="<?= Security::e($cmdKey) ?>" <?= $selectedDiagCmd === $cmdKey ? 'selected' : '' ?>>
                            <?= Security::e($cmdMeta['label']) ?> (<?= Security::e($cmdMeta['cmd']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="sys-console-form__submit">Interroger l'hôte</button>
            </div>
        </form>

        <div class="terminal" role="region" aria-label="Sortie de la sonde système Linux Mint">
            <div class="terminal__header">
                <span class="terminal__prompt">root@bootes-linux-mint:~# <?= Security::e($allowedDiagCommands[$selectedDiagCmd]['cmd']) ?></span>
            </div>
            <pre class="terminal__output"><code class="terminal__code"><?php
                if (empty($diagnosticOutput)) {
                    echo "Sélectionnez une sonde ci-dessus et cliquez sur « Interroger l'hôte » pour afficher le retour système en direct.\n";
                } else {
                    foreach ($diagnosticOutput as $line) {
                        echo Security::e((string) $line) . "\n";
                    }
                }
            ?></code></pre>
        </div>
    </section>
</div>