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
    : Security::validatePositiveInt($_GET['id'] ?? $_POST['server_id'] ?? null);
$currentUserId = Auth::getUserId();

if ($serverId === null || $currentUserId === null || !Auth::canManageServer($currentUserId, $serverId, $db)) {
    http_response_code(403);
    ?>
    <section class="server-access server-access--forbidden">
        <h1 class="server-access__title">Accès refusé</h1>
    </section>
    <?php
    return;
}

$server = $serverManager->getServerById($serverId);
if ($server === null) {
    ?>
    <section class="server-access server-access--not-found">
        <h1 class="server-access__title">Serveur introuvable</h1>
    </section>
    <?php
    return;
}

$isOwnerOrGlobalAdmin = ((int) $server['id_proprietaire'] === $currentUserId) || Auth::isAdmin();
$csrfToken            = Security::generateCsrfToken();
$feedbackMessage      = null;
$feedbackError        = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 35) ?? '';

    if (!Security::verifyCsrfToken($postCsrf)) {
        $feedbackError = 'Jeton CSRF invalide.';
    } else {
        switch ($action) {
            case 'add_coadmin':
                if (!$isOwnerOrGlobalAdmin) {
                    $feedbackError = 'Seul le propriétaire du serveur ou un Administrateur Boötes peut nommer des co-administrateurs.';
                    break;
                }

                $targetUid = Security::validatePositiveInt($_POST['friend_user_id'] ?? null);
                $permLevel = Security::validateString($_POST['niveau_permission'] ?? 'COMPLET', 3, 20) ?? 'COMPLET';

                if ($targetUid === null && !empty($_POST['coadmin_pseudo'])) {
                    $pseudoSearch = Security::validateString($_POST['coadmin_pseudo'], 3, 32);
                    if ($pseudoSearch !== null) {
                        $uStmt = $db->prepare('SELECT id_utilisateur FROM UTILISATEUR WHERE pseudo = :p LIMIT 1');
                        $uStmt->execute([':p' => $pseudoSearch]);
                        $found = $uStmt->fetchColumn();
                        if ($found !== false) {
                            $targetUid = (int) $found;
                        }
                    }
                }

                if ($targetUid === null) {
                    $feedbackError = 'Veuillez sélectionner un ami ou saisir un pseudonyme Boötes existant.';
                } else {
                    $res = $serverManager->addCoAdmin($serverId, $targetUid, $permLevel);
                    if ($res['success']) {
                        $feedbackMessage = $res['message'];
                    } else {
                        $feedbackError = $res['message'];
                    }
                }
                break;

            case 'remove_coadmin':
                if (!$isOwnerOrGlobalAdmin) {
                    $feedbackError = 'Seul le propriétaire ou un Administrateur peut révoquer un co-administrateur.';
                    break;
                }
                $targetUid = Security::validatePositiveInt($_POST['coadmin_user_id'] ?? null);
                if ($targetUid !== null && $serverManager->removeCoAdmin($serverId, $targetUid)) {
                    $feedbackMessage = 'Droits de co-administration révoqués.';
                } else {
                    $feedbackError = 'Impossible de retirer ce co-administrateur.';
                }
                break;

            case 'add_listed_player':
                $pseudoMc = Security::validateString($_POST['pseudo_mc'] ?? '', 3, 16) ?? '';
                $listType = Security::validateString($_POST['type_liste'] ?? 'WHITELIST', 2, 15) ?? 'WHITELIST';
                $raison   = Security::validateString($_POST['raison'] ?? '', 0, 255) ?? '';

                if ($serverManager->addListedPlayer($serverId, $pseudoMc, $listType, $raison)) {
                    $feedbackMessage = sprintf('Le joueur "%s" a été enregistré dans la liste %s.', $pseudoMc, $listType);
                } else {
                    $feedbackError = 'Pseudonyme Minecraft invalide (3 à 16 caractères alphanumériques/underscores).';
                }
                break;

            case 'remove_listed_player':
                $pseudoMc = Security::validateString($_POST['pseudo_mc'] ?? '', 3, 16) ?? '';
                $listType = Security::validateString($_POST['type_liste'] ?? 'WHITELIST', 2, 15) ?? 'WHITELIST';

                if ($serverManager->removeListedPlayer($serverId, $pseudoMc, $listType)) {
                    $feedbackMessage = sprintf('Le joueur "%s" a été retiré de la liste %s.', $pseudoMc, $listType);
                } else {
                    $feedbackError = 'Impossible de retirer ce joueur.';
                }
                break;
        }
    }
}

$coAdmins      = $serverManager->getCoAdmins($serverId);
$listedPlayers = $serverManager->getListedPlayers($serverId);

// Chargement des amis acceptés du propriétaire pour faciliter l'ajout en 1 clic
$ownerFriends = [];
try {
    $ownerId = (int) $server['id_proprietaire'];
    $frStmt  = $db->prepare("
        SELECT 
            CASE WHEN ru.id_demandeur = :o1 THEN u_dest.id_utilisateur ELSE u_dem.id_utilisateur END AS friend_id,
            CASE WHEN ru.id_demandeur = :o2 THEN u_dest.pseudo ELSE u_dem.pseudo END AS friend_pseudo
        FROM RELATION_UTILISATEUR ru
        INNER JOIN UTILISATEUR u_dem ON u_dem.id_utilisateur = ru.id_demandeur
        INNER JOIN UTILISATEUR u_dest ON u_dest.id_utilisateur = ru.id_destinataire
        WHERE (ru.id_demandeur = :o3 OR ru.id_destinataire = :o4)
          AND ru.statut = 'ACCEPTE'
        ORDER BY friend_pseudo ASC
    ");
    $frStmt->execute([
        ':o1' => $ownerId,
        ':o2' => $ownerId,
        ':o3' => $ownerId,
        ':o4' => $ownerId,
    ]);
    $ownerFriends = $frStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][AccesAdmin] Erreur chargement amis : %s', $e->getMessage()));
}
?>
<div class="server-access">
    <header class="server-access__header">
        <h1 class="server-access__title">
            Accès Co-Administrateurs &amp; Listes Joueurs — <?= Security::e((string) $server['nom_serveur']) ?>
        </h1>
        <p class="server-access__subtitle">
            Déléguez la gestion du panel à vos amis (<code>ADMINISTRER_SERVEUR</code>) et contrôlez la Whitelist/Blacklist Minecraft (<code>ETRE_LISTER</code>).
        </p>
    </header>

    <?php if ($feedbackMessage !== null): ?>
        <div class="server-access__alert server-access__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="server-access__alert server-access__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <div class="server-access__grid">
        <!-- 1. GESTION DES CO-ADMINISTRATEURS (ADMINISTRER_SERVEUR) -->
        <section class="server-access__panel" aria-labelledby="coadmins-heading">
            <h2 id="coadmins-heading" class="server-access__panel-title">
                Co-Administrateurs du serveur (<?= count($coAdmins) ?>)
            </h2>

            <?php if ($isOwnerOrGlobalAdmin): ?>
                <form action="/serveur/acces-admin?id=<?= $serverId ?>" method="post" class="coadmin-form">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="server_id" value="<?= $serverId ?>">
                    <input type="hidden" name="action" value="add_coadmin">

                    <?php if (!empty($ownerFriends)): ?>
                        <div class="coadmin-form__field">
                            <label for="friend-select" class="coadmin-form__label">Sélectionner dans ma liste d'amis :</label>
                            <select id="friend-select" name="friend_user_id" class="coadmin-form__select">
                                <option value="">— Choisir un ami —</option>
                                <?php foreach ($ownerFriends as $fr): ?>
                                    <option value="<?= (int) $fr['friend_id'] ?>">
                                        <?= Security::e((string) $fr['friend_pseudo']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="coadmin-form__field">
                        <label for="coadmin-pseudo" class="coadmin-form__label">Ou saisir un pseudonyme Boötes :</label>
                        <input
                            type="text"
                            id="coadmin-pseudo"
                            name="coadmin_pseudo"
                            placeholder="Pseudo du membre Boötes"
                            maxlength="32"
                            class="coadmin-form__input"
                        >
                    </div>

                    <div class="coadmin-form__field">
                        <label for="coadmin-perm" class="coadmin-form__label">Niveau d'habilitation</label>
                        <select id="coadmin-perm" name="niveau_permission" class="coadmin-form__select">
                            <option value="COMPLET">Complet (Démarrage, Console, Backups, Joueurs)</option>
                            <option value="CONSOLE">Opérateur Console &amp; Logs</option>
                            <option value="LECTURE">Consultation seule</option>
                        </select>
                    </div>

                    <button type="submit" class="coadmin-form__submit">Ajouter comme Co-Administrateur</button>
                </form>
            <?php endif; ?>

            <?php if (empty($coAdmins)): ?>
                <p class="server-access__empty">Aucun co-administrateur n'est associé à ce serveur.</p>
            <?php else: ?>
                <table class="coadmins-table">
                    <caption class="coadmins-table__caption">Utilisateurs habilités dans ADMINISTRER_SERVEUR</caption>
                    <thead class="coadmins-table__head">
                        <tr class="coadmins-table__row">
                            <th scope="col" class="coadmins-table__cell">Utilisateur</th>
                            <th scope="col" class="coadmins-table__cell">Permission</th>
                            <th scope="col" class="coadmins-table__cell">Ajouté le</th>
                            <?php if ($isOwnerOrGlobalAdmin): ?>
                                <th scope="col" class="coadmins-table__cell">Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="coadmins-table__body">
                        <?php foreach ($coAdmins as $ca): ?>
                            <tr class="coadmins-table__row">
                                <th scope="row" class="coadmins-table__cell"><?= Security::e((string) $ca['pseudo']) ?></th>
                                <td class="coadmins-table__cell"><?= Security::e((string) $ca['niveau_permission']) ?></td>
                                <td class="coadmins-table__cell"><?= Security::e((string) $ca['date_attribution']) ?></td>
                                <?php if ($isOwnerOrGlobalAdmin): ?>
                                    <td class="coadmins-table__cell">
                                        <form action="/serveur/acces-admin?id=<?= $serverId ?>" method="post" class="coadmins-table__form">
                                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                            <input type="hidden" name="server_id" value="<?= $serverId ?>">
                                            <input type="hidden" name="action" value="remove_coadmin">
                                            <input type="hidden" name="coadmin_user_id" value="<?= (int) $ca['id_utilisateur'] ?>">
                                            <button type="submit" class="coadmins-table__revoke-btn">Révoquer</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <!-- 2. WHITELIST / BLACKLIST DES JOUEURS MINECRAFT (ETRE_LISTER) -->
        <section class="server-access__panel" aria-labelledby="playerlist-heading">
            <h2 id="playerlist-heading" class="server-access__panel-title">
                Whitelist, Blacklist &amp; Opérateurs Minecraft (<code>ETRE_LISTER</code>)
            </h2>

            <form action="/serveur/acces-admin?id=<?= $serverId ?>" method="post" class="playerlist-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="server_id" value="<?= $serverId ?>">
                <input type="hidden" name="action" value="add_listed_player">

                <div class="playerlist-form__field">
                    <label for="pl-pseudo" class="playerlist-form__label">Pseudo en jeu (Minecraft)</label>
                    <input
                        type="text"
                        id="pl-pseudo"
                        name="pseudo_mc"
                        pattern="^[a-zA-Z0-9_]{3,16}$"
                        minlength="3"
                        maxlength="16"
                        required
                        placeholder="Ex : Notch"
                        class="playerlist-form__input"
                    >
                </div>

                <div class="playerlist-form__field">
                    <label for="pl-type" class="playerlist-form__label">Liste cible</label>
                    <select id="pl-type" name="type_liste" class="playerlist-form__select">
                        <option value="WHITELIST">WHITELIST (Autorisé à rejoindre)</option>
                        <option value="BLACKLIST">BLACKLIST (Banni du serveur)</option>
                        <option value="OP">OP (Opérateur en jeu)</option>
                    </select>
                </div>

                <div class="playerlist-form__field">
                    <label for="pl-reason" class="playerlist-form__label">Motif ou note (optionnel)</label>
                    <input
                        type="text"
                        id="pl-reason"
                        name="raison"
                        maxlength="255"
                        placeholder="Ex : Membre vérifié / Grief au spawn"
                        class="playerlist-form__input"
                    >
                </div>

                <button type="submit" class="playerlist-form__submit">Enregistrer le joueur</button>
            </form>

            <?php if (empty($listedPlayers)): ?>
                <p class="server-access__empty">Aucun joueur inscrit dans la Whitelist ou la Blacklist.</p>
            <?php else: ?>
                <table class="playerlist-table">
                    <caption class="playerlist-table__caption">Entrées actives dans ETRE_LISTER</caption>
                    <thead class="playerlist-table__head">
                        <tr class="playerlist-table__row">
                            <th scope="col" class="playerlist-table__cell">Pseudo MC</th>
                            <th scope="col" class="playerlist-table__cell">Liste</th>
                            <th scope="col" class="playerlist-table__cell">Motif</th>
                            <th scope="col" class="playerlist-table__cell">Date</th>
                            <th scope="col" class="playerlist-table__cell">Action</th>
                        </tr>
                    </thead>
                    <tbody class="playerlist-table__body">
                        <?php foreach ($listedPlayers as $lp): ?>
                            <tr class="playerlist-table__row">
                                <th scope="row" class="playerlist-table__cell"><?= Security::e((string) $lp['pseudo_mc']) ?></th>
                                <td class="playerlist-table__cell">
                                    <span class="playerlist-table__badge"><?= Security::e((string) $lp['type_liste']) ?></span>
                                </td>
                                <td class="playerlist-table__cell"><?= Security::e((string) ($lp['raison'] ?: '—')) ?></td>
                                <td class="playerlist-table__cell"><?= Security::e((string) $lp['date_ajout']) ?></td>
                                <td class="playerlist-table__cell">
                                    <form action="/serveur/acces-admin?id=<?= $serverId ?>" method="post" class="playerlist-table__form">
                                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                        <input type="hidden" name="server_id" value="<?= $serverId ?>">
                                        <input type="hidden" name="action" value="remove_listed_player">
                                        <input type="hidden" name="pseudo_mc" value="<?= Security::e((string) $lp['pseudo_mc']) ?>">
                                        <input type="hidden" name="type_liste" value="<?= Security::e((string) $lp['type_liste']) ?>">
                                        <button type="submit" class="playerlist-table__remove-btn">Retirer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</div>