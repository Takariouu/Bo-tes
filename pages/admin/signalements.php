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

if (!Auth::isAdmin()) {
    http_response_code(403);
    ?>
    <section class="admin-reports admin-reports--forbidden">
        <h1 class="admin-reports__title">Accès refusé</h1>
        <p class="admin-reports__message">Le centre de modération des signalements est réservé aux Administrateurs.</p>
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 35) ?? '';
    $reportId = Security::validatePositiveInt($_POST['report_id'] ?? null);

    if (!Security::verifyCsrfToken($postCsrf) || $reportId === null) {
        $feedbackError = 'Requête invalide ou jeton CSRF expiré.';
    } else {
        try {
            $repStmt = $db->prepare('
                SELECT id_signalement, type_cible, id_cible, motif, statut
                FROM SIGNALEMENT
                WHERE id_signalement = :rid
                LIMIT 1
            ');
            $repStmt->execute([':rid' => $reportId]);
            $report = $repStmt->fetch(PDO::FETCH_ASSOC);

            if (!$report) {
                $feedbackError = 'Ce signalement est introuvable.';
            } else {
                $targetType = strtoupper((string) $report['type_cible']);
                $targetId   = (int) $report['id_cible'];

                if ($action === 'ignore_report') {
                    $upd = $db->prepare("UPDATE SIGNALEMENT SET statut = 'IGNORE' WHERE id_signalement = :rid");
                    $upd->execute([':rid' => $reportId]);
                    $feedbackMessage = sprintf('Signalement #%d classé sans suite (Ignoré).', $reportId);
                } elseif ($action === 'delete_reported_content') {
                    $removed = true;
                    if ($targetType === 'RESSOURCE') {
                        $removed = $resourceManager->deleteResource($targetId);
                        $feedbackMessage = sprintf('La ressource signalée #%d a été supprimée et le signalement #%d a été résolu.', $targetId, $reportId);
                    } elseif ($targetType === 'SERVEUR') {
                        $serverManager->forceKillServer($targetId, $currentUserId);
                        $removed = $serverManager->deleteServer($targetId);
                        $feedbackMessage = sprintf('Le serveur signalé #%d a été arrêté et supprimé avec ses données.', $targetId);
                    } else {
                        $feedbackMessage = sprintf('Contenu cible #%d retiré.', $targetId);
                    }

                    if ($removed) {
                        $db->prepare("UPDATE SIGNALEMENT SET statut = 'TRAITE' WHERE id_signalement = :rid")
                           ->execute([':rid' => $reportId]);
                    } else {
                        $feedbackMessage = null;
                        $feedbackError   = sprintf('La suppression du contenu #%d a échoué : le signalement reste ouvert.', $targetId);
                    }
                } elseif ($action === 'ban_reported_user') {
                    $offenderId = null;

                    if ($targetType === 'UTILISATEUR') {
                        $offenderId = $targetId;
                    } elseif ($targetType === 'SERVEUR') {
                        $ownerStmt = $db->prepare('SELECT id_proprietaire FROM SERVEUR WHERE id_serveur = :sid LIMIT 1');
                        $ownerStmt->execute([':sid' => $targetId]);
                        $foundOwner = $ownerStmt->fetchColumn();
                        if ($foundOwner !== false) {
                            $offenderId = (int) $foundOwner;
                        }
                    } elseif ($targetType === 'RESSOURCE') {
                        $authStmt = $db->prepare('SELECT id_auteur FROM RESSOURCE WHERE id_ressource = :rid LIMIT 1');
                        $authStmt->execute([':rid' => $targetId]);
                        $foundAuthor = $authStmt->fetchColumn();
                        if ($foundAuthor !== false) {
                            $offenderId = (int) $foundAuthor;
                        }
                    }

                    $offenderRole = $offenderId !== null ? Auth::getUserRole($offenderId, $db) : null;

                    if ($offenderId === null || $offenderId === $currentUserId || $offenderRole === null) {
                        $feedbackError = 'Impossible d\'identifier ou de bannir le propriétaire de ce contenu.';
                    } elseif (!Auth::canAdministrate(Auth::getRole(), $offenderRole)) {
                        $feedbackError = 'Ce compte est de rang égal ou supérieur au vôtre : le bannissement doit être décidé par un Super-Administrateur.';
                    } else {
                        $db->prepare('UPDATE UTILISATEUR SET est_banni = 1 WHERE id_utilisateur = :uid')
                           ->execute([':uid' => $offenderId]);

                        $db->prepare("UPDATE SERVEUR SET statut = 'HORS_LIGNE', joueurs_connectes = 0 WHERE id_proprietaire = :uid")
                           ->execute([':uid' => $offenderId]);

                        $db->prepare("UPDATE RESSOURCE SET statut_moderation = 'SUSPENDU' WHERE id_auteur = :uid")
                           ->execute([':uid' => $offenderId]);

                        $db->prepare("UPDATE SIGNALEMENT SET statut = 'TRAITE' WHERE id_signalement = :rid")
                           ->execute([':rid' => $reportId]);

                        $feedbackMessage = sprintf(
                            'Utilisateur responsable (#%d) banni, ses serveurs ont été éteints et ses ressources suspendues (Signalement #%d traité).',
                            $offenderId,
                            $reportId
                        );
                    }
                }

                // Journalisation d'audit dans LOG_SYSTEME
                try {
                    $logStmt = $db->prepare('
                        INSERT INTO LOG_SYSTEME (id_utilisateur, niveau, action, details, adresse_ip, date_log)
                        VALUES (:uid, \'WARNING\', :act, :details, :ip, NOW())
                    ');
                    $logStmt->execute([
                        ':uid'     => $currentUserId,
                        ':act'     => strtoupper($action),
                        ':details' => sprintf('Traitement signalement #%d (%s #%d) via action %s.', $reportId, $targetType, $targetId, $action),
                        ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    ]);
                } catch (PDOException $e) {
                    error_log(sprintf('[Boötes][Signalements] Log audit ignoré : %s', $e->getMessage()));
                }
            }
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][Signalements] Erreur traitement modération : %s', $e->getMessage()));
            $feedbackError = 'Une erreur technique est survenue lors du traitement du signalement.';
        }
    }
}

$statusFilter = strtoupper(Security::validateString($_GET['statut'] ?? 'EN_ATTENTE', 3, 20) ?? 'EN_ATTENTE');
$typeFilter   = strtoupper(Security::validateString($_GET['type'] ?? 'ALL', 3, 20) ?? 'ALL');

$reports = [];
try {
    $where  = ['1 = 1'];
    $params = [];

    if (in_array($statusFilter, ['EN_ATTENTE', 'TRAITE', 'IGNORE'], true)) {
        $where[]           = 'sig.statut = :statut';
        $params[':statut'] = $statusFilter;
    }

    if (in_array($typeFilter, ['SERVEUR', 'RESSOURCE', 'UTILISATEUR'], true)) {
        $where[]         = 'UPPER(sig.type_cible) = :type';
        $params[':type'] = $typeFilter;
    }

    $sql = '
        SELECT 
            sig.id_signalement,
            sig.id_auteur_signalement,
            sig.type_cible,
            sig.id_cible,
            sig.motif,
            sig.description,
            sig.statut,
            sig.date_signalement,
            u_rep.pseudo AS signaleur_pseudo,
            CASE 
                WHEN UPPER(sig.type_cible) = \'SERVEUR\' THEN s.nom_serveur
                WHEN UPPER(sig.type_cible) = \'RESSOURCE\' THEN r.nom_ressource
                WHEN UPPER(sig.type_cible) = \'UTILISATEUR\' THEN u_cible.pseudo
                ELSE CONCAT(\'Cible #\', sig.id_cible)
            END AS nom_cible,
            CASE 
                WHEN UPPER(sig.type_cible) = \'SERVEUR\' THEN s.id_proprietaire
                WHEN UPPER(sig.type_cible) = \'RESSOURCE\' THEN r.id_auteur
                WHEN UPPER(sig.type_cible) = \'UTILISATEUR\' THEN u_cible.id_utilisateur
                ELSE NULL
            END AS id_responsable
        FROM SIGNALEMENT sig
        LEFT JOIN UTILISATEUR u_rep ON u_rep.id_utilisateur = sig.id_auteur_signalement
        LEFT JOIN SERVEUR s ON UPPER(sig.type_cible) = \'SERVEUR\' AND s.id_serveur = sig.id_cible
        LEFT JOIN RESSOURCE r ON UPPER(sig.type_cible) = \'RESSOURCE\' AND r.id_ressource = sig.id_cible
        LEFT JOIN UTILISATEUR u_cible ON UPPER(sig.type_cible) = \'UTILISATEUR\' AND u_cible.id_utilisateur = sig.id_cible
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY sig.statut = \'EN_ATTENTE\' DESC, sig.date_signalement DESC
        LIMIT 100
    ';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Signalements] Erreur chargement signalements : %s', $e->getMessage()));
}
?>
<div class="admin-reports">
    <header class="admin-reports__header">
        <div class="admin-reports__heading">
            <p class="admin-reports__eyebrow">Sécurité Communautaire &amp; Conformité</p>
            <h1 class="admin-reports__title">Centre de Modération des Signalements</h1>
            <p class="admin-reports__subtitle">
                Examinez les signalements portant sur les serveurs, les ressources ou les comptes utilisateurs.
            </p>
        </div>

        <form action="/admin/signalements" method="get" class="admin-reports__filter-form">
            <div class="admin-reports__field">
                <label for="rep-statut" class="admin-reports__label">État du dossier</label>
                <select id="rep-statut" name="statut" class="admin-reports__select">
                    <option value="EN_ATTENTE" <?= $statusFilter === 'EN_ATTENTE' ? 'selected' : '' ?>>En attente de modération</option>
                    <option value="TRAITE" <?= $statusFilter === 'TRAITE' ? 'selected' : '' ?>>Traités / Résolus</option>
                    <option value="IGNORE" <?= $statusFilter === 'IGNORE' ? 'selected' : '' ?>>Ignorés / Sans suite</option>
                    <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : '' ?>>Tous les signalements</option>
                </select>
            </div>

            <div class="admin-reports__field">
                <label for="rep-type" class="admin-reports__label">Nature de la cible</label>
                <select id="rep-type" name="type" class="admin-reports__select">
                    <option value="ALL" <?= $typeFilter === 'ALL' ? 'selected' : '' ?>>Toutes les cibles</option>
                    <option value="SERVEUR" <?= $typeFilter === 'SERVEUR' ? 'selected' : '' ?>>Serveurs Minecraft</option>
                    <option value="RESSOURCE" <?= $typeFilter === 'RESSOURCE' ? 'selected' : '' ?>>Mods / Plugins / Modpacks</option>
                    <option value="UTILISATEUR" <?= $typeFilter === 'UTILISATEUR' ? 'selected' : '' ?>>Utilisateurs</option>
                </select>
            </div>

            <button type="submit" class="admin-reports__submit">Filtrer</button>
        </form>
    </header>

    <?php if ($feedbackMessage !== null): ?>
        <div class="admin-reports__alert admin-reports__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="admin-reports__alert admin-reports__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <section class="admin-reports__list-section" aria-labelledby="reports-list-heading">
        <h2 id="reports-list-heading" class="admin-reports__section-title">
            Dossiers de signalement (<?= count($reports) ?>)
        </h2>

        <?php if (empty($reports)): ?>
            <article class="admin-reports__empty">
                <h3 class="admin-reports__empty-title">Aucun signalement dans cette vue</h3>
                <p class="admin-reports__empty-text">Tous les dossiers correspondants ont été traités par l'équipe de modération.</p>
            </article>
        <?php else: ?>
            <div class="report-cards">
                <?php foreach ($reports as $rep): ?>
                    <?php
                    $repId      = (int) $rep['id_signalement'];
                    $cibleType  = strtoupper((string) $rep['type_cible']);
                    $cibleId    = (int) $rep['id_cible'];
                    $isPending  = strtoupper((string) $rep['statut']) === 'EN_ATTENTE';

                    $inspectUrl = '/admin/utilisateurs';
                    if ($cibleType === 'SERVEUR') {
                        $inspectUrl = '/serveur/dashboard?id=' . $cibleId;
                    } elseif ($cibleType === 'RESSOURCE') {
                        $inspectUrl = '/ressource-detail?id=' . $cibleId;
                    } elseif ($cibleType === 'UTILISATEUR') {
                        $inspectUrl = '/user/compte?id=' . $cibleId;
                    }
                    ?>
                    <article class="moderation-card">
                        <header class="moderation-card__header">
                            <div class="moderation-card__badges">
                                <span class="moderation-card__id">Signalement #<?= $repId ?></span>
                                <span class="moderation-card__type"><?= Security::e(View::label('cible', $cibleType)) ?> #<?= $cibleId ?></span>
                                <span class="moderation-card__motif">Motif : <?= Security::e(View::label('motif', $rep['motif'])) ?></span>
                                <?= View::badge('signalement', $rep['statut']) ?>
                            </div>
                            <time class="moderation-card__date" datetime="<?= Security::e((string) $rep['date_signalement']) ?>">
                                <?= Security::e((string) $rep['date_signalement']) ?>
                            </time>
                        </header>

                        <div class="moderation-card__body">
                            <h3 class="moderation-card__target-title">
                                Cible :
                                <a href="<?= Security::e($inspectUrl) ?>" class="moderation-card__target-link">
                                    <?= Security::e((string) ($rep['nom_cible'] ?? ('Élément #' . $cibleId))) ?>
                                </a>
                            </h3>
                            <p class="moderation-card__reporter">
                                Signalé par : <strong><?= Security::e((string) ($rep['signaleur_pseudo'] ?? 'Utilisateur supprimé')) ?></strong>
                                <?php if (!empty($rep['id_responsable'])): ?>
                                    &bull; <a href="/user/compte?id=<?= (int) $rep['id_responsable'] ?>">Voir le profil du responsable (#<?= (int) $rep['id_responsable'] ?>)</a>
                                <?php endif; ?>
                            </p>
                            <blockquote class="moderation-card__description">
                                <p><?= nl2br(Security::e((string) $rep['description'])) ?></p>
                            </blockquote>
                        </div>

                        <footer class="moderation-card__footer">
                            <a href="<?= Security::e($inspectUrl) ?>" class="moderation-card__btn">Inspecter la cible</a>

                            <?php if ($isPending): ?>
                                <form action="/admin/signalements" method="post" class="moderation-card__form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="report_id" value="<?= $repId ?>">
                                    <input type="hidden" name="action" value="ignore_report">
                                    <button type="submit" class="moderation-card__btn moderation-card__btn--ignore">
                                        Ignorer
                                    </button>
                                </form>

                                <form action="/admin/signalements" method="post" class="moderation-card__form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="report_id" value="<?= $repId ?>">
                                    <input type="hidden" name="action" value="delete_reported_content">
                                    <button type="submit" class="moderation-card__btn moderation-card__btn--delete" data-confirm="Supprimer définitivement le contenu signalé ?">
                                        Supprimer le contenu signalé
                                    </button>
                                </form>

                                <form action="/admin/signalements" method="post" class="moderation-card__form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="report_id" value="<?= $repId ?>">
                                    <input type="hidden" name="action" value="ban_reported_user">
                                    <button type="submit" class="moderation-card__btn moderation-card__btn--ban" data-confirm="Bannir le responsable, arrêter ses serveurs et suspendre ses ressources ?">
                                        Bannir l'utilisateur responsable
                                    </button>
                                </form>
                            <?php endif; ?>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>