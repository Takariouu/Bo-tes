<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/ResourceManager.php';

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Http;
use Bootes\Src\ResourceManager;
use Bootes\Src\Security;
use Bootes\Src\ServerManager;
use Bootes\Src\UserManager;
use Bootes\Src\View;

$db              = $db ?? Database::getInstance();
$resourceManager = new ResourceManager($db);
$serverManager   = new ServerManager($db);

$loggedUserId = Auth::getUserId();
if ($loggedUserId === null) {
    Http::redirect('/auth?mode=login');
}

// Détection du mode modération Admin (?id=X)
$requestedUserId = Security::validatePositiveInt($_GET['id'] ?? $_POST['target_user_id'] ?? null);
$isAdminView     = false;
$targetUserId    = $loggedUserId;

if ($requestedUserId !== null && $requestedUserId !== $loggedUserId) {
    // Un administrateur ne gère que des comptes de rang strictement inférieur au sien (anti-escalade).
    $targetRole = Auth::isAdmin() ? Auth::getUserRole($requestedUserId, $db) : null;

    if ($targetRole !== null && Auth::canAdministrate(Auth::getRole(), $targetRole)) {
        $targetUserId = $requestedUserId;
        $isAdminView  = true;
    } else {
        http_response_code(403);
        ?>
        <section class="account account--forbidden">
            <h1 class="account__title">Accès refusé</h1>
            <p class="account__message">Vous n'avez pas l'autorisation de consulter ou modifier ce profil (compte inexistant ou de rang égal ou supérieur au vôtre).</p>
            <a href="/user/compte" class="account__btn">Retourner sur mon profil</a>
        </section>
        <?php
        return;
    }
}

$csrfToken       = Security::generateCsrfToken();
$feedbackMessage = null;
$feedbackError   = null;

// Traitement centralisé des actions POST (Profil, RGPD, Serveurs, Amis, Tickets)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 40) ?? '';

    if (!Security::verifyCsrfToken($postCsrf)) {
        $feedbackError = 'Jeton CSRF invalide ou expiré. Veuillez réessayer.';
    } else {
        try {
            switch ($action) {
                case 'update_profile':
                    $newPseudo       = Security::validateString($_POST['pseudo'] ?? '', 3, 32);
                    $newEmail        = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
                    $currentPassword = (string) ($_POST['current_password'] ?? '');
                    $newPassword     = (string) ($_POST['new_password'] ?? '');

                    if ($newPseudo === null || !preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $newPseudo) || $newEmail === false) {
                        $feedbackError = 'Veuillez fournir un pseudonyme valide (3-32 caractères) et une adresse e-mail correcte.';
                        break;
                    }

                    $currentIdentity = $db->prepare('SELECT pseudo, email FROM UTILISATEUR WHERE id_utilisateur = :uid');
                    $currentIdentity->execute([':uid' => $targetUserId]);
                    $identityRow    = $currentIdentity->fetch(PDO::FETCH_ASSOC) ?: ['pseudo' => '', 'email' => ''];
                    $pseudoChanged  = mb_strtolower($newPseudo, 'UTF-8') !== mb_strtolower((string) $identityRow['pseudo'], 'UTF-8');
                    $emailChanged   = mb_strtolower($newEmail, 'UTF-8') !== mb_strtolower((string) $identityRow['email'], 'UTF-8');

                    if (
                        ($pseudoChanged && Auth::isReservedIdentity($newPseudo, '')) ||
                        ($emailChanged && Auth::isReservedIdentity('', $newEmail))
                    ) {
                        $feedbackError = 'Ce pseudonyme ou cette adresse e-mail est réservé(e) par la plateforme.';
                        break;
                    }

                    $dupStmt = $db->prepare('
                        SELECT COUNT(*)
                        FROM UTILISATEUR
                        WHERE (pseudo = :pseudo OR email = :email)
                          AND id_utilisateur != :uid
                    ');
                    $dupStmt->execute([
                        ':pseudo' => $newPseudo,
                        ':email'  => $newEmail,
                        ':uid'    => $targetUserId,
                    ]);

                    if ((int) $dupStmt->fetchColumn() > 0) {
                        $feedbackError = 'Ce pseudonyme ou cette adresse e-mail est déjà utilisé par un autre compte.';
                        break;
                    }

                    if (!$isAdminView && !Auth::verifyPassword($targetUserId, $currentPassword, $db)) {
                        $feedbackError = 'Votre mot de passe actuel est requis et doit être exact pour modifier le profil.';
                        break;
                    }

                    if ($newPassword !== '') {
                        if (!Auth::isAcceptablePassword($newPassword)) {
                            $feedbackError = 'Le nouveau mot de passe doit contenir au moins 10 caractères.';
                            break;
                        }
                        $newHash = Auth::hashPassword($newPassword);
                        $updStmt = $db->prepare('
                            UPDATE UTILISATEUR
                            SET pseudo = :pseudo, email = :email, mot_de_passe = :hash
                            WHERE id_utilisateur = :uid
                        ');
                        $updStmt->execute([
                            ':pseudo' => $newPseudo,
                            ':email'  => $newEmail,
                            ':hash'   => $newHash,
                            ':uid'    => $targetUserId,
                        ]);
                    } else {
                        $updStmt = $db->prepare('
                            UPDATE UTILISATEUR
                            SET pseudo = :pseudo, email = :email
                            WHERE id_utilisateur = :uid
                        ');
                        $updStmt->execute([
                            ':pseudo' => $newPseudo,
                            ':email'  => $newEmail,
                            ':uid'    => $targetUserId,
                        ]);
                    }

                    if (!$isAdminView && isset($_SESSION['user'])) {
                        $_SESSION['user']['pseudo'] = $newPseudo;
                        $_SESSION['user']['email']  = $newEmail;
                        if ($newPassword !== '') {
                            session_regenerate_id(true);
                        }
                    }

                    $feedbackMessage = 'Les informations du profil ont été mises à jour.';
                    break;

                case 'admin_update_account':
                    if (!$isAdminView || !Auth::isAdmin()) {
                        $feedbackError = 'Action réservée aux administrateurs.';
                        break;
                    }

                    $accountTier = ($_POST['type_compte'] ?? '') === Auth::ACCOUNT_PREMIUM
                        ? Auth::ACCOUNT_PREMIUM
                        : Auth::ACCOUNT_STANDARD;
                    $isPremium   = $accountTier === Auth::ACCOUNT_PREMIUM;
                    $maxRam      = Security::validateRamAllocation($_POST['max_ram_allouable'] ?? 2048, $isPremium) ?? 2048;
                    $estBanni    = isset($_POST['est_banni']) && $_POST['est_banni'] === '1' ? 1 : 0;

                    $admStmt = $db->prepare('
                        UPDATE UTILISATEUR
                        SET type_compte = :tier,
                            max_ram_allouable = :max_ram,
                            est_banni = :banni
                        WHERE id_utilisateur = :uid
                    ');
                    $admStmt->execute([
                        ':tier'    => $accountTier,
                        ':max_ram' => $maxRam,
                        ':banni'   => $estBanni,
                        ':uid'     => $targetUserId,
                    ]);
                    $feedbackMessage = 'Paramètres de modération du compte enregistrés.';
                    break;

                case 'delete_account_rgpd':
                    $rgpdMode    = Security::validateString($_POST['rgpd_resources_mode'] ?? 'anonymize', 4, 20) ?? 'anonymize';
                    $confirmText = trim((string) ($_POST['confirm_delete_text'] ?? ''));

                    if ($confirmText !== 'SUPPRIMER') {
                        $feedbackError = 'Veuillez saisir exactement le mot SUPPRIMER pour confirmer la suppression définitive.';
                        break;
                    }

                    // Ré-authentification obligatoire du titulaire avant une suppression irréversible.
                    if (!$isAdminView && !Auth::verifyPassword($targetUserId, (string) ($_POST['current_password'] ?? ''), $db)) {
                        $feedbackError = 'Votre mot de passe actuel est requis pour confirmer la suppression du compte.';
                        break;
                    }

                    $userManager = new UserManager($db, $serverManager, $resourceManager);
                    if (!$userManager->deleteAccount($targetUserId, $rgpdMode === 'delete_all')) {
                        $feedbackError = 'La suppression du compte a échoué : aucune donnée n\'a été modifiée. Contactez le support.';
                        break;
                    }

                    if (!$isAdminView) {
                        Auth::logout();
                        Http::redirect('/home', 'Votre compte et vos données personnelles ont été supprimés définitivement.');
                    }

                    Http::redirect('/admin/utilisateurs', 'Le compte utilisateur et ses données personnelles ont été purgés conformément au RGPD.');

                case 'create_server':
                    $creation = $serverManager->createServer($targetUserId, $_POST, $isAdminView && Auth::isAdmin());
                    if ($creation['success']) {
                        $feedbackMessage = $creation['message'];
                    } else {
                        $feedbackError = $creation['message'];
                    }
                    break;

                case 'add_friend':
                    $friendPseudo = Security::validateString($_POST['friend_pseudo'] ?? '', 3, 32);
                    if ($friendPseudo === null) {
                        $feedbackError = 'Veuillez saisir un pseudonyme valide.';
                        break;
                    }

                    $findFriend = $db->prepare('SELECT id_utilisateur FROM UTILISATEUR WHERE pseudo = :pseudo LIMIT 1');
                    $findFriend->execute([':pseudo' => $friendPseudo]);
                    $friendId = $findFriend->fetchColumn();

                    if ($friendId === false || (int) $friendId === $targetUserId) {
                        $feedbackError = 'Utilisateur introuvable ou identique à votre propre compte.';
                        break;
                    }

                    $relCheck = $db->prepare('
                        SELECT COUNT(*)
                        FROM RELATION_UTILISATEUR
                        WHERE (id_demandeur = :u1 AND id_destinataire = :u2)
                           OR (id_demandeur = :u3 AND id_destinataire = :u4)
                    ');
                    $relCheck->execute([
                        ':u1' => $targetUserId,
                        ':u2' => (int) $friendId,
                        ':u3' => (int) $friendId,
                        ':u4' => $targetUserId,
                    ]);

                    if ((int) $relCheck->fetchColumn() > 0) {
                        $feedbackError = 'Une relation ou demande d\'ami existe déjà avec cet utilisateur.';
                        break;
                    }

                    $addRel = $db->prepare('
                        INSERT INTO RELATION_UTILISATEUR (id_demandeur, id_destinataire, statut, date_relation)
                        VALUES (:u1, :u2, \'EN_ATTENTE\', NOW())
                    ');
                    $addRel->execute([
                        ':u1' => $targetUserId,
                        ':u2' => (int) $friendId,
                    ]);
                    $feedbackMessage = sprintf('Demande d\'ami envoyée à %s.', $friendPseudo);
                    break;

                case 'accept_friend':
                    $requesterId = Security::validatePositiveInt($_POST['friend_user_id'] ?? null);
                    if ($requesterId !== null) {
                        $accStmt = $db->prepare('
                            UPDATE RELATION_UTILISATEUR
                            SET statut = \'ACCEPTE\'
                            WHERE id_demandeur = :req AND id_destinataire = :dest AND statut = \'EN_ATTENTE\'
                        ');
                        $accStmt->execute([
                            ':req'  => $requesterId,
                            ':dest' => $targetUserId,
                        ]);
                        $feedbackMessage = 'Demande d\'ami acceptée.';
                    }
                    break;

                case 'remove_friend':
                    $otherUserId = Security::validatePositiveInt($_POST['friend_user_id'] ?? null);
                    if ($otherUserId !== null) {
                        $remStmt = $db->prepare('
                            DELETE FROM RELATION_UTILISATEUR
                            WHERE (id_demandeur = :u1 AND id_destinataire = :u2)
                               OR (id_demandeur = :u3 AND id_destinataire = :u4)
                        ');
                        $remStmt->execute([
                            ':u1' => $targetUserId,
                            ':u2' => $otherUserId,
                            ':u3' => $otherUserId,
                            ':u4' => $targetUserId,
                        ]);
                        $feedbackMessage = 'Relation supprimée de votre liste d\'amis.';
                    }
                    break;

                case 'create_ticket':
                    $sujet     = Security::validateString($_POST['sujet'] ?? '', 5, 120);
                    $categorie = Security::validateString($_POST['categorie'] ?? 'TECHNIQUE', 3, 40) ?? 'TECHNIQUE';
                    $message   = Security::validateString($_POST['message'] ?? '', 10, 3000);

                    if ($sujet === null || $message === null) {
                        $feedbackError = 'Veuillez renseigner un sujet (5 caractères min.) et détailler votre demande.';
                        break;
                    }

                    $db->beginTransaction();
                    $tktStmt = $db->prepare('
                        INSERT INTO TICKET_SUPPORT (id_utilisateur, sujet, categorie, statut, date_creation)
                        VALUES (:uid, :sujet, :cat, \'OUVERT\', NOW())
                    ');
                    $tktStmt->execute([
                        ':uid'   => $targetUserId,
                        ':sujet' => $sujet,
                        ':cat'   => $categorie,
                    ]);
                    $newTicketId = (int) $db->lastInsertId();

                    $msgStmt = $db->prepare('
                        INSERT INTO MESSAGE_TICKET (id_ticket, id_auteur, contenu, date_envoi)
                        VALUES (:tid, :uid, :contenu, NOW())
                    ');
                    $msgStmt->execute([
                        ':tid'     => $newTicketId,
                        ':uid'     => $loggedUserId,
                        ':contenu' => $message,
                    ]);
                    $db->commit();

                    $feedbackMessage = sprintf('Votre ticket #%d a été ouvert auprès du support Boötes.', $newTicketId);
                    break;

                case 'reply_ticket':
                    $ticketId = Security::validatePositiveInt($_POST['ticket_id'] ?? null);
                    $replyMsg = Security::validateString($_POST['message'] ?? '', 2, 3000);

                    if ($ticketId !== null && $replyMsg !== null) {
                        $chkTkt = $db->prepare('SELECT id_utilisateur FROM TICKET_SUPPORT WHERE id_ticket = :tid');
                        $chkTkt->execute([':tid' => $ticketId]);
                        $tktOwner = $chkTkt->fetchColumn();

                        if ($tktOwner !== false && ((int) $tktOwner === $targetUserId || Auth::isAdmin())) {
                            $repStmt = $db->prepare('
                                INSERT INTO MESSAGE_TICKET (id_ticket, id_auteur, contenu, date_envoi)
                                VALUES (:tid, :uid, :contenu, NOW())
                            ');
                            $repStmt->execute([
                                ':tid'     => $ticketId,
                                ':uid'     => $loggedUserId,
                                ':contenu' => $replyMsg,
                            ]);

                            $newStatus = Auth::isAdmin() && $isAdminView ? 'REPONDU' : 'OUVERT';
                            $db->prepare('UPDATE TICKET_SUPPORT SET statut = :st WHERE id_ticket = :tid')
                               ->execute([':st' => $newStatus, ':tid' => $ticketId]);

                            $feedbackMessage = 'Votre réponse a été ajoutée au ticket.';
                        }
                    }
                    break;

                case 'close_ticket':
                    $ticketId = Security::validatePositiveInt($_POST['ticket_id'] ?? null);
                    if ($ticketId !== null) {
                        $clsStmt = $db->prepare('
                            UPDATE TICKET_SUPPORT
                            SET statut = \'FERME\'
                            WHERE id_ticket = :tid AND (id_utilisateur = :uid OR :is_admin = 1)
                        ');
                        $clsStmt->execute([
                            ':tid'      => $ticketId,
                            ':uid'      => $targetUserId,
                            ':is_admin' => Auth::isAdmin() ? 1 : 0,
                        ]);
                        $feedbackMessage = sprintf('Le ticket #%d a été clôturé.', $ticketId);
                    }
                    break;
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log(sprintf('[Boötes][Compte] Erreur SQL : %s', $e->getMessage()));
            $feedbackError = 'Une erreur technique est survenue lors de l\'enregistrement.';
        }
    }
}

// 1. Chargement des informations de l'utilisateur cible
$userProfile = null;
try {
    $uStmt = $db->prepare('
        SELECT id_utilisateur, pseudo, email, role, type_compte, max_ram_allouable, est_banni, date_inscription
        FROM UTILISATEUR
        WHERE id_utilisateur = :uid
        LIMIT 1
    ');
    $uStmt->execute([':uid' => $targetUserId]);
    $userProfile = $uStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Compte] Erreur chargement profil : %s', $e->getMessage()));
}

if ($userProfile === null) {
    ?>
    <section class="account account--not-found">
        <h1 class="account__title">Profil utilisateur introuvable</h1>
        <p class="account__message">Ce compte n'existe pas ou a été supprimé.</p>
    </section>
    <?php
    return;
}

// 2. Chargement des serveurs (Propriétaire + Co-Admin)
$myServers = [];
try {
    $srvStmt = $db->prepare('
        SELECT DISTINCT
            s.id_serveur,
            s.id_proprietaire,
            s.nom_serveur,
            s.port,
            s.version_mc,
            s.mode_jeu,
            s.statut,
            s.joueurs_connectes,
            s.slots_max,
            s.ram_allouee,
            s.est_public
        FROM SERVEUR s
        LEFT JOIN ADMINISTRER_SERVEUR ads ON ads.id_serveur = s.id_serveur
        WHERE s.id_proprietaire = :uid_owner OR ads.id_utilisateur = :uid_coadmin
        ORDER BY s.id_proprietaire = :uid_order DESC, s.date_creation DESC
    ');
    $srvStmt->execute([
        ':uid_owner'   => $targetUserId,
        ':uid_coadmin' => $targetUserId,
        ':uid_order'   => $targetUserId,
    ]);
    $myServers = $srvStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Compte] Erreur chargement serveurs : %s', $e->getMessage()));
}

// 3. Chargement des créations (Mods, Modpacks, Plugins) via ResourceManager
$myCreations = [];
try {
    $myCreations = $resourceManager->getResourcesByUser($targetUserId);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Compte] Erreur chargement créations : %s', $e->getMessage()));
}

// 4. Chargement de la liste d'amis (RELATION_UTILISATEUR)
$pendingReceived = [];
$pendingSent     = [];
$acceptedFriends = [];
try {
    $relStmt = $db->prepare('
        SELECT 
            ru.id_demandeur,
            ru.id_destinataire,
            ru.statut,
            ru.date_relation,
            u_dem.pseudo AS demandeur_pseudo,
            u_dest.pseudo AS destinataire_pseudo
        FROM RELATION_UTILISATEUR ru
        INNER JOIN UTILISATEUR u_dem ON u_dem.id_utilisateur = ru.id_demandeur
        INNER JOIN UTILISATEUR u_dest ON u_dest.id_utilisateur = ru.id_destinataire
        WHERE ru.id_demandeur = :u1 OR ru.id_destinataire = :u2
        ORDER BY ru.date_relation DESC
    ');
    $relStmt->execute([
        ':u1' => $targetUserId,
        ':u2' => $targetUserId,
    ]);

    foreach ($relStmt->fetchAll(PDO::FETCH_ASSOC) as $rel) {
        if ($rel['statut'] === 'ACCEPTE') {
            $isRequester = (int) $rel['id_demandeur'] === $targetUserId;
            $acceptedFriends[] = [
                'friend_id'     => $isRequester ? (int) $rel['id_destinataire'] : (int) $rel['id_demandeur'],
                'friend_pseudo' => $isRequester ? (string) $rel['destinataire_pseudo'] : (string) $rel['demandeur_pseudo'],
                'since'         => (string) $rel['date_relation'],
            ];
        } elseif ($rel['statut'] === 'EN_ATTENTE') {
            if ((int) $rel['id_destinataire'] === $targetUserId) {
                $pendingReceived[] = [
                    'friend_id'     => (int) $rel['id_demandeur'],
                    'friend_pseudo' => (string) $rel['demandeur_pseudo'],
                    'date'          => (string) $rel['date_relation'],
                ];
            } else {
                $pendingSent[] = [
                    'friend_id'     => (int) $rel['id_destinataire'],
                    'friend_pseudo' => (string) $rel['destinataire_pseudo'],
                    'date'          => (string) $rel['date_relation'],
                ];
            }
        }
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Compte] Erreur chargement amis : %s', $e->getMessage()));
}

// 5. Chargement des tickets de support et de leurs messages
$tickets       = [];
$ticketReplies = [];
try {
    $tktQuery = $db->prepare('
        SELECT id_ticket, sujet, categorie, statut, date_creation
        FROM TICKET_SUPPORT
        WHERE id_utilisateur = :uid
        ORDER BY date_creation DESC
    ');
    $tktQuery->execute([':uid' => $targetUserId]);
    $tickets = $tktQuery->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($tickets)) {
        $msgQuery = $db->prepare('
            SELECT mt.id_ticket, mt.contenu, mt.date_envoi, u.pseudo AS auteur_pseudo, u.role AS auteur_role
            FROM MESSAGE_TICKET mt
            INNER JOIN UTILISATEUR u ON u.id_utilisateur = mt.id_auteur
            INNER JOIN TICKET_SUPPORT ts ON ts.id_ticket = mt.id_ticket
            WHERE ts.id_utilisateur = :uid
            ORDER BY mt.date_envoi ASC
        ');
        $msgQuery->execute([':uid' => $targetUserId]);
        foreach ($msgQuery->fetchAll(PDO::FETCH_ASSOC) as $msgRow) {
            $tid = (int) $msgRow['id_ticket'];
            $ticketReplies[$tid][] = $msgRow;
        }
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Compte] Erreur chargement tickets : %s', $e->getMessage()));
}

// 6. Serveurs favoris (fiches publiques mises de côté)
$favoriteServers = [];
try {
    $favQuery = $db->prepare('
        SELECT s.id_serveur, s.nom_serveur, s.version_mc, s.mode_jeu, s.statut, s.joueurs_connectes, s.slots_max,
               s.description, u.pseudo AS proprietaire_pseudo,
               COALESCE((SELECT AVG(a.note) FROM AVIS_SERVEUR a WHERE a.id_serveur = s.id_serveur), 0) AS note_moyenne
        FROM FAVORI_SERVEUR f
        INNER JOIN SERVEUR s ON s.id_serveur = f.id_serveur
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = s.id_proprietaire
        WHERE f.id_utilisateur = :uid AND (s.est_public = 1 OR s.id_proprietaire = :uid_owner)
        ORDER BY f.date_ajout DESC
    ');
    $favQuery->execute([':uid' => $targetUserId, ':uid_owner' => $targetUserId]);
    $favoriteServers = $favQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Compte] Erreur chargement favoris : %s', $e->getMessage()));
}

$formTargetUrl = $isAdminView ? '/user/compte?id=' . $targetUserId : '/user/compte';
$isPremiumTier = strtoupper((string) $userProfile['type_compte']) === Auth::ACCOUNT_PREMIUM;
$ramCeiling    = ServerManager::ramCeilingFor((string) $userProfile['type_compte'], (int) $userProfile['max_ram_allouable']);
$maxServers    = ServerManager::maxServersFor((string) $userProfile['type_compte']);

// Données de présentation
$ownedServers  = count(array_filter($myServers, static fn(array $srv): bool => (int) $srv['id_proprietaire'] === $targetUserId));
$openTickets   = count(array_filter($tickets, static fn(array $tkt): bool => strtoupper((string) $tkt['statut']) !== 'FERME'));
$sectionUrl    = static fn(string $section): string => $formTargetUrl . '#' . $section;
$ramDefault    = min(2048, max(512, $ramCeiling));
$ramMax        = $isAdminView ? ServerManager::HOST_MAX_MC_RAM_MB : max(512, $ramCeiling);
?>
<div class="account" data-tabs>
    <header class="account__header">
        <div class="account__identity">
            <span class="avatar avatar--xl" aria-hidden="true"><?= Security::e(View::initials((string) $userProfile['pseudo'])) ?></span>
            <div class="account__heading">
                <p class="account__eyebrow"><?= $isAdminView ? 'Inspection administrateur' : 'Mon espace' ?></p>
                <h1 class="account__title"><?= Security::e((string) $userProfile['pseudo']) ?></h1>
                <p class="account__subtitle">
                    <?= View::badge('role', $userProfile['role'] ?? 'UTILISATEUR') ?>
                    <?= View::badge('offre', $userProfile['type_compte']) ?>
                    <span>Membre depuis le <time datetime="<?= Security::e((string) $userProfile['date_inscription']) ?>"><?= Security::e(View::date((string) $userProfile['date_inscription'])) ?></time></span>
                </p>
            </div>
        </div>

        <dl class="account__stats">
            <div class="account__stat">
                <dt>Serveurs</dt>
                <dd><?= $ownedServers ?> / <?= $maxServers ?></dd>
                <dd><meter min="0" max="<?= $maxServers ?>" value="<?= $ownedServers ?>" class="account__meter" aria-label="Serveurs créés sur le maximum de l'offre"></meter></dd>
            </div>
            <div class="account__stat">
                <dt>Quota RAM</dt>
                <dd><?= Security::e(View::number((int) $userProfile['max_ram_allouable'] / 1024, 1)) ?> Go</dd>
                <dd><?= (int) $userProfile['max_ram_allouable'] ?> Mo au total</dd>
            </div>
            <div class="account__stat">
                <dt>Créations</dt>
                <dd><?= count($myCreations) ?></dd>
                <dd>mods, plugins, modpacks</dd>
            </div>
            <div class="account__stat">
                <dt>Amis</dt>
                <dd><?= count($acceptedFriends) ?></dd>
                <dd><?= count($pendingReceived) ?> demande<?= count($pendingReceived) > 1 ? 's' : '' ?> reçue<?= count($pendingReceived) > 1 ? 's' : '' ?></dd>
            </div>
        </dl>

        <?php if ($isAdminView): ?>
            <div class="account__admin-banner">
                <p class="account__admin-notice">
                    Vous consultez ce profil avec vos privilèges <strong><?= Security::e(View::label('role', Auth::getRole())) ?></strong> :
                    chaque modification est appliquée au compte de <?= Security::e((string) $userProfile['pseudo']) ?>.
                </p>
                <a href="/admin/utilisateurs" class="account__btn account__btn--back"><?= View::icon('arrow-left') ?> Retour aux utilisateurs</a>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($feedbackMessage !== null): ?>
        <div class="account__alert account__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="account__alert account__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <nav class="tabs__list account__tabs" aria-label="Rubriques du compte" data-tablist>
        <a href="#section-serveurs" class="tabs__tab" data-tab><?= View::icon('server') ?> Serveurs <span class="tabs__count"><?= count($myServers) ?></span></a>
        <a href="#section-creations" class="tabs__tab" data-tab><?= View::icon('blocks') ?> Créations <span class="tabs__count"><?= count($myCreations) ?></span></a>
        <a href="#section-amis" class="tabs__tab" data-tab><?= View::icon('users') ?> Amis
            <?php if (!empty($pendingReceived)): ?>
                <span class="tabs__count tabs__count--alert" title="Demandes reçues"><?= count($pendingReceived) ?></span>
            <?php else: ?>
                <span class="tabs__count"><?= count($acceptedFriends) ?></span>
            <?php endif; ?>
        </a>
        <a href="#section-tickets" class="tabs__tab" data-tab><?= View::icon('life-buoy') ?> Support <span class="tabs__count"><?= $openTickets ?></span></a>
        <a href="#section-profil" class="tabs__tab" data-tab><?= View::icon('settings') ?> Profil et sécurité</a>
    </nav>

    <!-- 1. SERVEURS -->
    <section id="section-serveurs" class="account__section account__section--servers" aria-labelledby="heading-serveurs">
        <header class="account__section-header">
            <div class="account__section-heading">
                <h2 id="heading-serveurs" class="account__section-title">Mes serveurs Minecraft</h2>
                <p class="account__section-desc">
                    Serveurs dont vous êtes propriétaire ou co-administrateur &middot;
                    offre <?= Security::e(View::label('offre', $userProfile['type_compte'])) ?> : <?= $maxServers ?> serveurs, <?= $ramCeiling ?> Mo de RAM.
                </p>
            </div>
        </header>

        <details class="account__create-server"<?= empty($myServers) ? ' open' : '' ?>>
            <summary class="account__create-toggle"><?= View::icon('plus') ?> Créer un serveur</summary>
            <form action="<?= Security::e($sectionUrl('section-serveurs')) ?>" method="post" class="account-form account-form--server">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="action" value="create_server">
                <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">

                <div class="account-form__steps">
                    <fieldset class="account-form__fieldset">
                        <legend class="account-form__legend"><span class="account-form__step">1</span> Identité</legend>
                        <div class="account-form__field">
                            <label for="srv-nom" class="account-form__label">Nom du serveur</label>
                            <input type="text" id="srv-nom" name="nom_serveur" minlength="3" maxlength="50" required class="account-form__input" placeholder="Ex. : Les bâtisseurs du vide">
                        </div>
                        <div class="account-form__field">
                            <label for="srv-desc" class="account-form__label">Description publique</label>
                            <textarea id="srv-desc" name="description" rows="3" maxlength="500" class="account-form__textarea" placeholder="Ce qui rend votre monde unique (500 caractères max)."></textarea>
                        </div>
                        <div class="account-form__field account-form__field--checkbox">
                            <input type="checkbox" id="srv-public" name="est_public" value="1" checked class="account-form__checkbox">
                            <label for="srv-public" class="account-form__checkbox-label">Afficher ce serveur dans l'annuaire public</label>
                        </div>
                    </fieldset>

                    <fieldset class="account-form__fieldset">
                        <legend class="account-form__legend"><span class="account-form__step">2</span> Jeu</legend>
                        <div class="account-form__field">
                            <label for="srv-version" class="account-form__label">Version de Minecraft</label>
                            <select id="srv-version" name="version_mc" class="account-form__select">
                                <?php foreach (ResourceManager::SUPPORTED_MC_VERSIONS as $mcVer): ?>
                                    <option value="<?= Security::e($mcVer) ?>">Minecraft <?= Security::e($mcVer) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="account-form__field">
                            <label for="srv-mode" class="account-form__label">Mode de jeu</label>
                            <select id="srv-mode" name="mode_jeu" class="account-form__select">
                                <?php foreach (ServerManager::GAME_MODES as $modeKey => $modeLabel): ?>
                                    <option value="<?= Security::e($modeKey) ?>"><?= Security::e($modeLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </fieldset>

                    <fieldset class="account-form__fieldset">
                        <legend class="account-form__legend"><span class="account-form__step">3</span> Ressources</legend>
                        <div class="account-form__field">
                            <label for="srv-ram" class="account-form__label">
                                Mémoire RAM : <output for="srv-ram" class="account-form__output" data-range-output="srv-ram"><?= $ramDefault ?></output> Mo
                            </label>
                            <input type="range" id="srv-ram" name="ram_allouee" min="512" max="<?= $ramMax ?>" step="512" value="<?= $ramDefault ?>" required class="account-form__range">
                            <small class="account-form__help">De 512 Mo à <?= $ramMax ?> Mo selon votre offre. Le total des serveurs en ligne ne peut pas dépasser votre quota.</small>
                        </div>
                        <div class="account-form__field">
                            <label for="srv-slots" class="account-form__label">Joueurs maximum</label>
                            <input type="number" id="srv-slots" name="slots_max" min="2" max="<?= ServerManager::MAX_SLOTS ?>" value="20" required class="account-form__input">
                        </div>
                    </fieldset>
                </div>

                <button type="submit" class="account-form__submit"><?= View::icon('rocket') ?> Créer le serveur</button>
            </form>
        </details>

        <?php if (empty($myServers)): ?>
            <p class="account__empty">Aucun serveur pour le moment : créez votre premier monde avec le formulaire ci-dessus.</p>
        <?php else: ?>
            <div class="account__server-list">
                <?php foreach ($myServers as $srv): ?>
                    <?php
                    $srvId      = (int) $srv['id_serveur'];
                    $isOwner    = (int) $srv['id_proprietaire'] === $targetUserId;
                    $srvSlots   = max(1, (int) $srv['slots_max']);
                    $srvPlayers = min(max(0, (int) $srv['joueurs_connectes']), $srvSlots);
                    ?>
                    <article class="account-server-card">
                        <header class="account-server-card__header">
                            <?= View::badge('statut_serveur', $srv['statut']) ?>
                            <span class="account-server-card__role-badge"><?= $isOwner ? 'Propriétaire' : 'Co-admin' ?></span>
                        </header>
                        <h3 class="account-server-card__title"><?= Security::e((string) $srv['nom_serveur']) ?></h3>
                        <p class="account-server-card__meta">
                            Minecraft <?= Security::e((string) $srv['version_mc']) ?> &middot; <?= Security::e(View::label('mode_jeu', $srv['mode_jeu'])) ?>
                            <?php if ((int) ($srv['est_public'] ?? 1) !== 1): ?>&middot; privé<?php endif; ?>
                        </p>
                        <dl class="account-server-card__specs">
                            <dt>Joueurs</dt>
                            <dd><?= $srvPlayers ?> / <?= $srvSlots ?></dd>
                            <dt>Mémoire</dt>
                            <dd><?= (int) $srv['ram_allouee'] ?> Mo</dd>
                            <dt>Port</dt>
                            <dd><code><?= (int) $srv['port'] ?></code></dd>
                        </dl>
                        <footer class="account-server-card__footer">
                            <a href="/serveur/dashboard?id=<?= $srvId ?>" class="account-server-card__btn account-server-card__btn--primary"><?= View::icon('layout-dashboard') ?> Gérer</a>
                            <a href="/serveur/console?id=<?= $srvId ?>" class="account-server-card__btn" title="Console"><?= View::icon('square-terminal') ?> Console</a>
                            <a href="/serveur-public?id=<?= $srvId ?>" class="account-server-card__btn" title="Fiche publique"><?= View::icon('external-link') ?></a>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h3 class="account__subsection-title"><?= View::icon('heart') ?> Serveurs favoris <span class="tabs__count"><?= count($favoriteServers) ?></span></h3>
        <?php if (empty($favoriteServers)): ?>
            <p class="account__empty">Aucun favori : ajoutez des serveurs depuis leur fiche publique (bouton « Ajouter aux favoris »).</p>
        <?php else: ?>
            <div class="card-grid account__favorites">
                <?php foreach ($favoriteServers as $fav): ?>
                    <?php
                    $card = [
                        'id' => $fav['id_serveur'], 'name' => $fav['nom_serveur'], 'version' => $fav['version_mc'], 'mode' => $fav['mode_jeu'],
                        'status' => $fav['statut'], 'players' => $fav['joueurs_connectes'], 'slots' => $fav['slots_max'],
                        'description' => $fav['description'], 'owner' => $fav['proprietaire_pseudo'], 'rating' => $fav['note_moyenne'],
                    ];
                    require __DIR__ . '/../../includes/cards/server-card.php';
                    ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- 2. CRÉATIONS -->
    <section id="section-creations" class="account__section account__section--creations" aria-labelledby="heading-creations">
        <header class="account__section-header">
            <div class="account__section-heading">
                <h2 id="heading-creations" class="account__section-title">Mes créations</h2>
                <p class="account__section-desc">Mods, plugins et modpacks publiés, avec leur adoption par la communauté.</p>
            </div>
            <div class="account__section-actions">
                <a href="/user/createur-edition?type=MOD" class="account__btn account__btn--primary"><?= View::icon('puzzle') ?> Publier un mod</a>
                <a href="/user/createur-edition?type=PLUGIN" class="account__btn account__btn--secondary"><?= View::icon('plug') ?> Un plugin</a>
                <a href="/user/createur-edition?type=MOD_PACK" class="account__btn account__btn--secondary"><?= View::icon('boxes') ?> Un modpack</a>
            </div>
        </header>

        <?php if (empty($myCreations)): ?>
            <p class="account__empty">Vous n'avez encore rien publié. Partagez votre premier mod, plugin ou modpack avec la communauté !</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="creations-table">
                    <caption class="visually-hidden">Ressources publiées par ce compte</caption>
                    <thead class="creations-table__head">
                        <tr class="creations-table__row">
                            <th scope="col" class="creations-table__cell">Nom</th>
                            <th scope="col" class="creations-table__cell">Type</th>
                            <th scope="col" class="creations-table__cell">Minecraft</th>
                            <th scope="col" class="creations-table__cell">Versions</th>
                            <th scope="col" class="creations-table__cell">Téléchargements</th>
                            <th scope="col" class="creations-table__cell">Note</th>
                            <th scope="col" class="creations-table__cell">Statut</th>
                            <th scope="col" class="creations-table__cell creations-table__cell--actions"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="creations-table__body">
                        <?php foreach ($myCreations as $res): ?>
                            <?php $resId = (int) $res['id_ressource']; ?>
                            <tr class="creations-table__row">
                                <th scope="row" class="creations-table__cell">
                                    <a href="/ressource-detail?id=<?= $resId ?>" class="creations-table__link"><?= Security::e((string) $res['nom_ressource']) ?></a>
                                </th>
                                <td class="creations-table__cell"><?= Security::e(View::label('type_ressource', $res['type_ressource'])) ?></td>
                                <td class="creations-table__cell"><?= Security::e((string) $res['version_compatible']) ?></td>
                                <td class="creations-table__cell"><?= (int) $res['nb_versions'] ?></td>
                                <td class="creations-table__cell"><?= View::number((int) $res['nb_telechargements']) ?></td>
                                <td class="creations-table__cell">
                                    <?php if ((int) $res['nb_avis'] > 0): ?>
                                        <span class="rating"><?= View::icon('star') ?> <?= View::number((float) $res['note_moyenne'], 1) ?></span> <small>(<?= (int) $res['nb_avis'] ?>)</small>
                                    <?php else: ?>
                                        <small>Pas d'avis</small>
                                    <?php endif; ?>
                                </td>
                                <td class="creations-table__cell"><?= View::badge('moderation', $res['statut_moderation']) ?></td>
                                <td class="creations-table__cell creations-table__cell--actions">
                                    <a href="/user/createur-edition?id=<?= $resId ?>" class="creations-table__btn"><?= View::icon('pencil') ?> Modifier</a>
                                    <a href="/user/createur-statistiques?id=<?= $resId ?>" class="creations-table__btn"><?= View::icon('chart-column') ?> Statistiques</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- 3. AMIS -->
    <section id="section-amis" class="account__section account__section--friends" aria-labelledby="heading-amis">
        <header class="account__section-header">
            <div class="account__section-heading">
                <h2 id="heading-amis" class="account__section-title">Amis</h2>
                <p class="account__section-desc">Retrouvez vos amis et invitez-les comme co-administrateurs de vos serveurs.</p>
            </div>
        </header>

        <form action="<?= Security::e($sectionUrl('section-amis')) ?>" method="post" class="friend-add-form">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="action" value="add_friend">
            <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">

            <label for="friend-pseudo-input" class="friend-add-form__label">Ajouter un ami par son pseudo</label>
            <div class="friend-add-form__controls">
                <div class="input-icon">
                    <?= View::icon('user-plus', 'input-icon__icon') ?>
                    <input type="text" id="friend-pseudo-input" name="friend_pseudo" placeholder="Ex. : Steve_MC" minlength="3" maxlength="32" required class="friend-add-form__input">
                </div>
                <button type="submit" class="friend-add-form__submit"><?= View::icon('send') ?> Envoyer la demande</button>
            </div>
        </form>

        <div class="friends-columns">
            <article class="friends-panel" aria-labelledby="friends-accepted-title">
                <h3 id="friends-accepted-title" class="friends-panel__title">Mes amis <span class="tabs__count"><?= count($acceptedFriends) ?></span></h3>
                <?php if (empty($acceptedFriends)): ?>
                    <p class="friends-panel__empty">Votre liste d'amis est vide pour le moment.</p>
                <?php else: ?>
                    <ul class="friends-list">
                        <?php foreach ($acceptedFriends as $friend): ?>
                            <li class="friends-list__item">
                                <span class="avatar" aria-hidden="true"><?= Security::e(View::initials($friend['friend_pseudo'])) ?></span>
                                <span class="friends-list__name">
                                    <strong><?= Security::e($friend['friend_pseudo']) ?></strong>
                                    <small>ami depuis le <?= Security::e(View::date($friend['since'])) ?></small>
                                </span>
                                <form action="<?= Security::e($sectionUrl('section-amis')) ?>" method="post" class="friends-list__inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="remove_friend">
                                    <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">
                                    <input type="hidden" name="friend_user_id" value="<?= $friend['friend_id'] ?>">
                                    <button type="submit" class="friends-list__btn friends-list__btn--remove" title="Retirer de mes amis" data-confirm="Retirer <?= Security::e($friend['friend_pseudo']) ?> de vos amis ?">Retirer</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>

            <article class="friends-panel" aria-labelledby="friends-pending-title">
                <h3 id="friends-pending-title" class="friends-panel__title">Demandes en attente <span class="tabs__count"><?= count($pendingReceived) + count($pendingSent) ?></span></h3>
                <?php if (empty($pendingReceived) && empty($pendingSent)): ?>
                    <p class="friends-panel__empty">Aucune demande en attente.</p>
                <?php else: ?>
                    <ul class="friends-list">
                        <?php foreach ($pendingReceived as $req): ?>
                            <li class="friends-list__item friends-list__item--incoming">
                                <span class="avatar" aria-hidden="true"><?= Security::e(View::initials($req['friend_pseudo'])) ?></span>
                                <span class="friends-list__name">
                                    <strong><?= Security::e($req['friend_pseudo']) ?></strong>
                                    <small>souhaite devenir votre ami</small>
                                </span>
                                <div class="friends-list__actions">
                                    <form action="<?= Security::e($sectionUrl('section-amis')) ?>" method="post" class="friends-list__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="accept_friend">
                                        <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">
                                        <input type="hidden" name="friend_user_id" value="<?= $req['friend_id'] ?>">
                                        <button type="submit" class="friends-list__btn friends-list__btn--accept"><?= View::icon('check') ?> Accepter</button>
                                    </form>
                                    <form action="<?= Security::e($sectionUrl('section-amis')) ?>" method="post" class="friends-list__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="remove_friend">
                                        <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">
                                        <input type="hidden" name="friend_user_id" value="<?= $req['friend_id'] ?>">
                                        <button type="submit" class="friends-list__btn friends-list__btn--decline">Refuser</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>

                        <?php foreach ($pendingSent as $sent): ?>
                            <li class="friends-list__item friends-list__item--outgoing">
                                <span class="avatar" aria-hidden="true"><?= Security::e(View::initials($sent['friend_pseudo'])) ?></span>
                                <span class="friends-list__name">
                                    <strong><?= Security::e($sent['friend_pseudo']) ?></strong>
                                    <small>invitation envoyée <?= Security::e(View::ago($sent['date'])) ?></small>
                                </span>
                                <form action="<?= Security::e($sectionUrl('section-amis')) ?>" method="post" class="friends-list__inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="remove_friend">
                                    <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">
                                    <input type="hidden" name="friend_user_id" value="<?= $sent['friend_id'] ?>">
                                    <button type="submit" class="friends-list__btn friends-list__btn--cancel">Annuler</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
        </div>
    </section>

    <!-- 4. SUPPORT -->
    <section id="section-tickets" class="account__section account__section--tickets" aria-labelledby="heading-tickets">
        <header class="account__section-header">
            <div class="account__section-heading">
                <h2 id="heading-tickets" class="account__section-title">Support</h2>
                <p class="account__section-desc">Une question, un incident, une demande de passage à Premium ? L'équipe vous répond ici.</p>
            </div>
        </header>

        <?php $premiumRequest = ($_GET['demande'] ?? '') === 'premium'; ?>
        <details class="ticket-creator"<?= empty($tickets) || $premiumRequest ? ' open' : '' ?>>
            <summary class="ticket-creator__summary"><?= View::icon('plus') ?> Ouvrir un ticket</summary>
            <form action="<?= Security::e($sectionUrl('section-tickets')) ?>" method="post" class="ticket-creator__form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="action" value="create_ticket">
                <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">

                <div class="ticket-creator__row">
                    <div class="ticket-creator__field">
                        <label for="tkt-sujet" class="ticket-creator__label">Sujet</label>
                        <input type="text" id="tkt-sujet" name="sujet" minlength="5" maxlength="120" required class="ticket-creator__input"<?= $premiumRequest ? ' value="Passage à l\'offre Premium"' : '' ?>>
                    </div>
                    <div class="ticket-creator__field">
                        <label for="tkt-cat" class="ticket-creator__label">Catégorie</label>
                        <select id="tkt-cat" name="categorie" class="ticket-creator__select">
                            <option value="TECHNIQUE">Incident serveur / plantage</option>
                            <option value="FACTURATION"<?= $premiumRequest ? ' selected' : '' ?>>Offre Premium et mémoire RAM</option>
                            <option value="MODERATION">Catalogue, ressource, modération</option>
                            <option value="RGPD">Données personnelles (RGPD)</option>
                        </select>
                    </div>
                </div>

                <div class="ticket-creator__field">
                    <label for="tkt-msg" class="ticket-creator__label">Votre message</label>
                    <textarea id="tkt-msg" name="message" rows="4" minlength="10" maxlength="3000" required class="ticket-creator__textarea"></textarea>
                </div>

                <button type="submit" class="ticket-creator__submit"><?= View::icon('send') ?> Envoyer le ticket</button>
            </form>
        </details>

        <?php if (empty($tickets)): ?>
            <p class="account__empty">Aucun ticket pour le moment.</p>
        <?php else: ?>
            <div class="ticket-list">
                <?php foreach ($tickets as $tkt): ?>
                    <?php
                    $tid      = (int) $tkt['id_ticket'];
                    $isClosed = strtoupper((string) $tkt['statut']) === 'FERME';
                    $messages = $ticketReplies[$tid] ?? [];
                    ?>
                    <details class="ticket-item"<?= !$isClosed && count($tickets) === 1 ? ' open' : '' ?>>
                        <summary class="ticket-item__summary">
                            <span class="ticket-item__id">#<?= $tid ?></span>
                            <strong class="ticket-item__subject"><?= Security::e((string) $tkt['sujet']) ?></strong>
                            <span class="ticket-item__category"><?= Security::e(View::label('categorie_ticket', $tkt['categorie'])) ?></span>
                            <?= View::badge('ticket', $tkt['statut']) ?>
                            <time class="ticket-item__date" datetime="<?= Security::e((string) $tkt['date_creation']) ?>"><?= Security::e(View::ago((string) $tkt['date_creation'])) ?></time>
                        </summary>

                        <div class="ticket-item__conversation">
                            <?php foreach ($messages as $m): ?>
                                <?php $isStaff = in_array(strtoupper((string) $m['auteur_role']), ['ADMIN', 'SUPER_ADMIN'], true); ?>
                                <article class="ticket-message<?= $isStaff ? ' ticket-message--staff' : '' ?>">
                                    <header class="ticket-message__header">
                                        <span class="avatar" aria-hidden="true"><?= Security::e(View::initials((string) $m['auteur_pseudo'])) ?></span>
                                        <strong class="ticket-message__author"><?= Security::e((string) $m['auteur_pseudo']) ?></strong>
                                        <?php if ($isStaff): ?>
                                            <span class="badge badge--accent">Équipe Boötes</span>
                                        <?php endif; ?>
                                        <time class="ticket-message__time" datetime="<?= Security::e((string) $m['date_envoi']) ?>"><?= Security::e(View::date((string) $m['date_envoi'], true)) ?></time>
                                    </header>
                                    <p class="ticket-message__body"><?= nl2br(Security::e((string) $m['contenu'])) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!$isClosed): ?>
                            <div class="ticket-item__actions">
                                <form action="<?= Security::e($sectionUrl('section-tickets')) ?>" method="post" class="ticket-item__reply-form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="reply_ticket">
                                    <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">
                                    <input type="hidden" name="ticket_id" value="<?= $tid ?>">

                                    <label for="reply-msg-<?= $tid ?>" class="ticket-item__reply-label">Votre réponse</label>
                                    <textarea id="reply-msg-<?= $tid ?>" name="message" rows="2" minlength="2" maxlength="3000" required class="ticket-item__reply-textarea"></textarea>
                                    <button type="submit" class="ticket-item__btn ticket-item__btn--reply"><?= View::icon('reply') ?> Répondre</button>
                                </form>

                                <form action="<?= Security::e($sectionUrl('section-tickets')) ?>" method="post" class="ticket-item__close-form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="close_ticket">
                                    <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">
                                    <input type="hidden" name="ticket_id" value="<?= $tid ?>">
                                    <button type="submit" class="ticket-item__btn ticket-item__btn--close"><?= View::icon('circle-check') ?> Marquer comme résolu</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- 5. PROFIL ET SÉCURITÉ -->
    <section id="section-profil" class="account__section account__section--profile" aria-labelledby="heading-profil">
        <header class="account__section-header">
            <div class="account__section-heading">
                <h2 id="heading-profil" class="account__section-title">Profil et sécurité</h2>
                <p class="account__section-desc">Identifiants de connexion, mot de passe et données personnelles.</p>
            </div>
        </header>

        <div class="account__profile-grid">
            <form action="<?= Security::e($sectionUrl('section-profil')) ?>" method="post" class="account-form account-form--identity">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">

                <fieldset class="account-form__fieldset">
                    <legend class="account-form__legend">Identifiants et mot de passe</legend>

                    <div class="account-form__field">
                        <label for="profile-pseudo" class="account-form__label">Pseudo</label>
                        <input type="text" id="profile-pseudo" name="pseudo" value="<?= Security::e((string) $userProfile['pseudo']) ?>" minlength="3" maxlength="32" required class="account-form__input">
                    </div>

                    <div class="account-form__field">
                        <label for="profile-email" class="account-form__label">Adresse e-mail</label>
                        <input type="email" id="profile-email" name="email" value="<?= Security::e((string) $userProfile['email']) ?>" required class="account-form__input">
                    </div>

                    <div class="account-form__field">
                        <label for="profile-new-pwd" class="account-form__label">Nouveau mot de passe <small>(laisser vide pour le conserver)</small></label>
                        <div class="input-icon password-field">
                            <?= View::icon('key-round', 'input-icon__icon') ?>
                            <input type="password" id="profile-new-pwd" name="new_password" minlength="10" autocomplete="new-password" class="account-form__input">
                            <button type="button" class="password-field__toggle" data-password-toggle aria-label="Afficher le mot de passe" aria-pressed="false" hidden>
                                <?= View::icon('eye', 'password-field__show') ?>
                                <?= View::icon('eye-off', 'password-field__hide') ?>
                            </button>
                        </div>
                    </div>

                    <?php if (!$isAdminView): ?>
                        <div class="account-form__field">
                            <label for="profile-current-pwd" class="account-form__label">Mot de passe actuel <small>(requis pour valider)</small></label>
                            <div class="input-icon">
                                <?= View::icon('lock', 'input-icon__icon') ?>
                                <input type="password" id="profile-current-pwd" name="current_password" required autocomplete="current-password" class="account-form__input">
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="account-form__submit"><?= View::icon('save') ?> Enregistrer</button>
                </fieldset>
            </form>

            <?php if ($isAdminView && Auth::isAdmin()): ?>
                <form action="<?= Security::e($sectionUrl('section-profil')) ?>" method="post" class="account-form account-form--moderation">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="admin_update_account">
                    <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">

                    <fieldset class="account-form__fieldset">
                        <legend class="account-form__legend">Modération et quotas</legend>

                        <div class="account-form__field">
                            <label for="admin-tier" class="account-form__label">Offre</label>
                            <select id="admin-tier" name="type_compte" class="account-form__select">
                                <option value="STANDARD" <?= !$isPremiumTier ? 'selected' : '' ?>>Standard (2 048 Mo max)</option>
                                <option value="PREMIUM" <?= $isPremiumTier ? 'selected' : '' ?>>Premium (jusqu'à 6 144 Mo)</option>
                            </select>
                        </div>

                        <div class="account-form__field">
                            <label for="admin-ram" class="account-form__label">RAM maximale allouable (Mo)</label>
                            <input type="number" id="admin-ram" name="max_ram_allouable" min="512" max="6144" step="256" value="<?= (int) $userProfile['max_ram_allouable'] ?>" class="account-form__input">
                        </div>

                        <div class="account-form__field account-form__field--checkbox">
                            <input type="checkbox" id="admin-ban" name="est_banni" value="1" class="account-form__checkbox" <?= !empty($userProfile['est_banni']) ? 'checked' : '' ?>>
                            <label for="admin-ban" class="account-form__checkbox-label">Suspendre ce compte (bannissement)</label>
                        </div>

                        <button type="submit" class="account-form__submit account-form__submit--warning"><?= View::icon('gavel') ?> Appliquer</button>
                    </fieldset>
                </form>
            <?php endif; ?>

            <details class="account__rgpd-box">
                <summary class="account__rgpd-summary"><?= View::icon('trash-2') ?> Supprimer le compte (droit à l'oubli, RGPD)</summary>
                <form action="<?= Security::e($formTargetUrl) ?>" method="post" class="account-form account-form--danger">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_account_rgpd">
                    <input type="hidden" name="target_user_id" value="<?= $targetUserId ?>">

                    <p class="account-form__warning">
                        Cette opération supprime définitivement les informations personnelles et les serveurs Minecraft du compte.
                        Choisissez le sort des ressources publiées :
                    </p>

                    <fieldset class="account-form__fieldset">
                        <legend class="account-form__legend">Devenir des créations publiées</legend>
                        <div class="account-form__radio-group">
                            <input type="radio" id="rgpd-anonymize" name="rgpd_resources_mode" value="anonymize" checked class="account-form__radio">
                            <label for="rgpd-anonymize" class="account-form__radio-label">
                                <strong>Les anonymiser</strong> : elles restent disponibles pour les serveurs de la communauté, sans lien avec votre identité.
                            </label>
                        </div>
                        <div class="account-form__radio-group">
                            <input type="radio" id="rgpd-delete-all" name="rgpd_resources_mode" value="delete_all" class="account-form__radio">
                            <label for="rgpd-delete-all" class="account-form__radio-label">
                                <strong>Les supprimer</strong> : tous les fichiers versionnés sont effacés et retirés du catalogue.
                            </label>
                        </div>
                    </fieldset>

                    <div class="account-form__field">
                        <label for="confirm-delete-input" class="account-form__label">Tapez <code>SUPPRIMER</code> pour confirmer</label>
                        <input type="text" id="confirm-delete-input" name="confirm_delete_text" required pattern="SUPPRIMER" class="account-form__input">
                    </div>

                    <?php if (!$isAdminView): ?>
                        <div class="account-form__field">
                            <label for="confirm-delete-password" class="account-form__label">Mot de passe actuel</label>
                            <input type="password" id="confirm-delete-password" name="current_password" required autocomplete="current-password" class="account-form__input">
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="account-form__submit account-form__submit--danger" data-confirm="Supprimer définitivement ce compte et toutes ses données ? Cette action est irréversible.">
                        Supprimer définitivement le compte
                    </button>
                </form>
            </details>
        </div>
    </section>
</div>
