<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Http;
use Bootes\Src\MediaManager;
use Bootes\Src\ResourceManager;
use Bootes\Src\Security;
use Bootes\Src\View;

$db = $db ?? Database::getInstance();

$rawType        = strtolower(Security::validateString($_GET['type'] ?? 'all', 1, 20) ?? 'all');
$searchQuery    = Security::validateString($_GET['q'] ?? '', 0, 100) ?? '';
$versionFilter  = Security::validateString($_GET['version'] ?? 'all', 1, 20) ?? 'all';
$categoryFilter = (string) ($_GET['categorie'] ?? '');
$loaderFilter   = strtoupper((string) ($_GET['chargeur'] ?? ''));
$currentPage    = min(1000, Security::validatePositiveInt($_GET['p'] ?? 1) ?? 1);
$perPage        = 20;
$offset         = ($currentPage - 1) * $perPage;
$csrfToken      = Security::generateCsrfToken();
$listMode       = View::listMode();

// Les serveurs ont désormais leur propre annuaire : les anciens liens y sont redirigés
if (in_array($rawType, ['serveur', 'serveurs'], true)) {
    Http::redirect('/serveurs?' . http_build_query(array_filter(['q' => $searchQuery, 'version' => $versionFilter !== 'all' ? $versionFilter : null])));
}

$typeMap        = ['all' => 'all', 'mod' => 'mod', 'mods' => 'mod', 'plugin' => 'plugin', 'plugins' => 'plugin', 'modpack' => 'modpack', 'modpacks' => 'modpack'];
$normalizedType = $typeMap[$rawType] ?? 'all';
$categoryFilter = isset(ResourceManager::CATEGORIES[$categoryFilter]) ? $categoryFilter : '';
$loaderFilter   = isset(ResourceManager::LOADERS[$loaderFilter]) ? $loaderFilter : '';

$sortOptions = [
    'populaires' => 'Les plus téléchargés',
    'maj'        => 'Mis à jour récemment',
    'recents'    => 'Les plus récents',
    'notes'      => 'Les mieux notés',
    'nom'        => 'Ordre alphabétique',
];
$sort = Security::validateString($_GET['tri'] ?? 'populaires', 1, 20) ?? 'populaires';
if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'populaires';
}

// Nombre d'éléments par onglet (ressources validées) et serveurs de l'annuaire
$tabCounts = ['all' => 0, 'mod' => 0, 'plugin' => 0, 'modpack' => 0, 'serveurs' => 0];
try {
    $typeRows = $db->query("SELECT UPPER(type_ressource) AS t, COUNT(*) AS n FROM RESSOURCE WHERE statut_moderation = 'VALIDE' GROUP BY UPPER(type_ressource)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($typeRows as $typeRow) {
        $key = ['MOD' => 'mod', 'PLUGIN' => 'plugin', 'MOD_PACK' => 'modpack'][(string) $typeRow['t']] ?? null;
        if ($key !== null) {
            $tabCounts[$key] = (int) $typeRow['n'];
            $tabCounts['all'] += (int) $typeRow['n'];
        }
    }
    $tabCounts['serveurs'] = (int) $db->query('SELECT COUNT(*) FROM SERVEUR WHERE est_public = 1')->fetchColumn();
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Catalogue] Erreur comptage des onglets : %s', $e->getMessage()));
}

$items      = [];
$totalItems = 0;
$icons      = [];
$avatars    = [];

try {
    $whereClauses = ["r.statut_moderation = 'VALIDE'"];
    $params       = [];

    $resourceTypeMap = ['mod' => 'MOD', 'plugin' => 'PLUGIN', 'modpack' => 'MOD_PACK'];
    if (isset($resourceTypeMap[$normalizedType])) {
        $whereClauses[]      = 'UPPER(r.type_ressource) = :type_res';
        $params[':type_res'] = $resourceTypeMap[$normalizedType];
    }

    if ($searchQuery !== '') {
        $whereClauses[]    = '(r.nom_ressource LIKE :q_nom ESCAPE \'!\' OR r.resume LIKE :q_res ESCAPE \'!\' OR r.description LIKE :q_desc ESCAPE \'!\')';
        $likeQuery         = Security::likeContains($searchQuery);
        $params[':q_nom']  = $likeQuery;
        $params[':q_res']  = $likeQuery;
        $params[':q_desc'] = $likeQuery;
    }

    if ($versionFilter !== 'all') {
        $whereClauses[]     = 'r.version_compatible = :version';
        $params[':version'] = $versionFilter;
    }

    if ($categoryFilter !== '') {
        $whereClauses[]      = 'r.categories LIKE :categorie';
        $params[':categorie'] = '%,' . $categoryFilter . ',%';
    }

    if ($loaderFilter !== '') {
        $whereClauses[]     = 'r.chargeur = :chargeur';
        $params[':chargeur'] = $loaderFilter;
    }

    $whereSql      = implode(' AND ', $whereClauses);
    $resourceOrder = match ($sort) {
        'maj'     => 'updated_at DESC',
        'recents' => 'r.date_publication DESC',
        'notes'   => 'rating DESC, r.nb_telechargements DESC',
        'nom'     => 'r.nom_ressource ASC',
        default   => 'r.nb_telechargements DESC, r.date_publication DESC',
    };

    $countStmt = $db->prepare("SELECT COUNT(*) FROM RESSOURCE r WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalItems = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT
            r.id_ressource AS id,
            r.nom_ressource AS title,
            r.version_compatible AS version,
            UPPER(r.type_ressource) AS subtype,
            r.nb_telechargements AS downloads,
            r.resume AS summary,
            r.categories,
            r.chargeur,
            r.id_auteur AS author_id,
            u.pseudo AS author,
            COALESCE((SELECT AVG(ar.note) FROM AVIS_RESSOURCE ar WHERE ar.id_ressource = r.id_ressource), 0) AS rating,
            (SELECT COUNT(*) FROM FICHIER_RESSOURCE f WHERE f.id_ressource = r.id_ressource) AS versions,
            COALESCE((SELECT MAX(f2.date_ajout) FROM FICHIER_RESSOURCE f2 WHERE f2.id_ressource = r.id_ressource), r.date_publication) AS updated_at
        FROM RESSOURCE r
        INNER JOIN UTILISATEUR u ON u.id_utilisateur = r.id_auteur
        WHERE {$whereSql}
        ORDER BY {$resourceOrder}, r.id_ressource ASC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $media   = new MediaManager($db);
    $icons   = $media->tokensFor(MediaManager::TARGET_RESOURCE, array_column($items, 'id'), MediaManager::ROLE_ICON);
    $avatars = $media->tokensFor(MediaManager::TARGET_USER, array_column($items, 'author_id'), MediaManager::ROLE_AVATAR);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Catalogue] Erreur requête : %s', $e->getMessage()));
}

$totalPages = max(1, (int) ceil($totalItems / $perPage));
$tabs       = [
    'all'     => ['label' => 'Toutes les créations', 'icon' => 'blocks'],
    'mod'     => ['label' => 'Mods',                 'icon' => 'puzzle'],
    'plugin'  => ['label' => 'Plugins',              'icon' => 'plug'],
    'modpack' => ['label' => 'Modpacks',             'icon' => 'boxes'],
];
$baseQuery     = array_filter([
    'type'      => $normalizedType,
    'q'         => $searchQuery,
    'version'   => $versionFilter !== 'all' ? $versionFilter : null,
    'categorie' => $categoryFilter,
    'chargeur'  => $loaderFilter,
    'tri'       => $sort !== 'populaires' ? $sort : null,
], static fn (mixed $v): bool => $v !== null && $v !== '');
$pageUrl       = static fn (int $page): string => '/catalogue?' . http_build_query(['p' => $page] + $baseQuery);
$viewSwitchUrl = static fn (string $mode): string => '/catalogue?' . http_build_query($baseQuery + ['vue' => $mode, 'p' => $currentPage]);
$hasFilters    = $searchQuery !== '' || $versionFilter !== 'all' || $categoryFilter !== '' || $loaderFilter !== '';
?>
<section class="catalogue" aria-labelledby="catalogue-title">
    <header class="catalogue__header">
        <p class="catalogue__eyebrow">Catalogue communautaire</p>
        <h1 id="catalogue-title" class="catalogue__title">Mods, plugins et modpacks</h1>
        <p class="catalogue__subtitle">Des créations vérifiées par la modération, à télécharger ou à installer en un clic sur votre serveur.</p>
    </header>

    <nav class="catalogue__quick-filters" aria-label="Type de contenu">
        <ul class="catalogue__tabs">
            <?php foreach ($tabs as $tabKey => $tab): ?>
                <li class="catalogue__tab-item">
                    <a href="/catalogue?type=<?= Security::e($tabKey) ?>" class="catalogue__tab-link<?= $normalizedType === $tabKey ? ' catalogue__tab-link--active' : '' ?>"<?= $normalizedType === $tabKey ? ' aria-current="page"' : '' ?>>
                        <?= View::icon($tab['icon']) ?>
                        <?= Security::e($tab['label']) ?>
                        <span class="catalogue__tab-count"><?= View::number($tabCounts[$tabKey]) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
            <li class="catalogue__tab-item catalogue__tab-item--aside">
                <a href="/serveurs" class="catalogue__tab-link">
                    <?= View::icon('earth') ?> Annuaire des serveurs
                    <span class="catalogue__tab-count"><?= View::number($tabCounts['serveurs']) ?></span>
                </a>
            </li>
        </ul>
    </nav>

    <form action="/catalogue" method="get" class="filter-bar" role="search">
        <input type="hidden" name="type" value="<?= Security::e($normalizedType) ?>">
        <label class="filter-bar__search">
            <span class="visually-hidden">Rechercher une création</span>
            <?= View::icon('search') ?>
            <input type="search" name="q" value="<?= Security::e($searchQuery) ?>" maxlength="100" placeholder="Nom, mot-clé ou description…" class="filter-bar__input">
        </label>
        <label class="filter-bar__field">
            <span class="visually-hidden">Version de Minecraft</span>
            <select name="version" class="filter-bar__select" data-autosubmit>
                <option value="all">Toutes les versions</option>
                <?php foreach (ResourceManager::SUPPORTED_MC_VERSIONS as $version): ?>
                    <option value="<?= Security::e($version) ?>"<?= $versionFilter === $version ? ' selected' : '' ?>><?= Security::e($version) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-bar__field">
            <span class="visually-hidden">Catégorie</span>
            <select name="categorie" class="filter-bar__select" data-autosubmit>
                <option value="">Toutes les catégories</option>
                <?php foreach (ResourceManager::CATEGORIES as $slug => $label): ?>
                    <option value="<?= Security::e($slug) ?>"<?= $categoryFilter === $slug ? ' selected' : '' ?>><?= Security::e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-bar__field">
            <span class="visually-hidden">Chargeur</span>
            <select name="chargeur" class="filter-bar__select" data-autosubmit>
                <option value="">Tous les chargeurs</option>
                <?php foreach (ResourceManager::LOADERS as $code => $label): ?>
                    <option value="<?= Security::e($code) ?>"<?= $loaderFilter === $code ? ' selected' : '' ?>><?= Security::e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-bar__field">
            <span class="filter-bar__label"><?= View::icon('sliders-horizontal') ?> Trier</span>
            <select name="tri" class="filter-bar__select" data-autosubmit>
                <?php foreach ($sortOptions as $sortKey => $sortLabel): ?>
                    <option value="<?= Security::e($sortKey) ?>"<?= $sort === $sortKey ? ' selected' : '' ?>><?= Security::e($sortLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="filter-bar__submit"><?= View::icon('search') ?> Rechercher</button>
    </form>

    <div class="list-toolbar">
        <p class="list-toolbar__count" role="status">
            <strong><?= View::number($totalItems) ?></strong> résultat<?= $totalItems > 1 ? 's' : '' ?>
            <?php if ($totalPages > 1): ?>
                &middot; page <?= $currentPage ?> sur <?= $totalPages ?>
            <?php endif; ?>
            <?php if ($hasFilters): ?>
                &middot; <a href="/catalogue?type=<?= Security::e($normalizedType) ?>" class="list-toolbar__reset">Effacer les filtres</a>
            <?php endif; ?>
        </p>
        <?php require __DIR__ . '/../../includes/view-switch.php'; ?>
    </div>

    <?php if (empty($items)): ?>
        <div class="catalogue__empty">
            <span class="catalogue__empty-icon" aria-hidden="true"><?= View::icon('telescope') ?></span>
            <h2 class="catalogue__empty-title">Aucun résultat dans cette région du ciel</h2>
            <p class="catalogue__empty-text">
                Essayez d'élargir votre recherche ou de réinitialiser les filtres de version, de catégorie et de chargeur.
            </p>
            <a href="/catalogue" class="catalogue__reset-btn"><?= View::icon('refresh-cw') ?> Afficher tout le catalogue</a>
        </div>
    <?php elseif ($listMode === 'grille'): ?>
        <div class="card-grid catalogue__results">
            <?php foreach ($items as $item): ?>
                <?php
                $card = [
                    'id'        => $item['id'],
                    'name'      => $item['title'],
                    'type'      => $item['subtype'],
                    'version'   => $item['version'],
                    'summary'   => $item['summary'],
                    'author'    => $item['author'],
                    'downloads' => $item['downloads'],
                    'rating'    => $item['rating'],
                ];
                $cardIcon   = $icons[(int) $item['id']] ?? null;
                $cardAvatar = $avatars[(int) $item['author_id']] ?? null;
                require __DIR__ . '/../../includes/cards/resource-card.php';
                ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="item-list catalogue__results">
            <div class="item-list__head" aria-hidden="true">
                <span>Ressource</span><span>Version</span><span>Téléch.</span><span>Note</span><span>Mise à jour</span><span></span>
            </div>
            <?php foreach ($items as $row): ?>
                <?php
                $rowIcon   = $icons[(int) $row['id']] ?? null;
                $rowAvatar = $avatars[(int) $row['author_id']] ?? null;
                require __DIR__ . '/../../includes/lists/resource-row.php';
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($items) && $totalPages > 1): ?>
        <nav class="pagination" aria-label="Pagination du catalogue">
            <?php if ($currentPage > 1): ?>
                <a href="<?= Security::e($pageUrl($currentPage - 1)) ?>" class="pagination__link" aria-label="Page précédente"><?= View::icon('chevron-left') ?></a>
            <?php endif; ?>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a href="<?= Security::e($pageUrl($p)) ?>"
                   class="pagination__link<?= $p === $currentPage ? ' pagination__link--active' : '' ?>"
                   <?= $p === $currentPage ? 'aria-current="page"' : '' ?>><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($currentPage < $totalPages): ?>
                <a href="<?= Security::e($pageUrl($currentPage + 1)) ?>" class="pagination__link" aria-label="Page suivante"><?= View::icon('chevron-right') ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
