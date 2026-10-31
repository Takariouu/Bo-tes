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
    <section class="admin-directory admin-directory--forbidden">
        <h1 class="admin-directory__title">Accès refusé</h1>
        <p class="admin-directory__message">Ce répertoire global est réservé aux Administrateurs.</p>
    </section>
    <?php
    return;
}

$db              = $db ?? Database::getInstance();
$serverManager   = new ServerManager($db);
$resourceManager = new ResourceManager($db);
$currentUserId   = Auth::getUserId() ?? 0;
$csrfToken       = Security::generateCsrfToken();

$feedbackMessage = null;
$feedbackError   = null;

// Traitement des actions rapides de modération (Bannir/Débannir, Basculer Offre, Suspendre Ressource)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 35) ?? '';

    if (!Security::verifyCsrfToken($postCsrf)) {
        $feedbackError = 'Jeton CSRF invalide.';
    } else {
        try {
            if ($action === 'toggle_ban_user') {
                $targetUid = Security::validatePositiveInt($_POST['user_id'] ?? null);
                $newBanVal = isset($_POST['ban_value']) && $_POST['ban_value'] === '1' ? 1 : 0;

                if ($targetUid === null || $targetUid === $currentUserId) {
                    $feedbackError = 'Vous ne pouvez pas modifier le statut de bannissement de votre propre compte.';
                } else {
                    $chkRole = $db->prepare('SELECT role, pseudo FROM UTILISATEUR WHERE id_utilisateur = :uid LIMIT 1');
                    $chkRole->execute([':uid' => $targetUid]);
                    $targetUser = $chkRole->fetch(PDO::FETCH_ASSOC);

                    if ($targetUser && strtoupper((string) $targetUser['role']) === Auth::ROLE_SUPER_ADMIN && !Auth::isSuperAdmin()) {
                        $feedbackError = 'Impossible de bannir un Super-Administrateur.';
                    } elseif ($targetUser) {
                        $upd = $db->prepare('UPDATE UTILISATEUR SET est_banni = :ban WHERE id_utilisateur = :uid');
                        $upd->execute([':ban' => $newBanVal, ':uid' => $targetUid]);

                        if ($newBanVal === 1) {
                            $db->prepare("UPDATE SERVEUR SET statut = 'HORS_LIGNE', joueurs_connectes = 0 WHERE id_proprietaire = :uid")
                               ->execute([':uid' => $targetUid]);
                        }

                        $feedbackMessage = sprintf(
                            'Le compte "%s" (#%d) a été %s.',
                            (string) $targetUser['pseudo'],
                            $targetUid,
                            $newBanVal === 1 ? 'banni et ses serveurs ont été arrêtés' : 'réactivé'
                        );
                    }
                }
            } elseif ($action === 'toggle_tier_user') {
                $targetUid = Security::validatePositiveInt($_POST['user_id'] ?? null);
                $newTier   = ($_POST['new_tier'] ?? '') === Auth::ACCOUNT_PREMIUM
                    ? Auth::ACCOUNT_PREMIUM
                    : Auth::ACCOUNT_STANDARD;
                $newRam    = $newTier === Auth::ACCOUNT_PREMIUM ? 4096 : 2048;

                if ($targetUid !== null) {
                    $upd = $db->prepare('UPDATE UTILISATEUR SET type_compte = :tier, max_ram_allouable = :ram WHERE id_utilisateur = :uid');
                    $upd->execute([
                        ':tier' => $newTier,
                        ':ram'  => $newRam,
                        ':uid'  => $targetUid,
                    ]);
                    $feedbackMessage = sprintf('Offre de l\'utilisateur #%d passée en %s (%d Mo max).', $targetUid, $newTier, $newRam);
                }
            } elseif ($action === 'toggle_resource_status') {
                $targetRid = Security::validatePositiveInt($_POST['resource_id'] ?? null);
                $newStatus = ($_POST['new_status'] ?? '') === 'SUSPENDU' ? 'SUSPENDU' : 'VALIDE';

                if ($targetRid !== null) {
                    $upd = $db->prepare('UPDATE RESSOURCE SET statut_moderation = :st WHERE id_ressource = :rid');
                    $upd->execute([':st' => $newStatus, ':rid' => $targetRid]);
                    $feedbackMessage = sprintf('Statut de la ressource #%d mis à jour (%s).', $targetRid, $newStatus);
                }
            }
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][AdminUtilisateurs] Erreur POST : %s', $e->getMessage()));
            $feedbackError = 'Erreur lors de la mise à jour en base de données.';
        }
    }
}

// Paramètres de filtrage du répertoire global
$activeTab    = Security::validateString($_GET['tab'] ?? 'utilisateurs', 3, 20) ?? 'utilisateurs';
if (!in_array($activeTab, ['utilisateurs', 'serveurs', 'ressources'], true)) {
    $activeTab = 'utilisateurs';
}

$searchQuery  = Security::validateString($_GET['q'] ?? '', 0, 100) ?? '';
$statusFilter = strtoupper(Security::validateString($_GET['status'] ?? 'ALL', 1, 20) ?? 'ALL');

$usersList     = [];
$serversList   = [];
$resourcesList = [];

try {
    // 1. Requête Utilisateurs
    $uWhere  = ['1 = 1'];
    $uParams = [];
    if ($searchQuery !== '') {
        $uWhere[]            = '(u.pseudo LIKE :q_pseudo OR u.email LIKE :q_email)';
        $uParams[':q_pseudo'] = '%' . $searchQuery . '%';
        $uParams[':q_email']  = '%' . $searchQuery . '%';
    }
    if ($statusFilter === 'STANDARD') {
        $uWhere[] = "UPPER(u.type_compte) = 'STANDARD' AND u.est_banni = 0";
    } elseif ($statusFilter === 'PREMIUM') {
        $uWhere[] = "UPPER(u.type_compte) = 'PREMIUM' AND u.est_banni = 0";
    } elseif ($statusFilter === 'BANNI') {
        $uWhere[] = 'u.est_banni = 1';
    }

    $uSql = '
        SELECT 
            u.id_utilisateur,
            u.pseudo,
            u.email,
            u.role,
            u.type_compte,
            u.max_ram_allouable,
            u.est_banni,
            u.date_inscription,
            COUNT(DISTINCT s.id_serveur) AS nb_serveurs,
            COUNT(DISTINCT r.id_ressource) AS nb_ressources
        FROM UTILISATEUR u
        LEFT JOIN SERVEUR s ON s.id_proprietaire = u.id_utilisateur
        LEFT JOIN RESSOURCE r ON r.id_auteur = u.id_utilisateur
        WHERE ' . implode(' AND ', $uWhere) . '
        GROUP BY u.id_utilisateur, u.pseudo, u.email, u.role, u.type_compte, u.max_ram_allouable, u.est_banni, u.date_inscription
        ORDER BY u.date_inscription DESC
        LIMIT 100
    ';
    $uStmt = $db->prepare($uSql);
    $uStmt->execute($uParams);
    $usersList = $uStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Requête Serveurs
    $sWhere  = ['1 = 1'];
    $sParams = [];
    if ($searchQuery !== '') {
        $sWhere[]           = '(s.nom_serveur LIKE :q_srv OR u.pseudo LIKE :q_owner)';
        $sParams[':q_srv']   = '%' . $searchQuery . '%';
        $sParams[':q_owner'] = '%' . $searchQuery . '%';
    }
    if ($statusFilter === 'EN_LIGNE') {
        $sWhere[] = "s.statut = 'EN_LIGNE'";
    } elseif ($statusFilter === 'HORS_LIGNE') {
        $sWhere[] = "s.statut != 'EN_LIGNE'";
    }

    $sSql = '
        SELECT 
            s.id_serveur,
            s.id_proprietaire,
            s.nom_serveur,
            s.port,
            s.version_mc,
            s.mode_jeu,
            s.statut,
            s.ram_allouee,
            s.joueurs_connectes,
            s.slots_max,
            s.est_public,
            u.pseudo AS proprietaire_pseudo
        FROM SERVEUR s
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = s.id_proprietaire
        WHERE ' . implode(' AND ', $sWhere) . '
        ORDER BY s.statut = \'EN_LIGNE\' DESC, s.id_serveur DESC
        LIMIT 100
    ';
    $sStmt = $db->prepare($sSql);
    $sStmt->execute($sParams);
    $serversList = $sStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Requête Ressources
    $rWhere  = ['1 = 1'];
    $rParams = [];
    if ($searchQuery !== '') {
        $rWhere[]            = '(r.nom_ressource LIKE :q_res OR u.pseudo LIKE :q_author)';
        $rParams[':q_res']    = '%' . $searchQuery . '%';
        $rParams[':q_author'] = '%' . $searchQuery . '%';
    }
    if (in_array($statusFilter, ['MOD', 'PLUGIN', 'MOD_PACK'], true)) {
        $rWhere[]         = 'UPPER(r.type_ressource) = :r_type';
        $rParams[':r_type'] = $statusFilter;
    } elseif ($statusFilter === 'SUSPENDU') {
        $rWhere[] = "r.statut_moderation = 'SUSPENDU'";
    }

    $rSql = '
        SELECT 
            r.id_ressource,
            r.id_auteur,
            r.nom_ressource,
            r.type_ressource,
            r.version_compatible,
            r.nb_telechargements,
            r.statut_moderation,
            r.date_publication,
            u.pseudo AS auteur_pseudo
        FROM RESSOURCE r
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = r.id_auteur
        WHERE ' . implode(' AND ', $rWhere) . '
        ORDER BY r.date_publication DESC
        LIMIT 100
    ';
    $rStmt = $db->prepare($rSql);
    $rStmt->execute($rParams);
    $resourcesList = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][AdminUtilisateurs] Erreur requêtes répertoire : %s', $e->getMessage()));
}
?>
<div class="admin-directory">
    <header class="admin-directory__header">
        <div class="admin-directory__heading">
            <p class="admin-directory__eyebrow">Administration &amp; Gestion de Parc</p>
            <h1 class="admin-directory__title">Répertoire Global : Utilisateurs, Serveurs &amp; Ressources</h1>
            <p class="admin-directory__subtitle">
                Recherchez, filtrez et administrez directement chaque compte, instance Minecraft ou création hébergée sur Boötes.
            </p>
        </div>

        <nav class="admin-directory__tabs" aria-label="Sélection du répertoire">
            <ul class="admin-directory__tab-list">
                <li class="admin-directory__tab-item">
                    <a href="/admin/utilisateurs?tab=utilisateurs"
                       class="admin-directory__tab-link <?= $activeTab === 'utilisateurs' ? 'admin-directory__tab-link--active' : '' ?>">
                        Comptes Utilisateurs (<?= count($usersList) ?>)
                    </a>
                </li>
                <li class="admin-directory__tab-item">
                    <a href="/admin/utilisateurs?tab=serveurs"
                       class="admin-directory__tab-link <?= $activeTab === 'serveurs' ? 'admin-directory__tab-link--active' : '' ?>">
                        Serveurs Minecraft (<?= count($serversList) ?>)
                    </a>
                </li>
                <li class="admin-directory__tab-item">
                    <a href="/admin/utilisateurs?tab=ressources"
                       class="admin-directory__tab-link <?= $activeTab === 'ressources' ? 'admin-directory__tab-link--active' : '' ?>">
                        Mods, Plugins &amp; Modpacks (<?= count($resourcesList) ?>)
                    </a>
                </li>
            </ul>
        </nav>
    </header>

    <?php if ($feedbackMessage !== null): ?>
        <div class="admin-directory__alert admin-directory__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="admin-directory__alert admin-directory__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <!-- BARRE DE RECHERCHE ET FILTRES DU RÉPERTOIRE -->
    <section class="admin-directory__filters" aria-label="Filtres de recherche">
        <form action="/admin/utilisateurs" method="get" class="directory-filter-form" role="search">
            <input type="hidden" name="tab" value="<?= Security::e($activeTab) ?>">

            <div class="directory-filter-form__field">
                <label for="dir-search" class="directory-filter-form__label">Rechercher (pseudo, e-mail, nom de serveur ou ressource)</label>
                <input
                    type="search"
                    id="dir-search"
                    name="q"
                    value="<?= Security::e($searchQuery) ?>"
                    placeholder="Saisissez un terme..."
                    maxlength="100"
                    class="directory-filter-form__input"
                >
            </div>

            <div class="directory-filter-form__field">
                <label for="dir-status" class="directory-filter-form__label">Filtre de statut / type</label>
                <select id="dir-status" name="status" class="directory-filter-form__select">
                    <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : '' ?>>Tous les statuts</option>
                    <optgroup label="Filtres Comptes Utilisateurs">
                        <option value="STANDARD" <?= $statusFilter === 'STANDARD' ? 'selected' : '' ?>>Compte Standard (2 Go)</option>
                        <option value="PREMIUM" <?= $statusFilter === 'PREMIUM' ? 'selected' : '' ?>>Compte Premium (Jusqu'à 6 Go)</option>
                        <option value="BANNI" <?= $statusFilter === 'BANNI' ? 'selected' : '' ?>>Compte Banni</option>
                    </optgroup>
                    <optgroup label="Filtres Serveurs">
                        <option value="EN_LIGNE" <?= $statusFilter === 'EN_LIGNE' ? 'selected' : '' ?>>Serveur En ligne</option>
                        <option value="HORS_LIGNE" <?= $statusFilter === 'HORS_LIGNE' ? 'selected' : '' ?>>Serveur Hors ligne</option>
                    </optgroup>
                    <optgroup label="Filtres Ressources">
                        <option value="MOD" <?= $statusFilter === 'MOD' ? 'selected' : '' ?>>Mods uniquement</option>
                        <option value="PLUGIN" <?= $statusFilter === 'PLUGIN' ? 'selected' : '' ?>>Plugins uniquement</option>
                        <option value="MOD_PACK" <?= $statusFilter === 'MOD_PACK' ? 'selected' : '' ?>>Modpacks uniquement</option>
                        <option value="SUSPENDU" <?= $statusFilter === 'SUSPENDU' ? 'selected' : '' ?>>Ressources Suspendues</option>
                    </optgroup>
                </select>
            </div>

            <div class="directory-filter-form__actions">
                <button type="submit" class="directory-filter-form__submit">Appliquer</button>
                <a href="/admin/utilisateurs?tab=<?= Security::e($activeTab) ?>" class="directory-filter-form__reset">Réinitialiser</a>
            </div>
        </form>
    </section>

    <!-- ONGLET 1 : COMPTES UTILISATEURS -->
    <?php if ($activeTab === 'utilisateurs'): ?>
        <section class="admin-directory__section" aria-labelledby="users-table-heading">
            <h2 id="users-table-heading" class="admin-directory__section-title">
                Comptes Utilisateurs (<?= count($usersList) ?>)
            </h2>

            <?php if (empty($usersList)): ?>
                <p class="admin-directory__empty">Aucun compte utilisateur ne correspond à votre recherche.</p>
            <?php else: ?>
                <table class="directory-table">
                    <caption class="directory-table__caption">Liste des membres inscrits sur Boötes</caption>
                    <thead class="directory-table__head">
                        <tr class="directory-table__row">
                            <th scope="col" class="directory-table__cell">ID</th>
                            <th scope="col" class="directory-table__cell">Pseudo &amp; E-mail</th>
                            <th scope="col" class="directory-table__cell">Rôle</th>
                            <th scope="col" class="directory-table__cell">Offre &amp; Quota RAM</th>
                            <th scope="col" class="directory-table__cell">Statut</th>
                            <th scope="col" class="directory-table__cell">Serveurs / Créations</th>
                            <th scope="col" class="directory-table__cell">Actions de Gestion</th>
                        </tr>
                    </thead>
                    <tbody class="directory-table__body">
                        <?php foreach ($usersList as $u): ?>
                            <?php
                            $uid        = (int) $u['id_utilisateur'];
                            $isBanned   = (int) $u['est_banni'] === 1;
                            $isPremium  = strtoupper((string) $u['type_compte']) === Auth::ACCOUNT_PREMIUM;
                            ?>
                            <tr class="directory-table__row">
                                <td class="directory-table__cell">#<?= $uid ?></td>
                                <th scope="row" class="directory-table__cell">
                                    <strong><?= Security::e((string) $u['pseudo']) ?></strong>
                                    <br>
                                    <small><?= Security::e((string) $u['email']) ?></small>
                                </th>
                                <td class="directory-table__cell"><?= Security::e((string) $u['role']) ?></td>
                                <td class="directory-table__cell">
                                    <?= Security::e((string) $u['type_compte']) ?> (<?= (int) $u['max_ram_allouable'] ?> Mo)
                                </td>
                                <td class="directory-table__cell">
                                    <span class="directory-table__status">
                                        <?= $isBanned ? 'BANNI' : 'ACTIF' ?>
                                    </span>
                                </td>
                                <td class="directory-table__cell">
                                    <?= (int) $u['nb_serveurs'] ?> srv &bull; <?= (int) $u['nb_ressources'] ?> ress.
                                </td>
                                <td class="directory-table__cell directory-table__cell--actions">
                                    <a href="/user/compte?id=<?= $uid ?>" class="directory-table__btn">Gérer le profil</a>

                                    <form action="/admin/utilisateurs?tab=utilisateurs" method="post" class="directory-table__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="toggle_tier_user">
                                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                                        <input type="hidden" name="new_tier" value="<?= $isPremium ? 'STANDARD' : 'PREMIUM' ?>">
                                        <button type="submit" class="directory-table__btn">
                                            <?= $isPremium ? 'Passer Standard' : 'Passer Premium' ?>
                                        </button>
                                    </form>

                                    <?php if ($uid !== $currentUserId): ?>
                                        <form action="/admin/utilisateurs?tab=utilisateurs" method="post" class="directory-table__inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle_ban_user">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="hidden" name="ban_value" value="<?= $isBanned ? '0' : '1' ?>">
                                            <button type="submit" class="directory-table__btn directory-table__btn--danger">
                                                <?= $isBanned ? 'Débannir' : 'Bannir' ?>
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

    <!-- ONGLET 2 : SERVEURS MINECRAFT -->
    <?php elseif ($activeTab === 'serveurs'): ?>
        <section class="admin-directory__section" aria-labelledby="servers-table-heading">
            <h2 id="servers-table-heading" class="admin-directory__section-title">
                Serveurs Minecraft hébergés (<?= count($serversList) ?>)
            </h2>

            <?php if (empty($serversList)): ?>
                <p class="admin-directory__empty">Aucun serveur ne correspond aux critères.</p>
            <?php else: ?>
                <table class="directory-table">
                    <caption class="directory-table__caption">Ensemble des serveurs Minecraft enregistrés</caption>
                    <thead class="directory-table__head">
                        <tr class="directory-table__row">
                            <th scope="col" class="directory-table__cell">ID</th>
                            <th scope="col" class="directory-table__cell">Nom du serveur</th>
                            <th scope="col" class="directory-table__cell">Propriétaire</th>
                            <th scope="col" class="directory-table__cell">Version &amp; Mode</th>
                            <th scope="col" class="directory-table__cell">Port &amp; RAM</th>
                            <th scope="col" class="directory-table__cell">Statut</th>
                            <th scope="col" class="directory-table__cell">Accès Direct</th>
                        </tr>
                    </thead>
                    <tbody class="directory-table__body">
                        <?php foreach ($serversList as $srv): ?>
                            <?php $sid = (int) $srv['id_serveur']; ?>
                            <tr class="directory-table__row">
                                <td class="directory-table__cell">#<?= $sid ?></td>
                                <th scope="row" class="directory-table__cell"><?= Security::e((string) $srv['nom_serveur']) ?></th>
                                <td class="directory-table__cell">
                                    <a href="/user/compte?id=<?= (int) $srv['id_proprietaire'] ?>">
                                        <?= Security::e((string) $srv['proprietaire_pseudo']) ?>
                                    </a>
                                </td>
                                <td class="directory-table__cell">
                                    MC <?= Security::e((string) $srv['version_mc']) ?> (<?= Security::e((string) $srv['mode_jeu']) ?>)
                                </td>
                                <td class="directory-table__cell">
                                    <code>:<?= (int) $srv['port'] ?></code> &bull; <?= (int) $srv['ram_allouee'] ?> Mo
                                </td>
                                <td class="directory-table__cell"><?= Security::e((string) $srv['statut']) ?></td>
                                <td class="directory-table__cell directory-table__cell--actions">
                                    <a href="/serveur/dashboard?id=<?= $sid ?>" class="directory-table__btn">Dashboard</a>
                                    <a href="/serveur/console?id=<?= $sid ?>" class="directory-table__btn">Console</a>
                                    <a href="/serveur/logs?id=<?= $sid ?>" class="directory-table__btn">Logs</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

    <!-- ONGLET 3 : RESSOURCES (MODS, PLUGINS, MODPACKS) -->
    <?php else: ?>
        <section class="admin-directory__section" aria-labelledby="resources-table-heading">
            <h2 id="resources-table-heading" class="admin-directory__section-title">
                Ressources du Catalogue (<?= count($resourcesList) ?>)
            </h2>

            <?php if (empty($resourcesList)): ?>
                <p class="admin-directory__empty">Aucune ressource ne correspond aux critères.</p>
            <?php else: ?>
                <table class="directory-table">
                    <caption class="directory-table__caption">Mods, Plugins et Modpacks publiés sur Boötes</caption>
                    <thead class="directory-table__head">
                        <tr class="directory-table__row">
                            <th scope="col" class="directory-table__cell">ID</th>
                            <th scope="col" class="directory-table__cell">Nom</th>
                            <th scope="col" class="directory-table__cell">Type</th>
                            <th scope="col" class="directory-table__cell">Auteur</th>
                            <th scope="col" class="directory-table__cell">Version MC</th>
                            <th scope="col" class="directory-table__cell">Téléch.</th>
                            <th scope="col" class="directory-table__cell">Statut</th>
                            <th scope="col" class="directory-table__cell">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="directory-table__body">
                        <?php foreach ($resourcesList as $res): ?>
                            <?php
                            $rid         = (int) $res['id_ressource'];
                            $isSuspended = strtoupper((string) $res['statut_moderation']) === 'SUSPENDU';
                            ?>
                            <tr class="directory-table__row">
                                <td class="directory-table__cell">#<?= $rid ?></td>
                                <th scope="row" class="directory-table__cell"><?= Security::e((string) $res['nom_ressource']) ?></th>
                                <td class="directory-table__cell"><?= Security::e((string) $res['type_ressource']) ?></td>
                                <td class="directory-table__cell">
                                    <a href="/user/compte?id=<?= (int) $res['id_auteur'] ?>">
                                        <?= Security::e((string) $res['auteur_pseudo']) ?>
                                    </a>
                                </td>
                                <td class="directory-table__cell">MC <?= Security::e((string) $res['version_compatible']) ?></td>
                                <td class="directory-table__cell"><?= (int) $res['nb_telechargements'] ?></td>
                                <td class="directory-table__cell"><?= Security::e((string) $res['statut_moderation']) ?></td>
                                <td class="directory-table__cell directory-table__cell--actions">
                                    <a href="/ressource-detail?id=<?= $rid ?>" class="directory-table__btn">Fiche</a>
                                    <a href="/user/createur-edition?id=<?= $rid ?>" class="directory-table__btn">Éditer</a>
                                    <a href="/user/createur-statistiques?id=<?= $rid ?>" class="directory-table__btn">Stats</a>

                                    <form action="/admin/utilisateurs?tab=ressources" method="post" class="directory-table__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="toggle_resource_status">
                                        <input type="hidden" name="resource_id" value="<?= $rid ?>">
                                        <input type="hidden" name="new_status" value="<?= $isSuspended ? 'VALIDE' : 'SUSPENDU' ?>">
                                        <button type="submit" class="directory-table__btn">
                                            <?= $isSuspended ? 'Réactiver' : 'Suspendre' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>