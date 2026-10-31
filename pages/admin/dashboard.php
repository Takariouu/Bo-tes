<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/ServerManager.php';
require_once __DIR__ . '/../../src/ResourceManager.php';

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\ResourceManager;
use Bootes\Src\Security;
use Bootes\Src\ServerManager;

if (!Auth::isAdmin()) {
    http_response_code(403);
    ?>
    <section class="admin-dashboard admin-dashboard--forbidden">
        <h1 class="admin-dashboard__title">Accès refusé</h1>
        <p class="admin-dashboard__message">Cet espace de supervision est strictement réservé aux Administrateurs Boötes.</p>
        <a href="/home" class="admin-dashboard__btn">Retour à l'accueil</a>
    </section>
    <?php
    return;
}

$db            = $db ?? Database::getInstance();
$serverManager = new ServerManager($db);

$stats = [
    'total_users'       => 0,
    'premium_users'     => 0,
    'banned_users'      => 0,
    'total_servers'     => 0,
    'online_servers'    => 0,
    'offline_servers'   => 0,
    'online_players'    => 0,
    'total_resources'   => 0,
    'count_mods'        => 0,
    'count_plugins'     => 0,
    'count_modpacks'    => 0,
    'total_downloads'   => 0,
    'pending_reports'   => 0,
    'open_tickets'      => 0,
    'allocated_ram_mb'  => $serverManager->getAllocatedRamMb(),
    'available_host_mb' => $serverManager->getHostAvailableRamMb(),
];

try {
    $userStmt = $db->query("
        SELECT 
            COUNT(*) AS total_users,
            COALESCE(SUM(CASE WHEN UPPER(type_compte) = 'PREMIUM' THEN 1 ELSE 0 END), 0) AS premium_users,
            COALESCE(SUM(CASE WHEN est_banni = 1 THEN 1 ELSE 0 END), 0) AS banned_users
        FROM UTILISATEUR
    ");
    if ($userStmt !== false) {
        $uRow = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($uRow)) {
            $stats['total_users']   = (int) $uRow['total_users'];
            $stats['premium_users'] = (int) $uRow['premium_users'];
            $stats['banned_users']  = (int) $uRow['banned_users'];
        }
    }

    $srvStmt = $db->query("
        SELECT 
            COUNT(*) AS total_servers,
            COALESCE(SUM(CASE WHEN statut = 'EN_LIGNE' THEN 1 ELSE 0 END), 0) AS online_servers,
            COALESCE(SUM(CASE WHEN statut != 'EN_LIGNE' THEN 1 ELSE 0 END), 0) AS offline_servers,
            COALESCE(SUM(CASE WHEN statut = 'EN_LIGNE' THEN joueurs_connectes ELSE 0 END), 0) AS online_players
        FROM SERVEUR
    ");
    if ($srvStmt !== false) {
        $sRow = $srvStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($sRow)) {
            $stats['total_servers']   = (int) $sRow['total_servers'];
            $stats['online_servers']  = (int) $sRow['online_servers'];
            $stats['offline_servers'] = (int) $sRow['offline_servers'];
            $stats['online_players']  = (int) $sRow['online_players'];
        }
    }

    $resStmt = $db->query("
        SELECT 
            COUNT(*) AS total_resources,
            COALESCE(SUM(CASE WHEN UPPER(type_ressource) = 'MOD' THEN 1 ELSE 0 END), 0) AS count_mods,
            COALESCE(SUM(CASE WHEN UPPER(type_ressource) = 'PLUGIN' THEN 1 ELSE 0 END), 0) AS count_plugins,
            COALESCE(SUM(CASE WHEN UPPER(type_ressource) IN ('MOD_PACK', 'MODPACK') THEN 1 ELSE 0 END), 0) AS count_modpacks,
            COALESCE(SUM(nb_telechargements), 0) AS total_downloads
        FROM RESSOURCE
    ");
    if ($resStmt !== false) {
        $rRow = $resStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($rRow)) {
            $stats['total_resources'] = (int) $rRow['total_resources'];
            $stats['count_mods']      = (int) $rRow['count_mods'];
            $stats['count_plugins']   = (int) $rRow['count_plugins'];
            $stats['count_modpacks']  = (int) $rRow['count_modpacks'];
            $stats['total_downloads'] = (int) $rRow['total_downloads'];
        }
    }

    $repStmt = $db->query("SELECT COUNT(*) FROM SIGNALEMENT WHERE statut = 'EN_ATTENTE'");
    if ($repStmt !== false) {
        $stats['pending_reports'] = (int) $repStmt->fetchColumn();
    }

    $tktStmt = $db->query("SELECT COUNT(*) FROM TICKET_SUPPORT WHERE statut != 'FERME'");
    if ($tktStmt !== false) {
        $stats['open_tickets'] = (int) $tktStmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][AdminDashboard] Erreur agrégation KPIs : %s', $e->getMessage()));
}

$recentServers = [];
$openTickets   = [];

try {
    $recSrvStmt = $db->query('
        SELECT s.id_serveur, s.nom_serveur, s.statut, s.ram_allouee, s.joueurs_connectes, s.slots_max, s.port, u.pseudo AS proprietaire
        FROM SERVEUR s
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = s.id_proprietaire
        ORDER BY s.statut = \'EN_LIGNE\' DESC, s.ram_allouee DESC
        LIMIT 8
    ');
    if ($recSrvStmt !== false) {
        $recentServers = $recSrvStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $tktListStmt = $db->query("
        SELECT t.id_ticket, t.id_utilisateur, t.sujet, t.categorie, t.statut, t.date_creation, u.pseudo
        FROM TICKET_SUPPORT t
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = t.id_utilisateur
        WHERE t.statut != 'FERME'
        ORDER BY t.date_creation ASC
        LIMIT 8
    ");
    if ($tktListStmt !== false) {
        $openTickets = $tktListStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][AdminDashboard] Erreur chargement listes : %s', $e->getMessage()));
}

$ramUsagePct = round(($stats['allocated_ram_mb'] / ServerManager::HOST_MAX_MC_RAM_MB) * 100, 1);
?>
<div class="admin-dashboard">
    <header class="admin-dashboard__header">
        <div class="admin-dashboard__heading">
            <p class="admin-dashboard__eyebrow">Centre de Supervision Globale — Rôle <?= Security::e(Auth::getRole()) ?></p>
            <h1 class="admin-dashboard__title">Tableau de bord Administrateur Boötes</h1>
            <p class="admin-dashboard__subtitle">
                Vue synthétique de l'infrastructure Linux Mint (Plafond 6 Go allouables sur 8 Go physiques), de la base <code>HEBERGEUR_MC</code> et de l'activité communautaire.
            </p>
        </div>

        <nav class="admin-dashboard__quick-actions" aria-label="Raccourcis d'administration">
            <a href="/admin/hardware" class="admin-dashboard__btn admin-dashboard__btn--primary">Supervision Hardware</a>
            <a href="/admin/utilisateurs" class="admin-dashboard__btn">Répertoire Global</a>
            <a href="/admin/signalements" class="admin-dashboard__btn">
                Signalements (<?= $stats['pending_reports'] ?>)
            </a>
            <?php if (Auth::isSuperAdmin()): ?>
                <a href="/admin/super-admin" class="admin-dashboard__btn admin-dashboard__btn--super">Espace Super-Admin</a>
            <?php endif; ?>
        </nav>
    </header>

    <!-- INDICATEURS GLOBAUX DE LA PLATEFORME -->
    <section class="admin-dashboard__kpis" aria-labelledby="global-kpis-title">
        <h2 id="global-kpis-title" class="admin-dashboard__section-title">Métriques en temps réel</h2>

        <dl class="kpi-grid">
            <div class="kpi-card">
                <dt class="kpi-card__label">Utilisateurs inscrits</dt>
                <dd class="kpi-card__value"><?= $stats['total_users'] ?></dd>
                <dd class="kpi-card__detail">
                    <?= $stats['premium_users'] ?> Premium &bull; <?= $stats['banned_users'] ?> banni(s)
                </dd>
            </div>

            <div class="kpi-card">
                <dt class="kpi-card__label">Serveurs Actifs vs Éteints</dt>
                <dd class="kpi-card__value">
                    <?= $stats['online_servers'] ?> en ligne / <?= $stats['offline_servers'] ?> éteints
                </dd>
                <dd class="kpi-card__detail">
                    Total : <?= $stats['total_servers'] ?> instances (<?= $stats['online_players'] ?> joueurs connectés)
                </dd>
            </div>

            <div class="kpi-card">
                <dt class="kpi-card__label">Catalogue Hébergé</dt>
                <dd class="kpi-card__value"><?= $stats['total_resources'] ?> ressources</dd>
                <dd class="kpi-card__detail">
                    <?= $stats['count_mods'] ?> Mods &bull; <?= $stats['count_plugins'] ?> Plugins &bull; <?= $stats['count_modpacks'] ?> Modpacks (<?= $stats['total_downloads'] ?> téléch.)
                </dd>
            </div>

            <div class="kpi-card">
                <dt class="kpi-card__label">RAM Allouée aux Serveurs MC</dt>
                <dd class="kpi-card__value">
                    <?= $stats['allocated_ram_mb'] ?> Mo / <?= ServerManager::HOST_MAX_MC_RAM_MB ?> Mo
                </dd>
                <dd class="kpi-card__detail">
                    <meter min="0" max="<?= ServerManager::HOST_MAX_MC_RAM_MB ?>" high="5120" value="<?= $stats['allocated_ram_mb'] ?>">
                        <?= Security::e((string) $ramUsagePct) ?> %
                    </meter>
                    <span><?= Security::e((string) $ramUsagePct) ?> % du quota MC utilisé</span>
                </dd>
            </div>

            <div class="kpi-card">
                <dt class="kpi-card__label">Signalements en attente</dt>
                <dd class="kpi-card__value"><?= $stats['pending_reports'] ?></dd>
                <dd class="kpi-card__detail">
                    <a href="/admin/signalements" class="kpi-card__link">Traiter la file de modération</a>
                </dd>
            </div>

            <div class="kpi-card">
                <dt class="kpi-card__label">Tickets Support ouverts</dt>
                <dd class="kpi-card__value"><?= $stats['open_tickets'] ?></dd>
                <dd class="kpi-card__detail">
                    <a href="#open-tickets-section" class="kpi-card__link">Consulter les demandes d'assistance</a>
                </dd>
            </div>
        </dl>
    </section>

    <div class="admin-dashboard__grid">
        <!-- ÉTAT DES SERVEURS MINECRAFT -->
        <section class="admin-dashboard__panel" aria-labelledby="active-servers-heading">
            <header class="admin-dashboard__panel-header">
                <h2 id="active-servers-heading" class="admin-dashboard__panel-title">
                    Supervision des instances Minecraft
                </h2>
                <a href="/admin/utilisateurs?tab=serveurs" class="admin-dashboard__panel-link">Voir tous les serveurs</a>
            </header>

            <?php if (empty($recentServers)): ?>
                <p class="admin-dashboard__empty">Aucun serveur enregistré sur la plateforme.</p>
            <?php else: ?>
                <table class="admin-table">
                    <caption class="admin-table__caption">Instances triées par état d'activité et consommation mémoire</caption>
                    <thead class="admin-table__head">
                        <tr class="admin-table__row">
                            <th scope="col" class="admin-table__cell">ID</th>
                            <th scope="col" class="admin-table__cell">Serveur</th>
                            <th scope="col" class="admin-table__cell">Propriétaire</th>
                            <th scope="col" class="admin-table__cell">Statut</th>
                            <th scope="col" class="admin-table__cell">RAM</th>
                            <th scope="col" class="admin-table__cell">Joueurs</th>
                            <th scope="col" class="admin-table__cell">Action</th>
                        </tr>
                    </thead>
                    <tbody class="admin-table__body">
                        <?php foreach ($recentServers as $srv): ?>
                            <?php $srvId = (int) $srv['id_serveur']; ?>
                            <tr class="admin-table__row">
                                <td class="admin-table__cell">#<?= $srvId ?></td>
                                <th scope="row" class="admin-table__cell">
                                    <?= Security::e((string) $srv['nom_serveur']) ?>
                                    <small>(:<?= (int) $srv['port'] ?>)</small>
                                </th>
                                <td class="admin-table__cell"><?= Security::e((string) $srv['proprietaire']) ?></td>
                                <td class="admin-table__cell">
                                    <span class="admin-table__badge"><?= Security::e((string) $srv['statut']) ?></span>
                                </td>
                                <td class="admin-table__cell"><?= (int) $srv['ram_allouee'] ?> Mo</td>
                                <td class="admin-table__cell"><?= (int) $srv['joueurs_connectes'] ?> / <?= (int) $srv['slots_max'] ?></td>
                                <td class="admin-table__cell">
                                    <a href="/serveur/dashboard?id=<?= $srvId ?>" class="admin-table__action-link">Contrôler</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <!-- FILE DES TICKETS SUPPORT OUVERTS -->
        <section id="open-tickets-section" class="admin-dashboard__panel" aria-labelledby="tickets-queue-heading">
            <header class="admin-dashboard__panel-header">
                <h2 id="tickets-queue-heading" class="admin-dashboard__panel-title">
                    Tickets de support en attente (<?= count($openTickets) ?>)
                </h2>
            </header>

            <?php if (empty($openTickets)): ?>
                <p class="admin-dashboard__empty">Aucun ticket de support ouvert actuellement.</p>
            <?php else: ?>
                <table class="admin-table">
                    <caption class="admin-table__caption">Demandes d'assistance des utilisateurs</caption>
                    <thead class="admin-table__head">
                        <tr class="admin-table__row">
                            <th scope="col" class="admin-table__cell">Ticket</th>
                            <th scope="col" class="admin-table__cell">Utilisateur</th>
                            <th scope="col" class="admin-table__cell">Sujet</th>
                            <th scope="col" class="admin-table__cell">Catégorie</th>
                            <th scope="col" class="admin-table__cell">Statut</th>
                            <th scope="col" class="admin-table__cell">Action</th>
                        </tr>
                    </thead>
                    <tbody class="admin-table__body">
                        <?php foreach ($openTickets as $tkt): ?>
                            <tr class="admin-table__row">
                                <td class="admin-table__cell">#<?= (int) $tkt['id_ticket'] ?></td>
                                <td class="admin-table__cell"><?= Security::e((string) $tkt['pseudo']) ?></td>
                                <th scope="row" class="admin-table__cell"><?= Security::e((string) $tkt['sujet']) ?></th>
                                <td class="admin-table__cell"><?= Security::e((string) $tkt['categorie']) ?></td>
                                <td class="admin-table__cell"><?= Security::e((string) $tkt['statut']) ?></td>
                                <td class="admin-table__cell">
                                    <a href="/user/compte?id=<?= (int) $tkt['id_utilisateur'] ?>#section-tickets" class="admin-table__action-link">
                                        Répondre
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</div>