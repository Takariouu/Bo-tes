<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Security;
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
        <a href="/catalogue?type=serveur" class="public-server__btn">Parcourir les serveurs publics</a>
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
            s.est_public,
            s.date_creation,
            u.pseudo AS proprietaire_pseudo
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
        <a href="/catalogue?type=serveur" class="public-server__btn">Retour aux serveurs publics</a>
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

$isOnline       = strtoupper((string) $server['statut']) === 'EN_LIGNE';
$validPort      = Security::validatePort($server['port'] ?? 25565) ?? 25565;
$hostAddress    = Security::validateString($server['adresse_ip'] ?? 'play.bootes.local', 3, 100) ?? 'play.bootes.local';
$connectionUri  = sprintf('%s:%d', $hostAddress, $validPort);
$canManageThis  = $currentUserId !== null && Auth::canManageServer($currentUserId, $serverId, $db);

$reviews = [];
$averageRating = 0.0;
try {
    $revStmt = $db->prepare('
        SELECT a.note, a.commentaire, a.date_avis, u.pseudo
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
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][ServeurPublic] Erreur chargement avis : %s', $e->getMessage()));
}

// Données de présentation
$isOwner      = $currentUserId !== null && (int) $server['id_proprietaire'] === $currentUserId;
$isFavorite   = false;
if ($currentUserId !== null) {
    try {
        $favStmt = $db->prepare('SELECT COUNT(*) FROM FAVORI_SERVEUR WHERE id_utilisateur = :uid AND id_serveur = :sid');
        $favStmt->execute([':uid' => $currentUserId, ':sid' => $serverId]);
        $isFavorite = (int) $favStmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log(sprintf('[Boötes][ServeurPublic] Erreur lecture favoris : %s', $e->getMessage()));
    }
}
$canReview    = Auth::isLogged() && !$isOwner;
$slots        = max(1, (int) $server['slots_max']);
$players      = min(max(0, (int) $server['joueurs_connectes']), $slots);
$baseUrl      = '/serveur-public?id=' . $serverId;
$ratingCounts = array_fill(1, 5, 0);
foreach ($reviews as $rev) {
    $note = (int) $rev['note'];
    if ($note >= 1 && $note <= 5) {
        $ratingCounts[$note]++;
    }
}
?>
<article class="public-server" aria-labelledby="public-server-title">
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

    <header class="public-server__hero">
        <div class="public-server__identity">
            <div class="public-server__chips">
                <?= View::badge('statut_serveur', $server['statut']) ?>
                <span class="badge"><?= View::icon('box') ?> Minecraft <?= Security::e((string) $server['version_mc']) ?></span>
                <span class="badge"><?= View::icon('pickaxe') ?> <?= Security::e(View::label('mode_jeu', $server['mode_jeu'])) ?></span>
                <?php if ((int) $server['est_public'] !== 1): ?>
                    <span class="badge badge--warning"><?= View::icon('lock') ?> Privé</span>
                <?php endif; ?>
            </div>
            <h1 id="public-server-title" class="public-server__title"><?= Security::e((string) $server['nom_serveur']) ?></h1>
            <p class="public-server__owner">
                <span class="avatar" aria-hidden="true"><?= Security::e(View::initials((string) $server['proprietaire_pseudo'])) ?></span>
                Fondé par <strong><?= Security::e((string) $server['proprietaire_pseudo']) ?></strong>
                &middot; en ligne depuis le <?= Security::e(View::date((string) $server['date_creation'])) ?>
            </p>
        </div>

        <div class="public-server__join">
            <p class="public-server__join-label"><?= View::icon('globe') ?> Adresse du serveur</p>
            <div class="public-server__address">
                <code><?= Security::e($connectionUri) ?></code>
                <button type="button" class="public-server__copy" data-copy="<?= Security::e($connectionUri) ?>"><?= View::icon('copy') ?> Copier</button>
            </div>
            <p class="public-server__join-help">Dans Minecraft : <strong>Multijoueur › Ajouter un serveur</strong>, puis collez l'adresse.</p>
            <?php if (Auth::isLogged()): ?>
                <form action="<?= Security::e($baseUrl) ?>" method="post" class="public-server__favorite-form">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="toggle_favorite">
                    <button type="submit" class="public-server__favorite<?= $isFavorite ? ' is-favorite' : '' ?>" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>">
                        <?= View::icon('heart') ?> <?= $isFavorite ? 'Dans mes favoris' : 'Ajouter aux favoris' ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($canManageThis): ?>
                <a href="/serveur/dashboard?id=<?= $serverId ?>" class="public-server__btn public-server__btn--manage"><?= View::icon('settings') ?> Administrer ce serveur</a>
            <?php endif; ?>
        </div>
    </header>

    <dl class="public-server__stats" aria-label="Caractéristiques du serveur">
        <div class="public-server__stat">
            <dt><?= View::icon('users') ?> Joueurs connectés</dt>
            <dd><?= $players ?> / <?= $slots ?></dd>
            <dd><meter class="public-server__meter" min="0" max="<?= $slots ?>" value="<?= $players ?>" low="<?= (int) ceil($slots * 0.75) ?>" high="<?= (int) ceil($slots * 0.9) ?>" optimum="0" aria-label="Remplissage du serveur"></meter></dd>
        </div>
        <div class="public-server__stat">
            <dt><?= View::icon('star') ?> Note des joueurs</dt>
            <dd><?= !empty($reviews) ? View::number($averageRating, 1) . ' / 5' : '—' ?></dd>
            <dd><?= View::stars($averageRating) ?> <?= count($reviews) ?> avis</dd>
        </div>
        <div class="public-server__stat">
            <dt><?= View::icon('box') ?> Version</dt>
            <dd><?= Security::e((string) $server['version_mc']) ?></dd>
            <dd>Édition Java</dd>
        </div>
        <div class="public-server__stat">
            <dt><?= View::icon('pickaxe') ?> Mode de jeu</dt>
            <dd><?= Security::e(View::label('mode_jeu', $server['mode_jeu'])) ?></dd>
            <dd><?= Security::e(View::label('statut_serveur', $server['statut'])) ?></dd>
        </div>
    </dl>

    <div class="public-server__layout">
        <section class="public-server__presentation" aria-labelledby="server-presentation-title">
            <h2 id="server-presentation-title" class="public-server__section-title"><?= View::icon('earth') ?> Présentation du monde</h2>
            <div class="public-server__description">
                <p><?= nl2br(Security::e((string) ($server['description'] ?? '') !== '' ? (string) $server['description'] : 'Aucune description fournie par l\'équipe du serveur.')) ?></p>
            </div>
        </section>

        <section id="avis" class="public-server__reviews" aria-labelledby="server-reviews-title">
            <h2 id="server-reviews-title" class="public-server__section-title"><?= View::icon('star') ?> Avis des joueurs</h2>

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

    <details class="report-box">
        <summary id="server-report-title" class="report-box__summary"><?= View::icon('flag') ?> Signaler ce serveur à la modération</summary>
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
</article>
