<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/ResourceManager.php';

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\ResourceManager;
use Bootes\Src\Security;

$db              = $db ?? Database::getInstance();
$resourceManager = new ResourceManager($db);

$currentUserId = Auth::getUserId();
if ($currentUserId === null) {
    header('Location: /auth?mode=login');
    exit;
}

$csrfToken       = Security::generateCsrfToken();
$feedbackMessage = null;
$feedbackError   = null;

$userResources = $resourceManager->getResourcesByUser($currentUserId);
$requestedId   = Security::validatePositiveInt($_GET['id'] ?? $_POST['resource_id'] ?? null);

if ($requestedId === null && !empty($userResources)) {
    $requestedId = (int) $userResources[0]['id_ressource'];
}

if ($requestedId === null) {
    ?>
    <section class="creator-stats creator-stats--empty">
        <h1 class="creator-stats__title">Aucune ressource à analyser</h1>
        <p class="creator-stats__message">
            Vous n'avez pas encore publié de Mod, Plugin ou Modpack sur Boötes.
        </p>
        <a href="/user/createur-edition" class="creator-stats__btn">Publier ma première ressource</a>
    </section>
    <?php
    return;
}

if (!$resourceManager->canManageResource($currentUserId, $requestedId)) {
    http_response_code(403);
    ?>
    <section class="creator-stats creator-stats--forbidden">
        <h1 class="creator-stats__title">Accès refusé aux statistiques</h1>
        <p class="creator-stats__message">Seul le créateur de cette ressource ou un administrateur peut consulter ses métriques.</p>
        <a href="/user/compte" class="creator-stats__btn">Retourner à mon compte</a>
    </section>
    <?php
    return;
}

// Modération d'un avis/commentaire (Admin ou Créateur en cas d'abus)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::isAdmin()) {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 30) ?? '';

    if (Security::verifyCsrfToken($postCsrf) && $action === 'delete_review') {
        $reviewerId = Security::validatePositiveInt($_POST['reviewer_id'] ?? null);
        if ($reviewerId !== null) {
            try {
                $delRev = $db->prepare('DELETE FROM AVIS_RESSOURCE WHERE id_ressource = :rid AND id_utilisateur = :uid');
                $delRev->execute([
                    ':rid' => $requestedId,
                    ':uid' => $reviewerId,
                ]);
                $feedbackMessage = 'L\'avis sélectionné a été retiré par la modération.';
            } catch (PDOException $e) {
                error_log(sprintf('[Boötes][CreateurStats] Erreur suppression avis : %s', $e->getMessage()));
                $feedbackError = 'Impossible de supprimer cet avis.';
            }
        }
    }
}

$statsData = $resourceManager->getResourceStatistics($requestedId);
if (empty($statsData)) {
    ?>
    <section class="creator-stats creator-stats--not-found">
        <h1 class="creator-stats__title">Ressource introuvable</h1>
        <a href="/user/compte" class="creator-stats__btn">Retour à mon compte</a>
    </section>
    <?php
    return;
}

$resource           = $statsData['resource'];
$versions           = $statsData['versions'];
$ratingBreakdown    = $statsData['rating_breakdown'];
$reviews            = $statsData['reviews'];
$activeServersCount = (int) $statsData['active_servers_count'];
$monthlyEvolution   = $statsData['monthly_evolution'];

$totalDownloads = (int) $resource['nb_telechargements'];
$totalReviews   = (int) $resource['nb_avis'];
$averageRating  = round((float) $resource['note_moyenne'], 2);
$typeLabel      = ResourceManager::getTypeLabel((string) $resource['type_ressource']);
?>
<div class="creator-stats">
    <header class="creator-stats__header">
        <div class="creator-stats__heading">
            <p class="creator-stats__eyebrow">Analytique &amp; Télémétrie Créateur — <?= Security::e($typeLabel) ?></p>
            <h1 class="creator-stats__title">
                Statistiques : <?= Security::e((string) $resource['nom_ressource']) ?>
            </h1>
            <p class="creator-stats__meta">
                Auteur : <strong><?= Security::e((string) $resource['auteur_pseudo']) ?></strong>
                &bull; Publié le <time datetime="<?= Security::e((string) $resource['date_publication']) ?>"><?= Security::e((string) $resource['date_publication']) ?></time>
            </p>
        </div>

        <div class="creator-stats__controls">
            <?php if (count($userResources) > 1): ?>
                <form action="/user/createur-statistiques" method="get" class="creator-stats__switcher">
                    <label for="stats-res-select" class="creator-stats__switcher-label">Changer de ressource :</label>
                    <select id="stats-res-select" name="id" class="creator-stats__switcher-select">
                        <?php foreach ($userResources as $ur): ?>
                            <option value="<?= (int) $ur['id_ressource'] ?>" <?= (int) $ur['id_ressource'] === $requestedId ? 'selected' : '' ?>>
                                [<?= Security::e((string) $ur['type_ressource']) ?>] <?= Security::e((string) $ur['nom_ressource']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="creator-stats__switcher-btn">Afficher</button>
                </form>
            <?php endif; ?>

            <div class="creator-stats__links">
                <a href="/user/createur-edition?id=<?= $requestedId ?>" class="creator-stats__btn">Éditer la ressource</a>
                <a href="/ressource-detail?id=<?= $requestedId ?>" class="creator-stats__btn creator-stats__btn--ghost">Voir la fiche publique</a>
            </div>
        </div>
    </header>

    <?php if ($feedbackMessage !== null): ?>
        <div class="creator-stats__alert creator-stats__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="creator-stats__alert creator-stats__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <!-- INDICATEURS CLÉS (KPIs) -->
    <section class="creator-stats__kpis" aria-label="Indicateurs clés de performance">
        <dl class="kpi-grid">
            <div class="kpi-card">
                <dt class="kpi-card__label">Téléchargements totaux</dt>
                <dd class="kpi-card__value"><?= $totalDownloads ?></dd>
            </div>
            <div class="kpi-card">
                <dt class="kpi-card__label">Serveurs Boötes équipés</dt>
                <dd class="kpi-card__value"><?= $activeServersCount ?></dd>
            </div>
            <div class="kpi-card">
                <dt class="kpi-card__label">Note moyenne</dt>
                <dd class="kpi-card__value"><?= Security::e((string) $averageRating) ?> / 5 (<?= $totalReviews ?> avis)</dd>
            </div>
            <div class="kpi-card">
                <dt class="kpi-card__label">Versions publiées</dt>
                <dd class="kpi-card__value"><?= count($versions) ?></dd>
            </div>
        </dl>
    </section>

    <div class="creator-stats__grid">
        <!-- RÉPARTITION PAR VERSION DE FICHIER -->
        <section class="creator-stats__panel" aria-labelledby="versions-breakdown-title">
            <h2 id="versions-breakdown-title" class="creator-stats__panel-title">
                Téléchargements et poids par version
            </h2>
            <?php if (empty($versions)): ?>
                <p class="creator-stats__empty">Aucune version de fichier publiée pour le moment.</p>
            <?php else: ?>
                <table class="stats-table">
                    <caption class="stats-table__caption">Adoption des différentes versions du fichier</caption>
                    <thead class="stats-table__head">
                        <tr class="stats-table__row">
                            <th scope="col" class="stats-table__cell">Version</th>
                            <th scope="col" class="stats-table__cell">Compatibilité MC</th>
                            <th scope="col" class="stats-table__cell">Téléchargements</th>
                            <th scope="col" class="stats-table__cell">Part relative</th>
                            <th scope="col" class="stats-table__cell">Date de sortie</th>
                        </tr>
                    </thead>
                    <tbody class="stats-table__body">
                        <?php
                        $sumVerDownloads = array_sum(array_map(static fn(array $v): int => (int) $v['nb_telechargements_version'], $versions));
                        $denominator     = max(1, $sumVerDownloads > 0 ? $sumVerDownloads : $totalDownloads);
                        foreach ($versions as $v):
                            $verDl = (int) $v['nb_telechargements_version'];
                            $ratio = round(($verDl / $denominator) * 100, 1);
                        ?>
                            <tr class="stats-table__row">
                                <th scope="row" class="stats-table__cell"><?= Security::e((string) $v['numero_version']) ?></th>
                                <td class="stats-table__cell">MC <?= Security::e((string) $v['version_mc']) ?></td>
                                <td class="stats-table__cell"><?= $verDl ?></td>
                                <td class="stats-table__cell">
                                    <progress class="stats-table__progress" value="<?= Security::e((string) $ratio) ?>" max="100">
                                        <?= Security::e((string) $ratio) ?> %
                                    </progress>
                                    <span class="stats-table__pct"><?= Security::e((string) $ratio) ?> %</span>
                                </td>
                                <td class="stats-table__cell"><?= Security::e((string) $v['date_ajout']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <!-- ÉVOLUTION TEMPORELLE DES INSTALLATIONS -->
        <section class="creator-stats__panel" aria-labelledby="evolution-title">
            <h2 id="evolution-title" class="creator-stats__panel-title">
                Évolution mensuelle des déploiements sur Boötes
            </h2>
            <?php if (empty($monthlyEvolution)): ?>
                <p class="creator-stats__empty">Aucune donnée d'évolution mensuelle disponible pour le moment.</p>
            <?php else: ?>
                <table class="stats-table">
                    <caption class="stats-table__caption">Installations directes sur les serveurs par mois</caption>
                    <thead class="stats-table__head">
                        <tr class="stats-table__row">
                            <th scope="col" class="stats-table__cell">Période (Année-Mois)</th>
                            <th scope="col" class="stats-table__cell">Nouveaux déploiements</th>
                        </tr>
                    </thead>
                    <tbody class="stats-table__body">
                        <?php foreach ($monthlyEvolution as $evo): ?>
                            <tr class="stats-table__row">
                                <th scope="row" class="stats-table__cell"><?= Security::e((string) $evo['periode']) ?></th>
                                <td class="stats-table__cell"><?= (int) $evo['nb_installations'] ?> serveur(s)</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <!-- RÉPARTITION DES ÉVALUATIONS -->
        <section class="creator-stats__panel" aria-labelledby="ratings-dist-title">
            <h2 id="ratings-dist-title" class="creator-stats__panel-title">
                Répartition des notes des utilisateurs
            </h2>
            <dl class="rating-bars">
                <?php foreach ($ratingBreakdown as $star => $count): ?>
                    <?php $starPct = $totalReviews > 0 ? round(($count / $totalReviews) * 100, 1) : 0.0; ?>
                    <div class="rating-bars__row">
                        <dt class="rating-bars__label"><?= (int) $star ?> étoile<?= $star > 1 ? 's' : '' ?></dt>
                        <dd class="rating-bars__bar-wrapper">
                            <meter class="rating-bars__meter" min="0" max="100" value="<?= Security::e((string) $starPct) ?>">
                                <?= Security::e((string) $starPct) ?> %
                            </meter>
                            <span class="rating-bars__count"><?= (int) $count ?> avis (<?= Security::e((string) $starPct) ?> %)</span>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    </div>

    <!-- COMMENTAIRES ET RETOURS DÉTAILLÉS -->
    <section class="creator-stats__reviews" aria-labelledby="reviews-feedback-title">
        <h2 id="reviews-feedback-title" class="creator-stats__panel-title">
            Commentaires et retours de la communauté (<?= count($reviews) ?>)
        </h2>

        <?php if (empty($reviews)): ?>
            <p class="creator-stats__empty">Aucun commentaire n'a encore été publié sur cette ressource.</p>
        <?php else: ?>
            <div class="creator-stats__review-list">
                <?php foreach ($reviews as $rev): ?>
                    <article class="review-item">
                        <header class="review-item__header">
                            <strong class="review-item__author"><?= Security::e((string) $rev['pseudo']) ?></strong>
                            <span class="review-item__rating">Note attribuée : <?= (int) $rev['note'] ?> / 5</span>
                            <time class="review-item__date" datetime="<?= Security::e((string) $rev['date_avis']) ?>">
                                <?= Security::e((string) $rev['date_avis']) ?>
                            </time>
                        </header>
                        <p class="review-item__comment"><?= nl2br(Security::e((string) $rev['commentaire'])) ?></p>

                        <?php if (Auth::isAdmin()): ?>
                            <footer class="review-item__footer">
                                <form action="/user/createur-statistiques?id=<?= $requestedId ?>" method="post" class="review-item__mod-form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_review">
                                    <input type="hidden" name="resource_id" value="<?= $requestedId ?>">
                                    <input type="hidden" name="reviewer_id" value="<?= (int) $rev['id_utilisateur'] ?>">
                                    <button type="submit" class="review-item__delete-btn">Supprimer cet avis (Modération)</button>
                                </form>
                            </footer>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>