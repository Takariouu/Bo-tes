<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Security;

$db = $db ?? Database::getInstance();

$popularServers = [];
$latestResources = [];

try {
    $serverStmt = $db->prepare('
        SELECT 
            s.id_serveur,
            s.nom_serveur,
            s.version_mc,
            s.mode_jeu,
            s.statut,
            s.joueurs_connectes,
            s.slots_max,
            s.description,
            u.pseudo AS proprietaire_pseudo,
            COALESCE(AVG(a.note), 0) AS note_moyenne
        FROM SERVEUR s
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = s.id_proprietaire
        LEFT JOIN AVIS_SERVEUR a ON a.id_serveur = s.id_serveur
        WHERE s.est_public = 1
        GROUP BY s.id_serveur, s.nom_serveur, s.version_mc, s.mode_jeu, s.statut, s.joueurs_connectes, s.slots_max, s.description, u.pseudo
        ORDER BY s.statut DESC, s.joueurs_connectes DESC, note_moyenne DESC
        LIMIT 6
    ');
    $serverStmt->execute();
    $popularServers = $serverStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Home] Erreur chargement serveurs populaires : %s', $e->getMessage()));
}

try {
    $resourceStmt = $db->prepare('
        SELECT 
            r.id_ressource,
            r.nom_ressource,
            r.type_ressource,
            r.version_compatible,
            r.resume,
            r.nb_telechargements,
            r.date_publication,
            u.pseudo AS auteur_pseudo
        FROM RESSOURCE r
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = r.id_auteur
        WHERE r.statut_moderation = :statut
        ORDER BY r.date_publication DESC
        LIMIT 6
    ');
    $resourceStmt->execute([':statut' => 'VALIDE']);
    $latestResources = $resourceStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Home] Erreur chargement dernières ressources : %s', $e->getMessage()));
}
?>
<section class="hero" aria-labelledby="hero-title">
    <div class="hero__container">
        <div class="hero__content">
            <p class="hero__eyebrow">Infrastructure d'hébergement Minecraft auto-hébergée</p>
            <h1 id="hero-title" class="hero__title">BOÖTES</h1>
            <p class="hero__slogan">Transformer le vide en lieu de vie</p>
            <p class="hero__description">
                Déployez, administrez et partagez vos serveurs Minecraft, mods, plugins et modpacks sur une architecture dédiée, transparente et optimisée.
            </p>
            <div class="hero__actions">
                <?php if (Auth::isLogged()): ?>
                    <a href="/user/compte" class="hero__btn hero__btn--primary">Gérer mes serveurs</a>
                <?php else: ?>
                    <a href="/auth?mode=register" class="hero__btn hero__btn--primary">Créer un serveur gratuitement</a>
                <?php endif; ?>
                <a href="/offres" class="hero__btn hero__btn--secondary">Découvrir les offres</a>
                <a href="/catalogue" class="hero__btn hero__btn--ghost">Explorer le catalogue</a>
            </div>
        </div>

        <figure class="hero__visual" aria-label="Illustration conceptuelle de Boötes : cubes en orbite dans le vide spatial">
            <div class="hero__orbital-scene" role="img" aria-hidden="true">
                <div class="hero__core-cube"></div>
                <div class="hero__orbit hero__orbit--inner">
                    <span class="hero__satellite-cube hero__satellite-cube--alpha"></span>
                    <span class="hero__satellite-cube hero__satellite-cube--beta"></span>
                </div>
                <div class="hero__orbit hero__orbit--outer">
                    <span class="hero__satellite-cube hero__satellite-cube--gamma"></span>
                    <span class="hero__satellite-cube hero__satellite-cube--delta"></span>
                </div>
            </div>
            <figcaption class="hero__figcaption">Écosystème cubique en orbite autour du vide du Bouvier (Boötes).</figcaption>
        </figure>
    </div>
</section>

<?php
$searchAction = '/catalogue';
require __DIR__ . '/../../includes/recherche.php';
?>

<section class="home-section home-section--servers" aria-labelledby="popular-servers-title">
    <header class="home-section__header">
        <div class="home-section__heading">
            <h2 id="popular-servers-title" class="home-section__title">Serveurs publics populaires</h2>
            <p class="home-section__subtitle">Rejoignez les mondes actifs hébergés par la communauté Boötes.</p>
        </div>
        <a href="/catalogue?type=serveur" class="home-section__more-link">Voir tous les serveurs</a>
    </header>

    <?php if (empty($popularServers)): ?>
        <p class="home-section__empty">Aucun serveur public n'est actuellement répertorié.</p>
    <?php else: ?>
        <div class="card-grid card-grid--servers">
            <?php foreach ($popularServers as $server): ?>
                <?php
                $serverId    = (int) $server['id_serveur'];
                $isOnline    = strtoupper((string) $server['statut']) === 'EN_LIGNE';
                $statusClass = $isOnline ? 'server-card__status--online' : 'server-card__status--offline';
                $statusLabel = $isOnline ? 'En ligne' : 'Hors ligne';
                $rating      = round((float) $server['note_moyenne'], 1);
                ?>
                <article class="server-card">
                    <header class="server-card__header">
                        <span class="server-card__status <?= Security::e($statusClass) ?>">
                            <?= Security::e($statusLabel) ?>
                        </span>
                        <span class="server-card__version">
                            MC <?= Security::e((string) $server['version_mc']) ?>
                        </span>
                    </header>

                    <div class="server-card__body">
                        <h3 class="server-card__title">
                            <a href="/serveur-public?id=<?= $serverId ?>" class="server-card__link">
                                <?= Security::e((string) $server['nom_serveur']) ?>
                            </a>
                        </h3>
                        <p class="server-card__meta">
                            Mode : <strong><?= Security::e((string) $server['mode_jeu']) ?></strong>
                            &bull; Par <?= Security::e((string) $server['proprietaire_pseudo']) ?>
                        </p>
                        <p class="server-card__description">
                            <?= Security::e(mb_strimwidth((string) ($server['description'] ?? ''), 0, 140, '...')) ?>
                        </p>
                    </div>

                    <footer class="server-card__footer">
                        <span class="server-card__players" title="Joueurs connectés">
                            <?= (int) $server['joueurs_connectes'] ?> / <?= (int) $server['slots_max'] ?> joueurs
                        </span>
                        <span class="server-card__rating" title="Note moyenne des joueurs">
                            Note : <?= Security::e((string) $rating) ?>/5
                        </span>
                        <a href="/serveur-public?id=<?= $serverId ?>" class="server-card__btn">Consulter</a>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="home-section home-section--resources" aria-labelledby="latest-resources-title">
    <header class="home-section__header">
        <div class="home-section__heading">
            <h2 id="latest-resources-title" class="home-section__title">Derniers Modpacks, Mods et Plugins</h2>
            <p class="home-section__subtitle">Enrichissez votre serveur avec les créations vérifiées de la communauté.</p>
        </div>
        <a href="/catalogue" class="home-section__more-link">Parcourir tout le catalogue</a>
    </header>

    <?php if (empty($latestResources)): ?>
        <p class="home-section__empty">Aucune ressource publiée pour le moment.</p>
    <?php else: ?>
        <div class="card-grid card-grid--resources">
            <?php foreach ($latestResources as $resource): ?>
                <?php $resourceId = (int) $resource['id_ressource']; ?>
                <article class="resource-card">
                    <header class="resource-card__header">
                        <span class="resource-card__type">
                            <?= Security::e(strtoupper((string) $resource['type_ressource'])) ?>
                        </span>
                        <span class="resource-card__version">
                            MC <?= Security::e((string) $resource['version_compatible']) ?>
                        </span>
                    </header>

                    <div class="resource-card__body">
                        <h3 class="resource-card__title">
                            <a href="/ressource-detail?id=<?= $resourceId ?>" class="resource-card__link">
                                <?= Security::e((string) $resource['nom_ressource']) ?>
                            </a>
                        </h3>
                        <p class="resource-card__author">
                            Auteur : <strong><?= Security::e((string) $resource['auteur_pseudo']) ?></strong>
                        </p>
                        <p class="resource-card__summary">
                            <?= Security::e(mb_strimwidth((string) ($resource['resume'] ?? ''), 0, 140, '...')) ?>
                        </p>
                    </div>

                    <footer class="resource-card__footer">
                        <span class="resource-card__downloads">
                            <?= (int) $resource['nb_telechargements'] ?> téléchargements
                        </span>
                        <a href="/ressource-detail?id=<?= $resourceId ?>" class="resource-card__btn">Voir la fiche</a>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>