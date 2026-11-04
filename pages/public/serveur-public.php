<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Http;
use Bootes\Src\MediaManager;
use Bootes\Src\PageLayout;
use Bootes\Src\Security;
use Bootes\Src\ServerDirectory;
use Bootes\Src\View;

$db = $db ?? Database::getInstance();

$serverId      = Security::validatePositiveInt($_GET['id'] ?? $_POST['server_id'] ?? null);
$currentUserId = Auth::getUserId();
$csrfToken     = Security::generateCsrfToken();

$feedbackMessage = null;
$feedbackError   = null;

if ($serverId === null) {
    // Si aucun ID n'est passé, redirection logique ou sélection du premier serveur public mis en avant
    try {
        $firstStmt = $db->query("SELECT id_serveur FROM SERVEUR WHERE est_public = 1 ORDER BY CASE statut WHEN 'EN_LIGNE' THEN 0 WHEN 'DEMARRAGE' THEN 1 WHEN 'MAINTENANCE' THEN 2 ELSE 3 END, joueurs_connectes DESC LIMIT 1");
        $foundId   = $firstStmt ? $firstStmt->fetchColumn() : false;
        if ($foundId !== false) {
            $serverId = (int) $foundId;
        }
    } catch (PDOException $e) {
        error_log(sprintf('[Boötes][ServeurPublic] Recherche serveur par défaut : %s', $e->getMessage()));
    }
}

if ($serverId === null) {
    ?>
    <section class="public-server public-server--empty">
        <h1 class="public-server__title">Aucun serveur public sélectionné</h1>
        <p class="public-server__message">Consultez le catalogue pour découvrir les serveurs Minecraft ouverts au public.</p>
        <a href="/serveurs" class="public-server__btn">Parcourir l'annuaire des serveurs</a>
    </section>
    <?php
    return;
}

// Visibilité contrôlée AVANT tout traitement : aucune action possible sur un serveur privé d'un tiers.
$server = null;
try {
    $stmt = $db->prepare('
        SELECT 
            s.id_serveur,
            s.id_proprietaire,
            s.nom_serveur,
            s.adresse_ip,
            s.port,
            s.version_mc,
            s.mode_jeu,
            s.statut,
            s.joueurs_connectes,
            s.slots_max,
            s.ram_allouee,
            s.description,
            s.origine,
            s.resume,
            s.etiquettes,
            s.site_web,
            s.discord,
            s.date_verification,
            s.est_public,
            s.date_creation,
            u.pseudo AS proprietaire_pseudo,
            u.type_compte AS proprietaire_offre
        FROM SERVEUR s
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = s.id_proprietaire
        WHERE s.id_serveur = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $serverId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][ServeurPublic] Erreur chargement fiche serveur : %s', $e->getMessage()));
}

if ($server === null || ((int) $server['est_public'] !== 1 && ($currentUserId === null || !Auth::canManageServer($currentUserId, $serverId, $db)))) {
    ?>
    <section class="public-server public-server--restricted">
        <h1 class="public-server__title">Serveur introuvable ou privé</h1>
        <p class="public-server__message">Ce serveur Minecraft n'est pas répertorié publiquement.</p>
        <a href="/serveurs" class="public-server__btn">Retour à l'annuaire</a>
    </section>
    <?php
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentUserId !== null) {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 30) ?? '';

    if (!Security::verifyCsrfToken($postCsrf)) {
        $feedbackError = 'Jeton de sécurité CSRF invalide.';
    } else {
        try {
            if ($action === 'rate_server') {
                $note        = Security::validatePositiveInt($_POST['note'] ?? null);
                $commentaire = Security::validateString($_POST['commentaire'] ?? '', 5, 1000);

                if ((int) $server['id_proprietaire'] === $currentUserId) {
                    $feedbackError = 'Vous ne pouvez pas évaluer votre propre serveur.';
                } elseif ($note === null || $note < 1 || $note > 5 || $commentaire === null) {
                    $feedbackError = 'Veuillez attribuer une note de 1 à 5 et saisir un commentaire valide.';
                } else {
                    $rateStmt = $db->prepare('
                        INSERT INTO AVIS_SERVEUR (id_serveur, id_utilisateur, note, commentaire, date_avis)
                        VALUES (:srv_id, :user_id, :note, :commentaire, NOW())
                        ON DUPLICATE KEY UPDATE note = VALUES(note), commentaire = VALUES(commentaire), date_avis = NOW()
                    ');
                    $rateStmt->execute([
                        ':srv_id'      => $serverId,
                        ':user_id'     => $currentUserId,
                        ':note'        => $note,
                        ':commentaire' => $commentaire,
                    ]);
                    $feedbackMessage = 'Votre évaluation du serveur a été enregistrée.';
                }
            } elseif ($action === 'toggle_favorite') {
                $favCheck = $db->prepare('SELECT COUNT(*) FROM FAVORI_SERVEUR WHERE id_utilisateur = :uid AND id_serveur = :sid');
                $favCheck->execute([':uid' => $currentUserId, ':sid' => $serverId]);
                if ((int) $favCheck->fetchColumn() > 0) {
                    $db->prepare('DELETE FROM FAVORI_SERVEUR WHERE id_utilisateur = :uid AND id_serveur = :sid')
                        ->execute([':uid' => $currentUserId, ':sid' => $serverId]);
                    $feedbackMessage = 'Serveur retiré de vos favoris.';
                } else {
                    $db->prepare('INSERT INTO FAVORI_SERVEUR (id_utilisateur, id_serveur, date_ajout) VALUES (:uid, :sid, NOW())')
                        ->execute([':uid' => $currentUserId, ':sid' => $serverId]);
                    $feedbackMessage = 'Serveur ajouté à vos favoris.';
                }
            } elseif ($action === 'refresh_status') {
                if ((int) $server['id_proprietaire'] !== $currentUserId && !Auth::isAdmin()) {
                    $feedbackError = 'Seul le propriétaire de la fiche peut lancer une vérification.';
                } elseif ((new ServerDirectory($db))->refreshStatus($server, true)) {
                    Http::redirect($_SERVER['REQUEST_URI'] ?? '/serveurs', 'Statut du serveur vérifié à l\'instant.');
                } else {
                    $feedbackError = 'Le statut a été vérifié il y a moins d\'une minute : réessayez dans un instant.';
                }
            } elseif ($action === 'report_server') {
                $motif       = Security::validateString($_POST['motif'] ?? '', 3, 100);
                $description = Security::validateString($_POST['description_signalement'] ?? '', 10, 1000);

                if ($motif === null || $description === null) {
                    $feedbackError = 'Veuillez renseigner un motif et une description détaillée (10 caractères min.).';
                } else {
                    $repStmt = $db->prepare('
                        INSERT INTO SIGNALEMENT (id_auteur_signalement, type_cible, id_cible, motif, description, statut, date_signalement)
                        VALUES (:user_id, :type_cible, :id_cible, :motif, :description, :statut, NOW())
                    ');
                    $repStmt->execute([
                        ':user_id'     => $currentUserId,
                        ':type_cible'  => 'SERVEUR',
                        ':id_cible'    => $serverId,
                        ':motif'       => $motif,
                        ':description' => $description,
                        ':statut'      => 'EN_ATTENTE',
                    ]);
                    $feedbackMessage = 'Le signalement concernant ce serveur a été transmis aux administrateurs.';
                }
            }
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][ServeurPublic] Erreur POST : %s', $e->getMessage()));
            $feedbackError = 'Impossible d\'enregistrer votre action pour le moment.';
        }
    }
}

$isOwner       = $currentUserId !== null && (int) $server['id_proprietaire'] === $currentUserId;
$isExternal    = (string) $server['origine'] === ServerDirectory::ORIGIN_EXTERNAL;
$canManageThis = !$isExternal && $currentUserId !== null && Auth::canManageServer($currentUserId, $serverId, $db);
$canEditPage   = $isOwner || Auth::isAdmin();
$ownerPremium  = strtoupper((string) ($server['proprietaire_offre'] ?? '')) === Auth::ACCOUNT_PREMIUM;
$editUrl       = '/user/annuaire-edition?id=' . $serverId;

$reviews       = [];
$averageRating = 0.0;
$favoriteCount = 0;
try {
    $revStmt = $db->prepare('
        SELECT a.note, a.commentaire, a.date_avis, u.pseudo, u.id_utilisateur
        FROM AVIS_SERVEUR a
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = a.id_utilisateur
        WHERE a.id_serveur = :id
        ORDER BY a.date_avis DESC
    ');
    $revStmt->execute([':id' => $serverId]);
    $reviews = $revStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($reviews)) {
        $sum = array_sum(array_map(static fn(array $r): int => (int) $r['note'], $reviews));
        $averageRating = round($sum / count($reviews), 1);
    }

    $favCountStmt = $db->prepare('SELECT COUNT(*) FROM FAVORI_SERVEUR WHERE id_serveur = :sid');
    $favCountStmt->execute([':sid' => $serverId]);
    $favoriteCount = (int) $favCountStmt->fetchColumn();
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][ServeurPublic] Erreur chargement avis : %s', $e->getMessage()));
}

$isFavorite = false;
if ($currentUserId !== null) {
    try {
        $favStmt = $db->prepare('SELECT COUNT(*) FROM FAVORI_SERVEUR WHERE id_utilisateur = :uid AND id_serveur = :sid');
        $favStmt->execute([':uid' => $currentUserId, ':sid' => $serverId]);
        $isFavorite = (int) $favStmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log(sprintf('[Boötes][ServeurPublic] Erreur lecture favoris : %s', $e->getMessage()));
    }
}

// Visuels (icône, bannière, galerie, photos) et mise en page choisie par le propriétaire
$iconToken    = null;
$bannerToken  = null;
$ownerAvatar  = null;
$galleryMedia = [];
$avatars      = [];
$layout       = PageLayout::defaultLayout();
try {
    $media        = new MediaManager($db);
    $iconToken    = $media->firstToken(MediaManager::TARGET_SERVER, $serverId, MediaManager::ROLE_ICON);
    $bannerToken  = $media->firstToken(MediaManager::TARGET_SERVER, $serverId, MediaManager::ROLE_BANNER);
    $galleryMedia = $media->forTarget(MediaManager::TARGET_SERVER, $serverId, MediaManager::ROLE_GALLERY);
    $avatars      = $media->tokensFor(MediaManager::TARGET_USER, array_merge([(int) $server['id_proprietaire']], array_column($reviews, 'id_utilisateur')), MediaManager::ROLE_AVATAR);
    $ownerAvatar  = $avatars[(int) $server['id_proprietaire']] ?? null;
    $layout       = (new PageLayout($db))->load(MediaManager::TARGET_SERVER, $serverId);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][ServeurPublic] Erreur chargement des visuels : %s', $e->getMessage()));
}

$canReview    = Auth::isLogged() && !$isOwner;
$slots        = max(1, (int) $server['slots_max']);
$players      = min(max(0, (int) $server['joueurs_connectes']), $slots);
$baseUrl      = '/serveur-public?id=' . $serverId;
$tags         = ServerDirectory::decodeTags($server['etiquettes'] ?? '');
$themeClass   = 'page-theme--' . $layout['theme'];
$ratingCounts = array_fill(1, 5, 0);
foreach ($reviews as $rev) {
    $note = (int) $rev['note'];
    if ($note >= 1 && $note <= 5) {
        $ratingCounts[$note]++;
    }
}

$pageBreadcrumbs = [
    ['label' => 'Accueil', 'url' => '/home'],
    ['label' => 'Annuaire des serveurs', 'url' => '/serveurs'],
    ['label' => (string) $server['nom_serveur'], 'url' => null],
];
$pageTitle = sprintf('%s — Serveur Minecraft — Boötes', (string) $server['nom_serveur']);

$presentationText  = (string) ($server['description'] ?? '');
$presentationTitle = 'Présentation du serveur';
$galleryName       = (string) $server['nom_serveur'];
?>
<article class="showcase showcase--server" aria-labelledby="public-server-title">
    <?php if ($feedbackMessage !== null): ?>
        <div class="public-server__alert public-server__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="public-server__alert public-server__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <header class="showcase-hero <?= Security::e($themeClass) ?><?= $bannerToken !== null ? ' showcase-hero--banner' : '' ?>">
        <div class="showcase-hero__banner" aria-hidden="true">
            <?php if ($bannerToken !== null): ?>
                <img src="<?= Security::e(MediaManager::url($bannerToken)) ?>" alt="" class="showcase-hero__banner-img" width="1600" height="400">
            <?php endif; ?>
        </div>
        <div class="showcase-hero__body">
            <?= View::tile($iconToken, (string) $server['nom_serveur'], 'tile--xl showcase-hero__icon') ?>
            <div class="showcase-hero__identity">
                <div class="showcase-hero__chips">
                    <?php if ($isExternal): ?>
                        <span class="badge"><?= View::icon('globe') ?> Serveur référencé</span>
                    <?php else: ?>
                        <span class="badge badge--accent"><?= View::icon('orbit') ?> Hébergé sur Boötes</span>
                    <?php endif; ?>
                    <span class="badge"><?= View::icon('pickaxe') ?> <?= Security::e(View::label('mode_jeu', $server['mode_jeu'])) ?></span>
                    <?php if ($ownerPremium): ?>
                        <span class="badge badge--premium"><?= View::icon('gem') ?> Premium</span>
                    <?php endif; ?>
                    <?php if ((int) $server['est_public'] !== 1): ?>
                        <span class="badge badge--warning"><?= View::icon('lock') ?> Non publié</span>
                    <?php endif; ?>
                </div>
                <h1 id="public-server-title" class="showcase-hero__title"><?= Security::e((string) $server['nom_serveur']) ?></h1>
                <?php if ((string) ($server['resume'] ?? '') !== ''): ?>
                    <p class="showcase-hero__lead"><?= Security::e((string) $server['resume']) ?></p>
                <?php endif; ?>
                <p class="showcase-hero__owner">
                    <?= View::avatar($ownerAvatar, (string) $server['proprietaire_pseudo']) ?>
                    <span><?= $isExternal ? 'Référencé par' : 'Fondé par' ?> <strong><?= Security::e((string) $server['proprietaire_pseudo']) ?></strong>
                    &middot; depuis le <?= Security::e(View::date((string) $server['date_creation'])) ?></span>
                </p>
            </div>
            <div class="showcase-hero__actions">
                <?php if (Auth::isLogged()): ?>
                    <form action="<?= Security::e($baseUrl) ?>" method="post" class="public-server__favorite-form">
                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="action" value="toggle_favorite">
                        <button type="submit" class="public-server__favorite<?= $isFavorite ? ' is-favorite' : '' ?>" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>">
                            <?= View::icon('heart') ?> <?= $isFavorite ? 'Dans mes favoris' : 'Ajouter aux favoris' ?>
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($canEditPage): ?>
                    <a href="<?= Security::e($editUrl) ?>" class="showcase-hero__btn"><?= View::icon('pencil') ?> Modifier la fiche</a>
                <?php endif; ?>
                <?php if ($canManageThis): ?>
                    <a href="/serveur/dashboard?id=<?= $serverId ?>" class="showcase-hero__btn"><?= View::icon('settings') ?> Administrer</a>
                <?php endif; ?>
                <?php if ($isExternal && $canEditPage): ?>
                    <form action="<?= Security::e($baseUrl) ?>" method="post" class="showcase-hero__inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="action" value="refresh_status">
                        <button type="submit" class="showcase-hero__btn btn--ghost"><?= View::icon('radar') ?> Vérifier le statut</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php require __DIR__ . '/../../includes/fiche/server-connect.php'; ?>

    <div class="showcase__layout">
        <div class="showcase__main <?= Security::e($themeClass) ?>">
            <?php require __DIR__ . '/../../includes/fiche/blocks.php'; ?>

            <section id="avis" class="page-block page-block--reviews" aria-labelledby="server-reviews-title">
                <h2 id="server-reviews-title" class="page-block__title"><?= View::icon('star') ?> Avis des joueurs</h2>

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
                        <input type="hidden" name="action" value="rate_server">
                        <?php
                        $starInputName = 'note';
                        $starInputId   = 'srv-note';
                        require __DIR__ . '/../../includes/star-input.php';
                        ?>
                        <div class="public-server__field">
                            <label for="srv-comment" class="public-server__label">Votre retour d'expérience</label>
                            <textarea id="srv-comment" name="commentaire" rows="3" minlength="5" maxlength="1000" required
                                      class="public-server__textarea" placeholder="Ambiance, stabilité, équipe..."></textarea>
                        </div>
                        <button type="submit" class="public-server__submit"><?= View::icon('send') ?> Publier mon avis</button>
                    </form>
                <?php elseif ($isOwner): ?>
                    <p class="public-server__info">C'est votre serveur : vous ne pouvez pas l'évaluer.</p>
                <?php elseif (!Auth::isLogged()): ?>
                    <p class="public-server__info"><a href="/auth?mode=login">Connectez-vous</a> pour donner votre avis sur ce serveur.</p>
                <?php endif; ?>

                <?php if (empty($reviews)): ?>
                    <p class="public-server__no-review">Soyez le premier joueur à évaluer ce serveur.</p>
                <?php else: ?>
                    <div class="review-list">
                        <?php foreach ($reviews as $rev): ?>
                            <article class="review-card">
                                <header class="review-card__header">
                                    <?= View::avatar($avatars[(int) $rev['id_utilisateur']] ?? null, (string) $rev['pseudo']) ?>
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

        <aside class="showcase__aside" aria-label="Informations sur le serveur">
            <section class="info-card">
                <h2 class="info-card__title"><?= View::icon('info') ?> Informations</h2>
                <dl class="info-card__list">
                    <div class="info-card__row">
                        <dt>Hébergement</dt>
                        <dd><?= $isExternal ? 'Serveur externe' : 'Boötes (Linux Mint)' ?></dd>
                    </div>
                    <div class="info-card__row">
                        <dt>Mode de jeu</dt>
                        <dd><?= Security::e(View::label('mode_jeu', $server['mode_jeu'])) ?></dd>
                    </div>
                    <div class="info-card__row">
                        <dt>Version</dt>
                        <dd>Java <?= Security::e((string) $server['version_mc']) ?></dd>
                    </div>
                    <?php if ((int) $server['slots_max'] > 0): ?>
                        <div class="info-card__row">
                            <dt>Places</dt>
                            <dd><?= View::number($players) ?> / <?= View::number($slots) ?></dd>
                        </div>
                    <?php endif; ?>
                    <div class="info-card__row">
                        <dt>Note</dt>
                        <dd><?= !empty($reviews) ? View::number($averageRating, 1) . ' / 5 (' . count($reviews) . ' avis)' : 'Pas encore d\'avis' ?></dd>
                    </div>
                    <div class="info-card__row">
                        <dt>Favoris</dt>
                        <dd><?= View::number($favoriteCount) ?> joueur<?= $favoriteCount > 1 ? 's' : '' ?></dd>
                    </div>
                </dl>
                <?php if ($tags !== []): ?>
                    <ul class="info-card__tags" aria-label="Étiquettes">
                        <?php foreach ($tags as $tag): ?>
                            <li class="info-card__tag"><a href="/serveurs?tag=<?= Security::e($tag) ?>"><?= Security::e(ServerDirectory::TAGS[$tag]) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (!empty($server['site_web']) || !empty($server['discord'])): ?>
                    <div class="info-card__links">
                        <?php if (!empty($server['site_web'])): ?>
                            <a href="<?= Security::e((string) $server['site_web']) ?>" class="info-card__link" rel="nofollow noopener ugc" target="_blank"><?= View::icon('globe') ?> Site web</a>
                        <?php endif; ?>
                        <?php if (!empty($server['discord'])): ?>
                            <a href="<?= Security::e((string) $server['discord']) ?>" class="info-card__link" rel="nofollow noopener ugc" target="_blank"><?= View::icon('message-circle') ?> Discord</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <details class="report-box">
                <summary id="server-report-title" class="report-box__summary"><?= View::icon('flag') ?> Signaler ce serveur</summary>
                <?php if (Auth::isLogged()): ?>
                    <form action="<?= Security::e($baseUrl) ?>" method="post" class="report-box__form">
                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="action" value="report_server">

                        <div class="report-box__field">
                            <label for="srv-report-motif" class="report-box__label">Motif du signalement</label>
                            <select id="srv-report-motif" name="motif" required class="report-box__select">
                                <option value="EULA_VIOLATION">Non-respect de l'EULA Minecraft</option>
                                <option value="HARASSMENT">Harcèlement ou propos haineux</option>
                                <option value="RESOURCE_ABUSE">Abus matériel (lag machine, exploit de plantage)</option>
                                <option value="ILLEGAL_CONTENT">Contenu illicite</option>
                            </select>
                        </div>

                        <div class="report-box__field">
                            <label for="srv-report-desc" class="report-box__label">Précisions</label>
                            <textarea id="srv-report-desc" name="description_signalement" rows="3" minlength="10" maxlength="1000" required
                                      class="report-box__textarea"></textarea>
                        </div>

                        <button type="submit" class="report-box__submit">Envoyer le signalement</button>
                    </form>
                <?php else: ?>
                    <p class="report-box__guest">
                        Vous devez être <a href="/auth?mode=login">connecté</a> pour signaler un serveur.
                    </p>
                <?php endif; ?>
            </details>
        </aside>
    </div>

    <?php require __DIR__ . '/../../includes/fiche/lightbox.php'; ?>
</article>
