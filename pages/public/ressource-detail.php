<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Http;
use Bootes\Src\RateLimiter;
use Bootes\Src\ResourceManager;
use Bootes\Src\Security;

$db              = $db ?? Database::getInstance();
$resourceManager = new ResourceManager($db);

$resourceId    = Security::validatePositiveInt($_GET['id'] ?? $_POST['resource_id'] ?? null);
$currentUserId = Auth::getUserId();
$csrfToken     = Security::generateCsrfToken();

$feedbackMessage = null;
$feedbackError   = null;

if ($resourceId === null) {
    ?>
    <section class="resource-detail resource-detail--not-found">
        <h1 class="resource-detail__title">Ressource introuvable</h1>
        <p class="resource-detail__message">Aucun identifiant de ressource valide n'a été transmis.</p>
        <a href="/catalogue" class="resource-detail__back-link">Retourner au catalogue</a>
    </section>
    <?php
    return;
}

$resource = null;
try {
    $stmt = $db->prepare('
        SELECT 
            r.id_ressource,
            r.id_auteur,
            r.nom_ressource,
            r.type_ressource,
            r.version_compatible,
            r.resume,
            r.description,
            r.nb_telechargements,
            r.chemin_fichier,
            r.statut_moderation,
            r.date_publication,
            u.pseudo AS auteur_pseudo
        FROM RESSOURCE r
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = r.id_auteur
        WHERE r.id_ressource = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $resourceId]);
    $resource = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][RessourceDetail] Erreur lecture ressource : %s', $e->getMessage()));
}

if ($resource === null) {
    ?>
    <section class="resource-detail resource-detail--not-found">
        <h1 class="resource-detail__title">Ressource inexistante</h1>
        <p class="resource-detail__message">Ce mod, plugin ou modpack n'existe plus dans la base de données.</p>
        <a href="/catalogue" class="resource-detail__back-link">Retourner au catalogue</a>
    </section>
    <?php
    return;
}

$isCreator   = $currentUserId !== null && $currentUserId === (int) $resource['id_auteur'];
$canModerate = $isCreator || Auth::isAdmin();
$isAvailable = ResourceManager::isPubliclyAvailable($resource);

// Une ressource en attente ou suspendue n'est visible que de son auteur et des administrateurs.
if (!$isAvailable && !$canModerate) {
    http_response_code(404);
    ?>
    <section class="resource-detail resource-detail--not-found">
        <h1 class="resource-detail__title">Ressource indisponible</h1>
        <p class="resource-detail__message">Ce mod, plugin ou modpack n'existe pas ou a été retiré du catalogue par la modération.</p>
        <a href="/catalogue" class="resource-detail__back-link">Retourner au catalogue</a>
    </section>
    <?php
    return;
}

// Traitement des formulaires POST (Téléchargement, Installation sur serveur, Avis, Signalement, Modération)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 30) ?? '';

    if (!Security::verifyCsrfToken($postCsrf)) {
        $feedbackError = 'Jeton CSRF invalide. Veuillez recharger la page.';
    } elseif (!$isAvailable && in_array($action, ['install_on_server', 'add_review', 'report'], true)) {
        $feedbackError = 'Cette ressource n\'est pas validée : action indisponible.';
    } else {
        try {
            if ($action === 'download') {
                $latestFile = $resourceManager->getLatestVersionFile($resourceId);
                $filePath   = $latestFile !== null ? $resourceManager->resolveStoredFile((string) $latestFile['chemin_fichier']) : null;

                if ($latestFile === null || $filePath === null) {
                    $feedbackError = 'Aucun fichier n\'est encore disponible au téléchargement pour cette ressource.';
                } else {
                    $resourceManager->registerDownload($resourceId, (int) $latestFile['id_fichier']);
                    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    Http::sendFile(
                        $filePath,
                        (string) $latestFile['nom_fichier'],
                        ResourceManager::ALLOWED_ARCHIVE_EXTENSIONS[$extension] ?? 'application/octet-stream'
                    );
                }
            } elseif ($action === 'install_on_server' && $currentUserId !== null) {
                $targetServerId = Security::validatePositiveInt($_POST['target_server_id'] ?? null);
                if ($targetServerId !== null && Auth::canManageServer($currentUserId, $targetServerId, $db, Auth::SERVER_PERM_FULL)) {
                    $install = $resourceManager->installOnServer($targetServerId, $resourceId);
                    if ($install['success']) {
                        $feedbackMessage = sprintf('La ressource a été installée avec succès sur votre serveur #%d.', $targetServerId);
                    } else {
                        $feedbackError = $install['message'];
                    }
                } else {
                    $feedbackError = 'Vous n\'avez pas les droits nécessaires (niveau COMPLET) sur le serveur sélectionné.';
                }
            } elseif ($action === 'add_review' && $currentUserId !== null && $isCreator) {
                $feedbackError = 'Vous ne pouvez pas évaluer votre propre ressource.';
            } elseif ($action === 'add_review' && $currentUserId !== null) {
                $note        = Security::validatePositiveInt($_POST['note'] ?? null);
                $commentaire = Security::validateString($_POST['commentaire'] ?? '', 5, 1000);

                if ($note === null || $note < 1 || $note > 5 || $commentaire === null) {
                    $feedbackError = 'Veuillez attribuer une note entre 1 et 5 et rédiger un avis (5 à 1000 caractères).';
                } else {
                    $reviewStmt = $db->prepare('
                        INSERT INTO AVIS_RESSOURCE (id_ressource, id_utilisateur, note, commentaire, date_avis)
                        VALUES (:res_id, :user_id, :note, :commentaire, NOW())
                        ON DUPLICATE KEY UPDATE note = VALUES(note), commentaire = VALUES(commentaire), date_avis = NOW()
                    ');
                    $reviewStmt->execute([
                        ':res_id'      => $resourceId,
                        ':user_id'     => $currentUserId,
                        ':note'        => $note,
                        ':commentaire' => $commentaire,
                    ]);
                    $feedbackMessage = 'Votre évaluation a bien été enregistrée.';
                }
            } elseif ($action === 'report' && $currentUserId !== null) {
                $motif       = Security::validateString($_POST['motif'] ?? '', 3, 100);
                $description = Security::validateString($_POST['description_signalement'] ?? '', 10, 1000);
                $reportKey   = 'report:user:' . $currentUserId;
                $limiter     = RateLimiter::default();

                if ($limiter->tooManyAttempts($reportKey, 10)) {
                    $feedbackError = 'Vous avez envoyé trop de signalements récemment. Réessayez plus tard.';
                } elseif ($motif === null || $description === null) {
                    $feedbackError = 'Veuillez préciser un motif et détailler votre signalement (10 caractères minimum).';
                } else {
                    $reportStmt = $db->prepare('
                        INSERT INTO SIGNALEMENT (id_auteur_signalement, type_cible, id_cible, motif, description, statut, date_signalement)
                        VALUES (:user_id, :type_cible, :id_cible, :motif, :description, :statut, NOW())
                    ');
                    $reportStmt->execute([
                        ':user_id'     => $currentUserId,
                        ':type_cible'  => 'RESSOURCE',
                        ':id_cible'    => $resourceId,
                        ':motif'       => $motif,
                        ':description' => $description,
                        ':statut'      => 'EN_ATTENTE',
                    ]);
                    $limiter->hit($reportKey, 3600);
                    $feedbackMessage = 'Signalement transmis à l\'équipe de modération Boötes.';
                }
            } elseif ($action === 'moderate_delete' && $canModerate) {
                $modStmt = $db->prepare("UPDATE RESSOURCE SET statut_moderation = 'SUSPENDU' WHERE id_ressource = :id");
                $modStmt->execute([':id' => $resourceId]);
                $resource['statut_moderation'] = 'SUSPENDU';
                $feedbackMessage = 'La ressource a été suspendue / masquée du catalogue public.';
            }
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][RessourceDetail] Erreur action POST : %s', $e->getMessage()));
            $feedbackError = 'Une erreur de base de données est survenue lors du traitement de votre demande.';
        }
    }
}

// Chargement des versions/fichiers de la ressource
$versionsList = [];
try {
    $verStmt = $db->prepare('
        SELECT id_fichier, numero_version, version_mc, nom_fichier, taille_octets, date_ajout
        FROM FICHIER_RESSOURCE
        WHERE id_ressource = :id
        ORDER BY date_ajout DESC
    ');
    $verStmt->execute([':id' => $resourceId]);
    $versionsList = $verStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][RessourceDetail] Info fichiers : %s', $e->getMessage()));
}

// Chargement des avis utilisateurs
$reviews = [];
$averageRating = 0.0;
try {
    $revStmt = $db->prepare('
        SELECT ar.note, ar.commentaire, ar.date_avis, u.pseudo
        FROM AVIS_RESSOURCE ar
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = ar.id_utilisateur
        WHERE ar.id_ressource = :id
        ORDER BY ar.date_avis DESC
    ');
    $revStmt->execute([':id' => $resourceId]);
    $reviews = $revStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($reviews)) {
        $sum = array_sum(array_map(static fn(array $r): int => (int) $r['note'], $reviews));
        $averageRating = round($sum / count($reviews), 1);
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][RessourceDetail] Info avis : %s', $e->getMessage()));
}

// Chargement des serveurs administrables par l'utilisateur connecté pour l'installation en 1 clic
$userServers = [];
if ($currentUserId !== null) {
    try {
        $srvStmt = $db->prepare('
            SELECT DISTINCT s.id_serveur, s.nom_serveur, s.version_mc
            FROM SERVEUR s
            LEFT JOIN ADMINISTRER_SERVEUR ads ON ads.id_serveur = s.id_serveur
            WHERE s.id_proprietaire = :uid_owner OR ads.id_utilisateur = :uid_admin
            ORDER BY s.nom_serveur ASC
        ');
        $srvStmt->execute([
            ':uid_owner' => $currentUserId,
            ':uid_admin' => $currentUserId,
        ]);
        $userServers = $srvStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log(sprintf('[Boötes][RessourceDetail] Info serveurs utilisateur : %s', $e->getMessage()));
    }
}
?>
<article class="resource-detail" aria-labelledby="resource-main-title">
    <?php if ($feedbackMessage !== null): ?>
        <div class="resource-detail__alert resource-detail__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="resource-detail__alert resource-detail__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <header class="resource-detail__header">
        <div class="resource-detail__heading">
            <span class="resource-detail__badge">
                <?= Security::e(strtoupper((string) $resource['type_ressource'])) ?>
            </span>
            <span class="resource-detail__mc-version">
                Minecraft <?= Security::e((string) $resource['version_compatible']) ?>
            </span>
            <h1 id="resource-main-title" class="resource-detail__title">
                <?= Security::e((string) $resource['nom_ressource']) ?>
            </h1>
            <p class="resource-detail__byline">
                Publié par <strong><?= Security::e((string) $resource['auteur_pseudo']) ?></strong>
                le <time datetime="<?= Security::e((string) $resource['date_publication']) ?>">
                    <?= Security::e((string) $resource['date_publication']) ?>
                </time>
            </p>
        </div>

        <dl class="resource-detail__stats">
            <div class="resource-detail__stat-item">
                <dt class="resource-detail__stat-label">Téléchargements</dt>
                <dd class="resource-detail__stat-value"><?= (int) $resource['nb_telechargements'] ?></dd>
            </div>
            <div class="resource-detail__stat-item">
                <dt class="resource-detail__stat-label">Note moyenne</dt>
                <dd class="resource-detail__stat-value"><?= Security::e((string) $averageRating) ?> / 5 (<?= count($reviews) ?> avis)</dd>
            </div>
            <div class="resource-detail__stat-item">
                <dt class="resource-detail__stat-label">Statut</dt>
                <dd class="resource-detail__stat-value"><?= Security::e((string) $resource['statut_moderation']) ?></dd>
            </div>
        </dl>
    </header>

    <?php if ($canModerate): ?>
        <aside class="resource-detail__moderation" aria-label="Outils d'administration et du créateur">
            <h2 class="resource-detail__moderation-title">Gestion Créateur / Modération</h2>
            <div class="resource-detail__moderation-actions">
                <a href="/user/createur-edition?id=<?= $resourceId ?>" class="resource-detail__btn resource-detail__btn--edit">
                    Modifier la ressource
                </a>
                <a href="/user/createur-statistiques?id=<?= $resourceId ?>" class="resource-detail__btn resource-detail__btn--stats">
                    Voir les statistiques détaillées
                </a>
                <form action="/ressource-detail?id=<?= $resourceId ?>" method="post" class="resource-detail__inline-form">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="moderate_delete">
                    <button type="submit" class="resource-detail__btn resource-detail__btn--danger" data-confirm="Retirer cette ressource du catalogue public ?">
                        Suspendre / Retirer du catalogue
                    </button>
                </form>
            </div>
        </aside>
    <?php endif; ?>

    <div class="resource-detail__body">
        <section class="resource-detail__description-section" aria-labelledby="desc-heading">
            <h2 id="desc-heading" class="resource-detail__section-title">Description complète</h2>
            <?php if (!empty($resource['resume'])): ?>
                <p class="resource-detail__lead"><?= Security::e((string) $resource['resume']) ?></p>
            <?php endif; ?>
            <div class="resource-detail__content">
                <p><?= nl2br(Security::e((string) $resource['description'])) ?></p>
            </div>
        </section>

        <section class="resource-detail__install-section" aria-labelledby="install-heading">
            <h2 id="install-heading" class="resource-detail__section-title">Téléchargement et déploiement</h2>

            <form action="/ressource-detail?id=<?= $resourceId ?>" method="post" class="resource-detail__download-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="action" value="download">
                <button type="submit" class="resource-detail__btn resource-detail__btn--primary" <?= empty($versionsList) ? 'disabled' : '' ?>>
                    <?php if (empty($versionsList)): ?>
                        Aucun fichier publié pour le moment
                    <?php else: ?>
                        Télécharger la dernière version (<?= Security::e((string) $versionsList[0]['nom_fichier']) ?>)
                    <?php endif; ?>
                </button>
            </form>

            <?php if (Auth::isLogged()): ?>
                <form action="/ressource-detail?id=<?= $resourceId ?>" method="post" class="resource-detail__deploy-form">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="install_on_server">

                    <label for="target-server-select" class="resource-detail__label">
                        Installer directement sur l'un de mes serveurs Boötes :
                    </label>
                    <?php if (empty($userServers)): ?>
                        <p class="resource-detail__notice">
                            Vous n'avez aucun serveur actif. <a href="/user/compte">Créez un serveur depuis votre espace</a>.
                        </p>
                    <?php else: ?>
                        <div class="resource-detail__deploy-controls">
                            <select id="target-server-select" name="target_server_id" required class="resource-detail__select">
                                <?php foreach ($userServers as $srv): ?>
                                    <option value="<?= (int) $srv['id_serveur'] ?>">
                                        <?= Security::e((string) $srv['nom_serveur']) ?> (MC <?= Security::e((string) $srv['version_mc']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="resource-detail__btn resource-detail__btn--secondary">
                                Installer sur ce serveur
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php else: ?>
                <p class="resource-detail__login-prompt">
                    <a href="/auth?mode=login">Connectez-vous</a> pour installer directement cette ressource sur votre serveur Minecraft en un clic.
                </p>
            <?php endif; ?>
        </section>

        <section class="resource-detail__versions-section" aria-labelledby="versions-heading">
            <h2 id="versions-heading" class="resource-detail__section-title">Fichiers et versions compatibles</h2>
            <?php if (empty($versionsList)): ?>
                <p class="resource-detail__info">
                    Version principale : <strong><?= Security::e((string) $resource['version_compatible']) ?></strong>
                </p>
            <?php else: ?>
                <table class="versions-table">
                    <caption class="versions-table__caption">Historique des versions de la ressource</caption>
                    <thead class="versions-table__head">
                        <tr class="versions-table__row">
                            <th scope="col" class="versions-table__cell">Version</th>
                            <th scope="col" class="versions-table__cell">Compatibilité MC</th>
                            <th scope="col" class="versions-table__cell">Fichier</th>
                            <th scope="col" class="versions-table__cell">Taille</th>
                            <th scope="col" class="versions-table__cell">Date</th>
                        </tr>
                    </thead>
                    <tbody class="versions-table__body">
                        <?php foreach ($versionsList as $ver): ?>
                            <tr class="versions-table__row">
                                <td class="versions-table__cell"><?= Security::e((string) $ver['numero_version']) ?></td>
                                <td class="versions-table__cell">MC <?= Security::e((string) $ver['version_mc']) ?></td>
                                <td class="versions-table__cell"><?= Security::e((string) $ver['nom_fichier']) ?></td>
                                <td class="versions-table__cell"><?= round(((int) $ver['taille_octets']) / 1024, 1) ?> Ko</td>
                                <td class="versions-table__cell"><?= Security::e((string) $ver['date_ajout']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="resource-detail__reviews-section" aria-labelledby="reviews-heading">
            <h2 id="reviews-heading" class="resource-detail__section-title">Notes et avis de la communauté</h2>

            <?php if (Auth::isLogged()): ?>
                <form action="/ressource-detail?id=<?= $resourceId ?>" method="post" class="review-form">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="add_review">

                    <div class="review-form__field">
                        <label for="review-note" class="review-form__label">Votre note (sur 5)</label>
                        <select id="review-note" name="note" required class="review-form__select">
                            <option value="5">5 / 5 — Excellent</option>
                            <option value="4">4 / 5 — Très bon</option>
                            <option value="3">3 / 5 — Correct</option>
                            <option value="2">2 / 5 — Insuffisant</option>
                            <option value="1">1 / 5 — Déconseillé</option>
                        </select>
                    </div>

                    <div class="review-form__field">
                        <label for="review-comment" class="review-form__label">Votre avis détaillé</label>
                        <textarea
                            id="review-comment"
                            name="commentaire"
                            rows="3"
                            minlength="5"
                            maxlength="1000"
                            required
                            class="review-form__textarea"
                        ></textarea>
                    </div>

                    <button type="submit" class="review-form__submit">Publier mon avis</button>
                </form>
            <?php endif; ?>

            <?php if (empty($reviews)): ?>
                <p class="resource-detail__empty-reviews">Aucun avis n'a encore été déposé sur cette ressource.</p>
            <?php else: ?>
                <div class="review-list">
                    <?php foreach ($reviews as $rev): ?>
                        <article class="review-card">
                            <header class="review-card__header">
                                <strong class="review-card__author"><?= Security::e((string) $rev['pseudo']) ?></strong>
                                <span class="review-card__rating">Note : <?= (int) $rev['note'] ?> / 5</span>
                                <time class="review-card__date" datetime="<?= Security::e((string) $rev['date_avis']) ?>">
                                    <?= Security::e((string) $rev['date_avis']) ?>
                                </time>
                            </header>
                            <p class="review-card__comment"><?= Security::e((string) $rev['commentaire']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="resource-detail__report-section" aria-labelledby="report-heading">
            <details class="report-box">
                <summary id="report-heading" class="report-box__summary">Signaler cette ressource à la modération</summary>
                <?php if (Auth::isLogged()): ?>
                    <form action="/ressource-detail?id=<?= $resourceId ?>" method="post" class="report-box__form">
                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="action" value="report">

                        <div class="report-box__field">
                            <label for="report-motif" class="report-box__label">Motif du signalement</label>
                            <select id="report-motif" name="motif" required class="report-box__select">
                                <option value="MALWARE"> Code malveillant / Backdoor</option>
                                <option value="COPYRIGHT">Violation de droits d'auteur / Plagiat</option>
                                <option value="BROKEN">Archive corrompue ou non fonctionnelle</option>
                                <option value="INAPPROPRIATE">Contenu inapproprié / Illégal</option>
                            </select>
                        </div>

                        <div class="report-box__field">
                            <label for="report-desc" class="report-box__label">Explications complémentaires</label>
                            <textarea
                                id="report-desc"
                                name="description_signalement"
                                rows="3"
                                minlength="10"
                                maxlength="1000"
                                required
                                class="report-box__textarea"
                            ></textarea>
                        </div>

                        <button type="submit" class="report-box__submit">Envoyer le signalement</button>
                    </form>
                <?php else: ?>
                    <p class="report-box__guest">
                        Veuillez vous <a href="/auth?mode=login">connecter</a> pour soumettre un signalement.
                    </p>
                <?php endif; ?>
            </details>
        </section>
    </div>
</article>