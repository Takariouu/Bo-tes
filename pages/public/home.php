<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Security;
use Bootes\Src\View;

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
        ORDER BY CASE s.statut WHEN \'EN_LIGNE\' THEN 0 WHEN \'DEMARRAGE\' THEN 1 WHEN \'MAINTENANCE\' THEN 2 ELSE 3 END,
                 s.joueurs_connectes DESC, note_moyenne DESC
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
            u.pseudo AS auteur_pseudo,
            COALESCE((SELECT AVG(ar.note) FROM AVIS_RESSOURCE ar WHERE ar.id_ressource = r.id_ressource), 0) AS note_moyenne
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

// Chiffres clés de la communauté
$stats = ['online' => 0, 'players' => 0, 'servers' => 0, 'resources' => 0, 'downloads' => 0, 'members' => 0];
try {
    $serverStats = $db->query("
        SELECT COUNT(*) AS servers,
               COALESCE(SUM(CASE WHEN statut = 'EN_LIGNE' THEN 1 ELSE 0 END), 0) AS online,
               COALESCE(SUM(CASE WHEN statut = 'EN_LIGNE' THEN joueurs_connectes ELSE 0 END), 0) AS players
        FROM SERVEUR WHERE est_public = 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $resourceStats = $db->query("
        SELECT COUNT(*) AS resources, COALESCE(SUM(nb_telechargements), 0) AS downloads
        FROM RESSOURCE WHERE statut_moderation = 'VALIDE'
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['members'] = (int) $db->query('SELECT COUNT(*) FROM UTILISATEUR WHERE est_banni = 0')->fetchColumn();
    foreach (['servers', 'online', 'players'] as $key) {
        $stats[$key] = (int) ($serverStats[$key] ?? 0);
    }
    foreach (['resources', 'downloads'] as $key) {
        $stats[$key] = (int) ($resourceStats[$key] ?? 0);
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Home] Erreur chargement des statistiques : %s', $e->getMessage()));
}

$features = [
    ['icon' => 'square-terminal', 'title' => 'Console en direct',       'text' => 'Envoyez vos commandes et suivez les journaux du serveur sans quitter le navigateur.'],
    ['icon' => 'blocks',          'title' => 'Mods et plugins en 1 clic', 'text' => 'Installez les créations validées du catalogue directement sur votre serveur.'],
    ['icon' => 'archive',         'title' => 'Sauvegardes',              'text' => 'Archivez votre monde avant une mise à jour et restaurez-le en cas de pépin.'],
    ['icon' => 'key-round',       'title' => 'Co-administrateurs',       'text' => 'Partagez la gestion avec vos amis : lecture seule, console ou contrôle complet.'],
    ['icon' => 'map',             'title' => 'Carte du monde',           'text' => 'Publiez une carte 2D ou 3D de votre monde pour que les joueurs s\'y repèrent.'],
    ['icon' => 'chart-line',      'title' => 'Statistiques',             'text' => 'Mémoire, processeur, joueurs : visualisez l\'activité de votre serveur dans le temps.'],
];
?>
<section class="hero" aria-labelledby="hero-title">
    <div class="hero__container">
        <div class="hero__content">
            <p class="hero__eyebrow"><?= View::icon('sparkles') ?> Hébergement Minecraft auto-hébergé et communautaire</p>
            <h1 id="hero-title" class="hero__title">BOÖTES</h1>
            <p class="hero__slogan">Transformer le vide en lieu de vie</p>
            <p class="hero__description">
                Déployez, administrez et partagez vos serveurs Minecraft, mods, plugins et modpacks sur une architecture dédiée, transparente et optimisée.
            </p>
            <div class="hero__actions">
                <?php if (Auth::isLogged()): ?>
                    <a href="/user/compte#section-serveurs" class="hero__btn hero__btn--primary"><?= View::icon('server') ?> Gérer mes serveurs</a>
                <?php else: ?>
                    <a href="/auth?mode=register" class="hero__btn hero__btn--primary"><?= View::icon('rocket') ?> Créer un serveur gratuitement</a>
                <?php endif; ?>
                <a href="/catalogue" class="hero__btn hero__btn--secondary"><?= View::icon('blocks') ?> Explorer le catalogue</a>
            </div>
            <?php if ($stats['online'] > 0): ?>
                <p class="hero__live">
                    <span class="hero__live-dot" aria-hidden="true"></span>
                    <?= View::number($stats['online']) ?> serveur<?= $stats['online'] > 1 ? 's' : '' ?> en ligne
                    &middot; <?= View::number($stats['players']) ?> joueur<?= $stats['players'] > 1 ? 's' : '' ?> connecté<?= $stats['players'] > 1 ? 's' : '' ?>
                </p>
            <?php endif; ?>
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

<section class="stats-band" aria-label="Boötes en chiffres">
    <dl class="stats-band__list">
        <div class="stats-band__item">
            <dt class="stats-band__label"><?= View::icon('server') ?> Serveurs publics</dt>
            <dd class="stats-band__value"><?= View::number($stats['servers']) ?></dd>
        </div>
        <div class="stats-band__item">
            <dt class="stats-band__label"><?= View::icon('users') ?> Joueurs connectés</dt>
            <dd class="stats-band__value"><?= View::number($stats['players']) ?></dd>
        </div>
        <div class="stats-band__item">
            <dt class="stats-band__label"><?= View::icon('blocks') ?> Créations validées</dt>
            <dd class="stats-band__value"><?= View::number($stats['resources']) ?></dd>
        </div>
        <div class="stats-band__item">
            <dt class="stats-band__label"><?= View::icon('download') ?> Téléchargements</dt>
            <dd class="stats-band__value"><?= View::number($stats['downloads']) ?></dd>
        </div>
        <div class="stats-band__item">
            <dt class="stats-band__label"><?= View::icon('user-round') ?> Membres</dt>
            <dd class="stats-band__value"><?= View::number($stats['members']) ?></dd>
        </div>
    </dl>
</section>

<?php
$searchAction = '/catalogue';
require __DIR__ . '/../../includes/recherche.php';
?>

<section class="home-section" aria-labelledby="popular-servers-title">
    <header class="home-section__header">
        <div class="home-section__heading">
            <p class="home-section__eyebrow">Communauté</p>
            <h2 id="popular-servers-title" class="home-section__title">Serveurs publics populaires</h2>
            <p class="home-section__subtitle">Rejoignez les mondes actifs hébergés par la communauté Boötes.</p>
        </div>
        <a href="/catalogue?type=serveur" class="home-section__more-link">Voir tous les serveurs <?= View::icon('arrow-right') ?></a>
    </header>

    <?php if (empty($popularServers)): ?>
        <p class="home-section__empty">Aucun serveur public n'est actuellement répertorié.</p>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($popularServers as $server): ?>
                <?php
                $card = [
                    'id'          => $server['id_serveur'],
                    'name'        => $server['nom_serveur'],
                    'version'     => $server['version_mc'],
                    'mode'        => $server['mode_jeu'],
                    'status'      => $server['statut'],
                    'players'     => $server['joueurs_connectes'],
                    'slots'       => $server['slots_max'],
                    'description' => $server['description'],
                    'owner'       => $server['proprietaire_pseudo'],
                    'rating'      => $server['note_moyenne'],
                ];
                require __DIR__ . '/../../includes/cards/server-card.php';
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="home-section" aria-labelledby="latest-resources-title">
    <header class="home-section__header">
        <div class="home-section__heading">
            <p class="home-section__eyebrow">Catalogue</p>
            <h2 id="latest-resources-title" class="home-section__title">Dernières créations</h2>
            <p class="home-section__subtitle">Modpacks, mods et plugins vérifiés par la modération, prêts à installer.</p>
        </div>
        <a href="/catalogue" class="home-section__more-link">Parcourir le catalogue <?= View::icon('arrow-right') ?></a>
    </header>

    <?php if (empty($latestResources)): ?>
        <p class="home-section__empty">Aucune ressource publiée pour le moment.</p>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($latestResources as $resource): ?>
                <?php
                $card = [
                    'id'        => $resource['id_ressource'],
                    'name'      => $resource['nom_ressource'],
                    'type'      => $resource['type_ressource'],
                    'version'   => $resource['version_compatible'],
                    'summary'   => $resource['resume'],
                    'author'    => $resource['auteur_pseudo'],
                    'downloads' => $resource['nb_telechargements'],
                    'rating'    => $resource['note_moyenne'],
                ];
                require __DIR__ . '/../../includes/cards/resource-card.php';
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="home-section" aria-labelledby="features-title">
    <header class="home-section__header">
        <div class="home-section__heading">
            <p class="home-section__eyebrow">Panel d'administration</p>
            <h2 id="features-title" class="home-section__title">Tout pour faire vivre votre serveur</h2>
            <p class="home-section__subtitle">Un panel pensé pour les joueurs comme pour les administrateurs.</p>
        </div>
    </header>
    <ul class="feature-grid">
        <?php foreach ($features as $feature): ?>
            <li class="feature">
                <span class="feature__icon" aria-hidden="true"><?= View::icon($feature['icon']) ?></span>
                <h3 class="feature__title"><?= Security::e($feature['title']) ?></h3>
                <p class="feature__text"><?= Security::e($feature['text']) ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="home-section" aria-labelledby="offers-title">
    <header class="home-section__header">
        <div class="home-section__heading">
            <p class="home-section__eyebrow">Offres</p>
            <h2 id="offers-title" class="home-section__title">Deux offres, aucune surprise</h2>
            <p class="home-section__subtitle">6 Go de mémoire sont réservés aux serveurs Minecraft sur la machine hôte.</p>
        </div>
        <a href="/offres" class="home-section__more-link">Comparer les offres <?= View::icon('arrow-right') ?></a>
    </header>
    <div class="offer-teasers">
        <article class="offer-teaser">
            <p class="offer-teaser__name">Standard</p>
            <p class="offer-teaser__price">Gratuit</p>
            <ul class="offer-teaser__list">
                <li><?= View::icon('check') ?> Jusqu'à 2 Go de RAM</li>
                <li><?= View::icon('check') ?> 3 serveurs, 2 sauvegardes</li>
                <li><?= View::icon('check') ?> Console, co-admins, catalogue</li>
            </ul>
        </article>
        <article class="offer-teaser offer-teaser--premium">
            <p class="offer-teaser__name"><?= View::icon('gem') ?> Premium</p>
            <p class="offer-teaser__price">Soutien de l'hôte</p>
            <ul class="offer-teaser__list">
                <li><?= View::icon('check') ?> Jusqu'à 6 Go de RAM</li>
                <li><?= View::icon('check') ?> 10 serveurs, 10 sauvegardes</li>
                <li><?= View::icon('check') ?> Idéal pour les gros modpacks</li>
            </ul>
        </article>
    </div>
</section>

<section class="cta-band" aria-labelledby="cta-title">
    <div class="cta-band__content">
        <h2 id="cta-title" class="cta-band__title">Prêt à transformer le vide en lieu de vie ?</h2>
        <p class="cta-band__text">Créez votre serveur en quelques secondes, invitez vos amis et installez vos mods préférés.</p>
    </div>
    <?php if (Auth::isLogged()): ?>
        <a href="/user/compte#section-serveurs" class="cta-band__btn btn--primary"><?= View::icon('plus') ?> Créer un serveur</a>
    <?php else: ?>
        <a href="/auth?mode=register" class="cta-band__btn btn--primary"><?= View::icon('rocket') ?> Commencer gratuitement</a>
    <?php endif; ?>
</section>
