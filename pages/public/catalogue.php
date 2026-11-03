<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Security;
use Bootes\Src\View;

$db = $db ?? Database::getInstance();

$rawType       = strtolower(Security::validateString($_GET['type'] ?? 'all', 1, 20) ?? 'all');
$searchQuery   = Security::validateString($_GET['q'] ?? '', 0, 100) ?? '';
$versionFilter = Security::validateString($_GET['version'] ?? 'all', 1, 20) ?? 'all';
$currentPage   = min(1000, Security::validatePositiveInt($_GET['p'] ?? 1) ?? 1);
$perPage       = 12;
$offset        = ($currentPage - 1) * $perPage;

// Normalisation singulier/pluriel entre ?type=serveur|mod|plugin|modpack et le composant recherche.php
$typeMap = [
    'all'      => 'all',
    'serveur'  => 'serveur',
    'serveurs' => 'serveur',
    'mod'      => 'mod',
    'mods'     => 'mod',
    'plugin'   => 'plugin',
    'plugins'  => 'plugin',
    'modpack'  => 'modpack',
    'modpacks' => 'modpack',
];

$normalizedType = $typeMap[$rawType] ?? 'all';

// Tri : liste blanche des ordres proposés
$sortOptions = ['populaires' => 'Les plus populaires', 'recents' => 'Les plus récents', 'notes' => 'Les mieux notés'];
$sort        = Security::validateString($_GET['tri'] ?? 'populaires', 1, 20) ?? 'populaires';
if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'populaires';
}

// Nombre d'éléments par onglet (serveurs publics et ressources validées)
$tabCounts = ['all' => 0, 'serveur' => 0, 'mod' => 0, 'plugin' => 0, 'modpack' => 0];
try {
    $tabCounts['serveur'] = (int) $db->query('SELECT COUNT(*) FROM SERVEUR WHERE est_public = 1')->fetchColumn();
    $typeRows = $db->query("SELECT UPPER(type_ressource) AS t, COUNT(*) AS n FROM RESSOURCE WHERE statut_moderation = 'VALIDE' GROUP BY UPPER(type_ressource)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($typeRows as $row) {
        $key = ['MOD' => 'mod', 'PLUGIN' => 'plugin', 'MOD_PACK' => 'modpack'][(string) $row['t']] ?? null;
        if ($key !== null) {
            $tabCounts[$key] = (int) $row['n'];
            $tabCounts['all'] += (int) $row['n'];
        }
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Catalogue] Erreur comptage des onglets : %s', $e->getMessage()));
}

$items      = [];
$totalItems = 0;

try {
    if ($normalizedType === 'serveur') {
        $whereClauses = ['s.est_public = 1'];
        $params       = [];

        if ($searchQuery !== '') {
            $whereClauses[]   = '(s.nom_serveur LIKE :q_nom ESCAPE \'!\' OR s.description LIKE :q_desc ESCAPE \'!\' OR s.mode_jeu LIKE :q_mode ESCAPE \'!\')';
            $likeQuery        = Security::likeContains($searchQuery);
            $params[':q_nom']  = $likeQuery;
            $params[':q_desc'] = $likeQuery;
            $params[':q_mode'] = $likeQuery;
        }

        if ($versionFilter !== 'all') {
            $whereClauses[]        = 's.version_mc = :version';
            $params[':version']    = $versionFilter;
        }

        $whereSql    = implode(' AND ', $whereClauses);
        $serverOrder = match ($sort) {
            'recents' => 's.date_creation DESC',
            'notes'   => 'rating DESC, s.joueurs_connectes DESC',
            default   => "CASE s.statut WHEN 'EN_LIGNE' THEN 0 WHEN 'DEMARRAGE' THEN 1 WHEN 'MAINTENANCE' THEN 2 ELSE 3 END, s.joueurs_connectes DESC",
        };

        $countStmt = $db->prepare("SELECT COUNT(*) FROM SERVEUR s WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalItems = (int) $countStmt->fetchColumn();

        $sql = "
            SELECT 
                s.id_serveur AS id,
                'SERVEUR' AS item_category,
                s.nom_serveur AS title,
                s.version_mc AS version,
                s.mode_jeu AS subtype,
                s.statut AS status,
                s.joueurs_connectes,
                s.slots_max,
                s.description AS summary,
                u.pseudo AS author,
                COALESCE(AVG(a.note), 0) AS rating
            FROM SERVEUR s
            INNER JOIN UTILISATEUR u ON u.id_utilisateur = s.id_proprietaire
            LEFT JOIN AVIS_SERVEUR a ON a.id_serveur = s.id_serveur
            WHERE {$whereSql}
            GROUP BY s.id_serveur, s.nom_serveur, s.version_mc, s.mode_jeu, s.statut, s.joueurs_connectes, s.slots_max, s.description, u.pseudo
            ORDER BY {$serverOrder}
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $whereClauses = ["r.statut_moderation = 'VALIDE'"];
        $params       = [];

        $resourceTypeMap = ['mod' => 'MOD', 'plugin' => 'PLUGIN', 'modpack' => 'MOD_PACK'];
        if (isset($resourceTypeMap[$normalizedType])) {
            $whereClauses[]          = 'UPPER(r.type_ressource) = :type_res';
            $params[':type_res']     = $resourceTypeMap[$normalizedType];
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

        $whereSql      = implode(' AND ', $whereClauses);
        $resourceOrder = match ($sort) {
            'recents' => 'r.date_publication DESC',
            'notes'   => 'rating DESC, r.nb_telechargements DESC',
            default   => 'r.nb_telechargements DESC, r.date_publication DESC',
        };

        $countStmt = $db->prepare("SELECT COUNT(*) FROM RESSOURCE r WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalItems = (int) $countStmt->fetchColumn();

        $sql = "
            SELECT 
                r.id_ressource AS id,
                'RESSOURCE' AS item_category,
                r.nom_ressource AS title,
                r.version_compatible AS version,
                UPPER(r.type_ressource) AS subtype,
                r.nb_telechargements AS downloads,
                r.resume AS summary,
                u.pseudo AS author,
                COALESCE(AVG(ar.note), 0) AS rating
            FROM RESSOURCE r
            INNER JOIN UTILISATEUR u ON u.id_utilisateur = r.id_auteur
            LEFT JOIN AVIS_RESSOURCE ar ON ar.id_ressource = r.id_ressource
            WHERE {$whereSql}
            GROUP BY r.id_ressource, r.nom_ressource, r.version_compatible, r.type_ressource, r.nb_telechargements, r.resume, u.pseudo
            ORDER BY {$resourceOrder}
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Catalogue] Erreur requête : %s', $e->getMessage()));
}

$totalPages = max(1, (int) ceil($totalItems / $perPage));
?>
<?php
$tabs = [
    'all'     => ['label' => 'Toutes les créations', 'icon' => 'blocks'],
    'serveur' => ['label' => 'Serveurs publics',     'icon' => 'earth'],
    'mod'     => ['label' => 'Mods',                 'icon' => 'puzzle'],
    'plugin'  => ['label' => 'Plugins',              'icon' => 'plug'],
    'modpack' => ['label' => 'Modpacks',             'icon' => 'boxes'],
];
$baseQuery = ['type' => $normalizedType, 'q' => $searchQuery, 'version' => $versionFilter, 'tri' => $sort];
$pageUrl   = static fn (int $page): string => '/catalogue?' . http_build_query(['p' => $page] + $baseQuery);
?>
<section class="catalogue" aria-labelledby="catalogue-title">
    <header class="catalogue__header">
        <p class="catalogue__eyebrow">Catalogue communautaire</p>
        <h1 id="catalogue-title" class="catalogue__title">
            <?= $normalizedType === 'serveur' ? 'Serveurs publics' : 'Mods, plugins et modpacks' ?>
        </h1>
        <p class="catalogue__subtitle">
            <?= $normalizedType === 'serveur'
                ? 'Rejoignez les mondes ouverts au public par la communauté Boötes.'
                : 'Des créations vérifiées par la modération, à installer en un clic sur votre serveur.' ?>
        </p>
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
        </ul>
    </nav>

    <?php
    $searchAction        = '/catalogue';
    $searchShowCategories = false;
    $searchHidden         = ['type' => $normalizedType, 'tri' => $sort];
    require __DIR__ . '/../../includes/recherche.php';
    ?>

    <div class="catalogue__meta-bar">
        <p class="catalogue__count" role="status">
            <strong><?= View::number($totalItems) ?></strong> résultat<?= $totalItems > 1 ? 's' : '' ?>
            <?php if ($totalPages > 1): ?>
                &middot; page <?= $currentPage ?> sur <?= $totalPages ?>
            <?php endif; ?>
        </p>
        <form action="/catalogue" method="get" class="catalogue__sort">
            <input type="hidden" name="type" value="<?= Security::e($normalizedType) ?>">
            <input type="hidden" name="q" value="<?= Security::e($searchQuery) ?>">
            <input type="hidden" name="version" value="<?= Security::e($versionFilter) ?>">
            <label for="catalogue-sort" class="catalogue__sort-label"><?= View::icon('sliders-horizontal') ?> Trier</label>
            <select id="catalogue-sort" name="tri" class="catalogue__sort-select" data-autosubmit>
                <?php foreach ($sortOptions as $sortKey => $sortLabel): ?>
                    <option value="<?= Security::e($sortKey) ?>"<?= $sort === $sortKey ? ' selected' : '' ?>><?= Security::e($sortLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="catalogue__sort-btn" data-autosubmit-fallback>Appliquer</button>
        </form>
    </div>

    <?php if (empty($items)): ?>
        <div class="catalogue__empty">
            <span class="catalogue__empty-icon" aria-hidden="true"><?= View::icon('telescope') ?></span>
            <h2 class="catalogue__empty-title">Aucun résultat dans cette région du ciel</h2>
            <p class="catalogue__empty-text">
                Essayez d'élargir votre recherche ou de réinitialiser les filtres de version et de catégorie.
            </p>
            <a href="/catalogue" class="catalogue__reset-btn"><?= View::icon('refresh-cw') ?> Afficher tout le catalogue</a>
        </div>
    <?php else: ?>
        <div class="card-grid catalogue__results">
            <?php foreach ($items as $item): ?>
                <?php
                if ($item['item_category'] === 'SERVEUR') {
                    $card = [
                        'id'          => $item['id'],
                        'name'        => $item['title'],
                        'version'     => $item['version'],
                        'mode'        => $item['subtype'],
                        'status'      => $item['status'],
                        'players'     => $item['joueurs_connectes'],
                        'slots'       => $item['slots_max'],
                        'description' => $item['summary'],
                        'owner'       => $item['author'],
                        'rating'      => $item['rating'],
                    ];
                    require __DIR__ . '/../../includes/cards/server-card.php';
                } else {
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
                    require __DIR__ . '/../../includes/cards/resource-card.php';
                }
                ?>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
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
    <?php endif; ?>
</section>
