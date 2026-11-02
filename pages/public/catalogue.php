<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Security;

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

        $whereSql = implode(' AND ', $whereClauses);

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
            ORDER BY s.statut DESC, s.joueurs_connectes DESC
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

        $whereSql = implode(' AND ', $whereClauses);

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
            ORDER BY r.nb_telechargements DESC, r.date_publication DESC
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
<section class="catalogue" aria-labelledby="catalogue-title">
    <header class="catalogue__header">
        <h1 id="catalogue-title" class="catalogue__title">Catalogue Communautaire Boötes</h1>
        <p class="catalogue__subtitle">
            Explorez les serveurs publics, mods, plugins et modpacks compatibles avec votre version de Minecraft.
        </p>

        <nav class="catalogue__quick-filters" aria-label="Filtres rapides par type">
            <ul class="catalogue__tabs">
                <li class="catalogue__tab-item">
                    <a href="/catalogue?type=all" class="catalogue__tab-link <?= $normalizedType === 'all' ? 'catalogue__tab-link--active' : '' ?>">Toutes les ressources</a>
                </li>
                <li class="catalogue__tab-item">
                    <a href="/catalogue?type=serveur" class="catalogue__tab-link <?= $normalizedType === 'serveur' ? 'catalogue__tab-link--active' : '' ?>">Serveurs Publics</a>
                </li>
                <li class="catalogue__tab-item">
                    <a href="/catalogue?type=mod" class="catalogue__tab-link <?= $normalizedType === 'mod' ? 'catalogue__tab-link--active' : '' ?>">Mods</a>
                </li>
                <li class="catalogue__tab-item">
                    <a href="/catalogue?type=plugin" class="catalogue__tab-link <?= $normalizedType === 'plugin' ? 'catalogue__tab-link--active' : '' ?>">Plugins</a>
                </li>
                <li class="catalogue__tab-item">
                    <a href="/catalogue?type=modpack" class="catalogue__tab-link <?= $normalizedType === 'modpack' ? 'catalogue__tab-link--active' : '' ?>">Modpacks</a>
                </li>
            </ul>
        </nav>
    </header>

    <?php
    $searchAction = '/catalogue';
    require __DIR__ . '/../../includes/recherche.php';
    ?>

    <div class="catalogue__meta-bar" role="status">
        <p class="catalogue__count">
            <strong><?= $totalItems ?></strong> résultat<?= $totalItems > 1 ? 's' : '' ?> trouvé<?= $totalItems > 1 ? 's' : '' ?>
            (Page <?= $currentPage ?> sur <?= $totalPages ?>)
        </p>
    </div>

    <?php if (empty($items)): ?>
        <article class="catalogue__empty">
            <h2 class="catalogue__empty-title">Aucun résultat ne correspond à vos critères</h2>
            <p class="catalogue__empty-text">
                Essayez d'élargir votre recherche ou de réinitialiser les filtres de version et de catégorie.
            </p>
            <a href="/catalogue" class="catalogue__reset-btn">Afficher tout le catalogue</a>
        </article>
    <?php else: ?>
        <div class="catalogue__grid">
            <?php foreach ($items as $item): ?>
                <?php
                $itemId      = (int) $item['id'];
                $isServer    = ($item['item_category'] === 'SERVEUR');
                $detailUrl   = $isServer ? "/serveur-public?id={$itemId}" : "/ressource-detail?id={$itemId}";
                $ratingValue = round((float) $item['rating'], 1);
                ?>
                <article class="catalogue-card <?= $isServer ? 'catalogue-card--server' : 'catalogue-card--resource' ?>">
                    <header class="catalogue-card__header">
                        <span class="catalogue-card__badge"><?= Security::e((string) $item['subtype']) ?></span>
                        <span class="catalogue-card__version">MC <?= Security::e((string) $item['version']) ?></span>
                    </header>

                    <div class="catalogue-card__body">
                        <h2 class="catalogue-card__title">
                            <a href="<?= Security::e($detailUrl) ?>" class="catalogue-card__link">
                                <?= Security::e((string) $item['title']) ?>
                            </a>
                        </h2>
                        <p class="catalogue-card__author">
                            Par <strong><?= Security::e((string) $item['author']) ?></strong>
                        </p>
                        <p class="catalogue-card__summary">
                            <?= Security::e(mb_strimwidth((string) ($item['summary'] ?? ''), 0, 150, '...')) ?>
                        </p>
                    </div>

                    <footer class="catalogue-card__footer">
                        <?php if ($isServer): ?>
                            <span class="catalogue-card__stat">
                                <?= Security::e((string) $item['status']) ?> &bull;
                                <?= (int) $item['joueurs_connectes'] ?>/<?= (int) $item['slots_max'] ?> joueurs
                            </span>
                        <?php else: ?>
                            <span class="catalogue-card__stat">
                                <?= (int) $item['downloads'] ?> téléchargements
                            </span>
                        <?php endif; ?>

                        <span class="catalogue-card__rating">Note : <?= Security::e((string) $ratingValue) ?>/5</span>
                        <a href="<?= Security::e($detailUrl) ?>" class="catalogue-card__btn">Consulter</a>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Pagination du catalogue">
                <ul class="pagination__list">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php
                        $queryParams = [
                            'type'    => $normalizedType,
                            'q'       => $searchQuery,
                            'version' => $versionFilter,
                            'p'       => $p,
                        ];
                        $pageUrl = '/catalogue?' . http_build_query($queryParams);
                        ?>
                        <li class="pagination__item">
                            <a href="<?= Security::e($pageUrl) ?>"
                               class="pagination__link <?= $p === $currentPage ? 'pagination__link--active' : '' ?>"
                               <?= $p === $currentPage ? 'aria-current="page"' : '' ?>>
                                <?= $p ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>