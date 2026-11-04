<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Http;
use Bootes\Src\RateLimiter;
use Bootes\Src\ResourceManager;
use Bootes\Src\Security;
use Bootes\Src\View;

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

// Données de présentation
$typeKey      = strtoupper((string) $resource['type_ressource']);
$typeTone     = match ($typeKey) {
    'PLUGIN'              => 'plugin',
    'MOD_PACK', 'MODPACK' => 'modpack',
    default               => 'mod',
};
$typeIcon     = ['mod' => 'puzzle', 'plugin' => 'plug', 'modpack' => 'boxes'][$typeTone];
$latestFile   = $versionsList[0] ?? null;
$canReview    = Auth::isLogged() && !$isCreator && $isAvailable;
$baseUrl      = '/ressource-detail?id=' . $resourceId;
$ratingCounts = array_fill(1, 5, 0);
foreach ($reviews as $rev) {
    $note = (int) $rev['note'];
    if ($note >= 1 && $note <= 5) {
        $ratingCounts[$note]++;
    }
}
?>
<article class="resource-detail resource-detail--<?= $typeTone ?>" aria-labelledby="resource-main-title">
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
        <div class="resource-detail__chips">
            <span class="type-chip type-chip--<?= $typeTone ?>"><?= View::icon($typeIcon) ?> <?= Security::e(View::label('type_ressource', $typeKey)) ?></span>
            <span class="badge"><?= View::icon('box') ?> Minecraft <?= Security::e((string) $resource['version_compatible']) ?></span>
            <?php if (!$isAvailable): ?>
                <?= View::badge('moderation', $resource['statut_moderation']) ?>
            <?php endif; ?>
        </div>
        <h1 id="resource-main-title" class="resource-detail__title"><?= Security::e((string) $resource['nom_ressource']) ?></h1>
        <?php if (!empty($resource['resume'])): ?>
            <p class="resource-detail__lead"><?= Security::e((string) $resource['resume']) ?></p>
        <?php endif; ?>
        <div class="resource-detail__byline">
            <span class="avatar" aria-hidden="true"><?= Security::e(View::initials((string) $resource['auteur_pseudo'])) ?></span>
            <span>
                par <strong><?= Security::e((string) $resource['auteur_pseudo']) ?></strong>
                &middot; publié le <time datetime="<?= Security::e((string) $resource['date_publication']) ?>"><?= Security::e(View::date((string) $resource['date_publication'])) ?></time>
            </span>
            <?php if (!empty($reviews)): ?>
                <a href="#avis" class="resource-detail__rating">
                    <?= View::stars($averageRating) ?>
                    <?= View::number($averageRating, 1) ?> &middot; <?= count($reviews) ?> avis
                </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="resource-detail__layout">
        <div class="resource-detail__main" data-tabs>
            <nav class="tabs__list" aria-label="Sections de la fiche" data-tablist>
                <a href="#description" class="tabs__tab" data-tab><?= View::icon('file-text') ?> Description</a>
                <a href="#versions" class="tabs__tab" data-tab><?= View::icon('history') ?> Versions <span class="tabs__count"><?= count($versionsList) ?></span></a>
                <a href="#avis" class="tabs__tab" data-tab><?= View::icon('star') ?> Avis <span class="tabs__count"><?= count($reviews) ?></span></a>
            </nav>

            <section id="description" class="resource-detail__panel" aria-labelledby="desc-heading">
                <h2 id="desc-heading" class="resource-detail__panel-title">Description</h2>
                <div class="resource-detail__content">
                    <p><?= nl2br(Security::e((string) $resource['description'])) ?></p>
                </div>
            </section>

            <section id="versions" class="resource-detail__panel" aria-labelledby="versions-heading">
                <h2 id="versions-heading" class="resource-detail__panel-title">Fichiers et versions</h2>
                <?php if (empty($versionsList)): ?>
                    <p class="resource-detail__info">
                        Aucun fichier publié pour l'instant. Version de Minecraft visée : <strong><?= Security::e((string) $resource['version_compatible']) ?></strong>.
                    </p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="versions-table">
                            <caption class="visually-hidden">Historique des versions de la ressource</caption>
                            <thead class="versions-table__head">
                                <tr class="versions-table__row">
                                    <th scope="col" class="versions-table__cell">Version</th>
                                    <th scope="col" class="versions-table__cell">Minecraft</th>
                                    <th scope="col" class="versions-table__cell">Fichier</th>
                                    <th scope="col" class="versions-table__cell">Taille</th>
                                    <th scope="col" class="versions-table__cell">Publiée le</th>
                                </tr>
                            </thead>
                            <tbody class="versions-table__body">
                                <?php foreach ($versionsList as $index => $ver): ?>
                                    <tr class="versions-table__row">
                                        <td class="versions-table__cell">
                                            <strong><?= Security::e((string) $ver['numero_version']) ?></strong>
                                            <?php if ($index === 0): ?>
                                                <span class="badge badge--success">Dernière</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="versions-table__cell"><?= Security::e((string) $ver['version_mc']) ?></td>
                                        <td class="versions-table__cell"><code><?= Security::e((string) $ver['nom_fichier']) ?></code></td>
                                        <td class="versions-table__cell"><?= Security::e(View::fileSize((int) $ver['taille_octets'])) ?></td>
                                        <td class="versions-table__cell"><?= Security::e(View::date((string) $ver['date_ajout'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section id="avis" class="resource-detail__panel" aria-labelledby="reviews-heading">
                <h2 id="reviews-heading" class="resource-detail__panel-title">Avis de la communauté</h2>

                <div class="review-summary">
                    <div class="review-summary__score">
                        <p class="review-summary__value"><?= !empty($reviews) ? View::number($averageRating, 1) : '—' ?></p>
                        <?= View::stars($averageRating) ?>
                        <p class="review-summary__count"><?= count($reviews) ?> avis</p>
                    </div>
                    <dl class="rating-bars">
                        <?php for ($star = 5; $star >= 1; $star--): ?>
                            <dt class="rating-bars__label"><?= $star ?> <?= View::icon('star') ?></dt>
                            <dd class="rating-bars__value">
                                <meter class="rating-bars__meter" min="0" max="<?= max(1, count($reviews)) ?>" value="<?= $ratingCounts[$star] ?>"
                                       aria-label="<?= $ratingCounts[$star] ?> avis à <?= $star ?> étoile<?= $star > 1 ? 's' : '' ?>"></meter>
                            </dd>
                        <?php endfor; ?>
                    </dl>
                </div>

                <?php if ($canReview): ?>
                    <form action="<?= Security::e($baseUrl) ?>#avis" method="post" class="review-form">
                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="action" value="add_review">
                        <?php
                        $starInputName = 'note';
                        $starInputId   = 'review-note';
                        require __DIR__ . '/../../includes/star-input.php';
                        ?>
                        <div class="review-form__field">
                            <label for="review-comment" class="review-form__label">Votre avis</label>
                            <textarea id="review-comment" name="commentaire" rows="3" minlength="5" maxlength="1000" required
                                      class="review-form__textarea" placeholder="Qu'avez-vous pensé de cette création ?"></textarea>
                        </div>
                        <button type="submit" class="review-form__submit"><?= View::icon('send') ?> Publier mon avis</button>
                    </form>
                <?php elseif ($isCreator): ?>
                    <p class="resource-detail__info">Vous êtes l'auteur de cette ressource : vous ne pouvez pas l'évaluer.</p>
                <?php elseif (!Auth::isLogged()): ?>
                    <p class="resource-detail__info"><a href="/auth?mode=login">Connectez-vous</a> pour donner votre avis.</p>
                <?php endif; ?>

                <?php if (empty($reviews)): ?>
                    <p class="resource-detail__no-review">Aucun avis pour le moment : soyez le premier à partager votre expérience.</p>
                <?php else: ?>
                    <div class="review-list">
                        <?php foreach ($reviews as $rev): ?>
                            <article class="review-card">
                                <header class="review-card__header">
                                    <span class="avatar" aria-hidden="true"><?= Security::e(View::initials((string) $rev['pseudo'])) ?></span>
                                    <strong class="review-card__author"><?= Security::e((string) $rev['pseudo']) ?></strong>
                                    <?= View::stars((int) $rev['note']) ?>
                                    <time class="review-card__date" datetime="<?= Security::e((string) $rev['date_avis']) ?>" title="<?= Security::e(View::date((string) $rev['date_avis'], true)) ?>">
                                        <?= Security::e(View::ago((string) $rev['date_avis'])) ?>
                                    </time>
                                </header>
                                <p class="review-card__comment"><?= Security::e((string) $rev['commentaire']) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="resource-detail__aside">
            <section class="resource-detail__card resource-detail__card--download" aria-labelledby="download-heading">
                <h2 id="download-heading" class="resource-detail__card-title">Télécharger</h2>
                <form action="<?= Security::e($baseUrl) ?>" method="post" class="resource-detail__download-form">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="download">
                    <button type="submit" class="resource-detail__btn resource-detail__btn--primary" <?= $latestFile === null ? 'disabled' : '' ?>>
                        <?= View::icon('download') ?> <?= $latestFile === null ? 'Aucun fichier publié' : 'Télécharger' ?>
                    </button>
                </form>
                <?php if ($latestFile !== null): ?>
                    <p class="resource-detail__file">
                        <code><?= Security::e((string) $latestFile['nom_fichier']) ?></code>
                        Version <?= Security::e((string) $latestFile['numero_version']) ?> &middot; <?= Security::e(View::fileSize((int) $latestFile['taille_octets'])) ?>
                    </p>
                <?php endif; ?>

                <div class="resource-detail__install">
                    <h3 class="resource-detail__install-title"><?= View::icon('server') ?> Installer sur un serveur</h3>
                    <?php if (!Auth::isLogged()): ?>
                        <p class="resource-detail__info"><a href="/auth?mode=login">Connectez-vous</a> pour l'installer en un clic sur votre serveur.</p>
                    <?php elseif (empty($userServers)): ?>
                        <p class="resource-detail__info">Vous n'avez pas encore de serveur. <a href="/user/compte#section-serveurs">Créer un serveur</a></p>
                    <?php else: ?>
                        <form action="<?= Security::e($baseUrl) ?>" method="post" class="resource-detail__deploy-form">
                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                            <input type="hidden" name="action" value="install_on_server">
                            <label for="target-server-select" class="visually-hidden">Serveur cible</label>
                            <select id="target-server-select" name="target_server_id" required class="resource-detail__select">
                                <?php foreach ($userServers as $srv): ?>
                                    <option value="<?= (int) $srv['id_serveur'] ?>">
                                        <?= Security::e((string) $srv['nom_serveur']) ?> (MC <?= Security::e((string) $srv['version_mc']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="resource-detail__btn resource-detail__btn--secondary"><?= View::icon('plus') ?> Installer</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <section class="resource-detail__card" aria-labelledby="facts-heading">
                <h2 id="facts-heading" class="resource-detail__card-title">Informations</h2>
                <dl class="resource-detail__facts">
                    <dt>Type</dt>
                    <dd><?= Security::e(View::label('type_ressource', $typeKey)) ?></dd>
                    <dt>Minecraft</dt>
                    <dd><?= Security::e((string) $resource['version_compatible']) ?></dd>
                    <dt>Téléchargements</dt>
                    <dd><?= View::number((int) $resource['nb_telechargements']) ?></dd>
                    <dt>Note</dt>
                    <dd><?= !empty($reviews) ? View::number($averageRating, 1) . ' / 5' : 'Pas encore notée' ?></dd>
                    <dt>Publication</dt>
                    <dd><?= Security::e(View::date((string) $resource['date_publication'])) ?></dd>
                    <?php if ($latestFile !== null): ?>
                        <dt>Mise à jour</dt>
                        <dd><?= Security::e(View::date((string) $latestFile['date_ajout'])) ?></dd>
                    <?php endif; ?>
                    <dt>Statut</dt>
                    <dd><?= View::badge('moderation', $resource['statut_moderation']) ?></dd>
                </dl>
            </section>

            <?php if ($canModerate): ?>
                <section class="resource-detail__card resource-detail__card--tools" aria-labelledby="tools-heading">
                    <h2 id="tools-heading" class="resource-detail__card-title"><?= View::icon('settings') ?> <?= $isCreator ? 'Espace créateur' : 'Modération' ?></h2>
                    <div class="resource-detail__moderation-actions">
                        <a href="/user/createur-edition?id=<?= $resourceId ?>" class="resource-detail__btn"><?= View::icon('pencil') ?> Modifier</a>
                        <a href="/user/createur-statistiques?id=<?= $resourceId ?>" class="resource-detail__btn"><?= View::icon('chart-column') ?> Statistiques</a>
                        <form action="<?= Security::e($baseUrl) ?>" method="post" class="resource-detail__inline-form">
                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                            <input type="hidden" name="action" value="moderate_delete">
                            <button type="submit" class="resource-detail__btn resource-detail__btn--danger" data-confirm="Retirer cette ressource du catalogue public ?">
                                <?= View::icon('ban') ?> Retirer du catalogue
                            </button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <details class="report-box">
                <summary id="report-heading" class="report-box__summary"><?= View::icon('flag') ?> Signaler cette ressource</summary>
                <?php if (Auth::isLogged()): ?>
                    <form action="<?= Security::e($baseUrl) ?>" method="post" class="report-box__form">
                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="action" value="report">

                        <div class="report-box__field">
                            <label for="report-motif" class="report-box__label">Motif</label>
                            <select id="report-motif" name="motif" required class="report-box__select">
                                <option value="MALWARE">Code malveillant / porte dérobée</option>
                                <option value="COPYRIGHT">Violation de droits d'auteur / plagiat</option>
                                <option value="BROKEN">Archive corrompue ou non fonctionnelle</option>
                                <option value="INAPPROPRIATE">Contenu inapproprié / illégal</option>
                            </select>
                        </div>

                        <div class="report-box__field">
                            <label for="report-desc" class="report-box__label">Explications</label>
                            <textarea id="report-desc" name="description_signalement" rows="3" minlength="10" maxlength="1000" required
                                      class="report-box__textarea"></textarea>
                        </div>

                        <button type="submit" class="report-box__submit">Envoyer le signalement</button>
                    </form>
                <?php else: ?>
                    <p class="report-box__guest">
                        Veuillez vous <a href="/auth?mode=login">connecter</a> pour soumettre un signalement.
                    </p>
                <?php endif; ?>
            </details>
        </div>
    </div>
</article>
