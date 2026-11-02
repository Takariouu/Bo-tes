<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Security;

$db = $db ?? Database::getInstance();

$serverId      = Security::validatePositiveInt($_GET['id'] ?? $_POST['server_id'] ?? null);
$currentUserId = Auth::getUserId();
$csrfToken     = Security::generateCsrfToken();

$feedbackMessage = null;
$feedbackError   = null;

if ($serverId === null) {
    // Si aucun ID n'est passé, redirection logique ou sélection du premier serveur public mis en avant
    try {
        $firstStmt = $db->query('SELECT id_serveur FROM SERVEUR WHERE est_public = 1 ORDER BY statut DESC, joueurs_connectes DESC LIMIT 1');
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

    <header class="public-server__header">
        <div class="public-server__identity">
            <span class="public-server__status <?= $isOnline ? 'public-server__status--online' : 'public-server__status--offline' ?>">
                <?= $isOnline ? 'En ligne' : 'Hors ligne' ?>
            </span>
            <h1 id="public-server-title" class="public-server__title">
                <?= Security::e((string) $server['nom_serveur']) ?>
            </h1>
            <p class="public-server__owner">
                Fondateur : <strong><?= Security::e((string) $server['proprietaire_pseudo']) ?></strong>
            </p>
        </div>

        <div class="public-server__join-box">
            <p class="public-server__address-label">Adresse de connexion directe :</p>
            <code class="public-server__address-code"><?= Security::e($connectionUri) ?></code>
            <div class="public-server__cta-group">
                <a href="minecraft://?addExternalServer=<?= rawurlencode((string) $server['nom_serveur']) ?>|<?= rawurlencode($connectionUri) ?>"
                   class="public-server__btn public-server__btn--join">
                    Rejoindre le serveur
                </a>
                <?php if ($canManageThis): ?>
                    <a href="/serveur/dashboard?id=<?= $serverId ?>" class="public-server__btn public-server__btn--manage">
                        Administrer ce serveur
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <section class="public-server__specs" aria-label="Caractéristiques techniques du serveur">
        <dl class="public-server__spec-list">
            <div class="public-server__spec-item">
                <dt class="public-server__spec-term">Version Minecraft</dt>
                <dd class="public-server__spec-desc"><?= Security::e((string) $server['version_mc']) ?></dd>
            </div>
            <div class="public-server__spec-item">
                <dt class="public-server__spec-term">Mode de jeu</dt>
                <dd class="public-server__spec-desc"><?= Security::e((string) $server['mode_jeu']) ?></dd>
            </div>
            <div class="public-server__spec-item">
                <dt class="public-server__spec-term">Joueurs connectés</dt>
                <dd class="public-server__spec-desc"><?= (int) $server['joueurs_connectes'] ?> / <?= (int) $server['slots_max'] ?></dd>
            </div>
            <div class="public-server__spec-item">
                <dt class="public-server__spec-term">Note des joueurs</dt>
                <dd class="public-server__spec-desc"><?= Security::e((string) $averageRating) ?> / 5 (<?= count($reviews) ?> avis)</dd>
            </div>
        </dl>
    </section>

    <section class="public-server__presentation" aria-labelledby="server-presentation-title">
        <h2 id="server-presentation-title" class="public-server__section-title">Présentation du monde</h2>
        <div class="public-server__description">
            <p><?= nl2br(Security::e((string) ($server['description'] ?? 'Aucune description fournie par l\'équipe du serveur.'))) ?></p>
        </div>
    </section>

    <section class="public-server__reviews" aria-labelledby="server-reviews-title">
        <h2 id="server-reviews-title" class="public-server__section-title">Évaluations des joueurs</h2>

        <?php if (Auth::isLogged()): ?>
            <form action="/serveur-public?id=<?= $serverId ?>" method="post" class="public-server__review-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="action" value="rate_server">

                <div class="public-server__field">
                    <label for="srv-note" class="public-server__label">Note globale</label>
                    <select id="srv-note" name="note" required class="public-server__select">
                        <option value="5">5 / 5 — Serveur remarquable</option>
                        <option value="4">4 / 5 — Très bonne expérience</option>
                        <option value="3">3 / 5 — Agréable</option>
                        <option value="2">2 / 5 — Moyen</option>
                        <option value="1">1 / 5 — À éviter</option>
                    </select>
                </div>

                <div class="public-server__field">
                    <label for="srv-comment" class="public-server__label">Votre retour d'expérience</label>
                    <textarea
                        id="srv-comment"
                        name="commentaire"
                        rows="3"
                        minlength="5"
                        maxlength="1000"
                        required
                        class="public-server__textarea"
                    ></textarea>
                </div>

                <button type="submit" class="public-server__submit">Envoyer mon évaluation</button>
            </form>
        <?php endif; ?>

        <?php if (empty($reviews)): ?>
            <p class="public-server__empty">Soyez le premier joueur à évaluer ce serveur.</p>
        <?php else: ?>
            <div class="public-server__review-list">
                <?php foreach ($reviews as $rev): ?>
                    <article class="review-card">
                        <header class="review-card__header">
                            <strong class="review-card__author"><?= Security::e((string) $rev['pseudo']) ?></strong>
                            <span class="review-card__rating"><?= (int) $rev['note'] ?> / 5</span>
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

    <section class="public-server__report" aria-labelledby="server-report-title">
        <details class="report-box">
            <summary id="server-report-title" class="report-box__summary">Signaler ce serveur à la modération Boötes</summary>
            <?php if (Auth::isLogged()): ?>
                <form action="/serveur-public?id=<?= $serverId ?>" method="post" class="report-box__form">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="report_server">

                    <div class="report-box__field">
                        <label for="srv-report-motif" class="report-box__label">Motif du signalement</label>
                        <select id="srv-report-motif" name="motif" required class="report-box__select">
                            <option value="EULA_VIOLATION">Non-respect de l'EULA Minecraft</option>
                            <option value="HARASSMENT">Harcèlement ou propos haineux</option>
                            <option value="RESOURCE_ABUSE">Abus matériel (Lag machine / Crash exploit)</option>
                            <option value="ILLEGAL_CONTENT">Contenu illicite</option>
                        </select>
                    </div>

                    <div class="report-box__field">
                        <label for="srv-report-desc" class="report-box__label">Précisions</label>
                        <textarea
                            id="srv-report-desc"
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
                    Vous devez être <a href="/auth?mode=login">connecté</a> pour signaler un serveur.
                </p>
            <?php endif; ?>
        </details>
    </section>
</article>