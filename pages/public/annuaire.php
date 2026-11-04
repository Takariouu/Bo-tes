<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\MediaManager;
use Bootes\Src\Security;
use Bootes\Src\ServerDirectory;
use Bootes\Src\View;

$db        = $db ?? Database::getInstance();
$directory = new ServerDirectory($db);
$listMode  = View::listMode();

$filters = [
    'q'        => Security::validateString($_GET['q'] ?? '', 0, 100) ?? '',
    'tag'      => isset(ServerDirectory::TAGS[$_GET['tag'] ?? '']) ? (string) $_GET['tag'] : '',
    'version'  => in_array($_GET['version'] ?? '', ServerDirectory::VERSIONS, true) ? (string) $_GET['version'] : '',
    'origine'  => in_array($_GET['origine'] ?? '', [ServerDirectory::ORIGIN_HOSTED, ServerDirectory::ORIGIN_EXTERNAL], true) ? (string) $_GET['origine'] : '',
    'en_ligne' => ($_GET['en_ligne'] ?? '') === '1',
    'tri'      => array_key_exists($_GET['tri'] ?? '', ServerDirectory::SORTS) ? (string) $_GET['tri'] : 'populaires',
];
$currentPage = min(1000, Security::validatePositiveInt($_GET['p'] ?? 1) ?? 1);
$perPage     = 20;
$offset      = ($currentPage - 1) * $perPage;

$result = ['items' => [], 'total' => 0];
$icons  = [];
try {
    $result = $directory->search($filters, $perPage, $offset);
    $icons  = (new MediaManager($db))->tokensFor(MediaManager::TARGET_SERVER, array_column($result['items'], 'id_serveur'), MediaManager::ROLE_ICON);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Annuaire] Erreur de recherche : %s', $e->getMessage()));
}

$totalPages = max(1, (int) ceil($result['total'] / $perPage));
$baseQuery  = array_filter([
    'q'        => $filters['q'],
    'tag'      => $filters['tag'],
    'version'  => $filters['version'],
    'origine'  => $filters['origine'],
    'en_ligne' => $filters['en_ligne'] ? '1' : null,
    'tri'      => $filters['tri'] !== 'populaires' ? $filters['tri'] : null,
], static fn (mixed $v): bool => $v !== null && $v !== '');
$pageUrl       = static fn (int $page): string => '/serveurs?' . http_build_query(['p' => $page] + $baseQuery);
$tagUrl        = static fn (string $tag): string => '/serveurs?' . http_build_query(array_filter(['tag' => $tag] + $baseQuery, static fn (mixed $v): bool => $v !== ''));
$viewSwitchUrl = static fn (string $mode): string => '/serveurs?' . http_build_query($baseQuery + ['vue' => $mode, 'p' => $currentPage]);
$showRank      = in_array($filters['tri'], ['populaires', 'joueurs', 'notes'], true);
$addUrl        = Auth::isLogged() ? '/user/annuaire-edition' : '/auth?mode=register';
?>
<section class="directory" aria-labelledby="directory-title">
    <header class="directory__header">
        <div class="directory__intro">
            <p class="directory__eyebrow">Annuaire public</p>
            <h1 id="directory-title" class="directory__title">Serveurs Minecraft</h1>
            <p class="directory__subtitle">
                Les serveurs hébergés sur Boötes et ceux référencés par la communauté, avec leur adresse à copier en un clic.
            </p>
        </div>
        <a href="<?= Security::e($addUrl) ?>" class="directory__btn directory__btn--primary"><?= View::icon('plus') ?> Ajouter mon serveur</a>
    </header>

    <nav class="tag-bar" aria-label="Filtrer par style de jeu">
        <a href="<?= Security::e($tagUrl('')) ?>" class="tag-bar__chip<?= $filters['tag'] === '' ? ' is-active' : '' ?>"<?= $filters['tag'] === '' ? ' aria-current="true"' : '' ?>>Tous</a>
        <?php foreach (ServerDirectory::TAGS as $slug => $label): ?>
            <a href="<?= Security::e($tagUrl($slug)) ?>" class="tag-bar__chip<?= $filters['tag'] === $slug ? ' is-active' : '' ?>"<?= $filters['tag'] === $slug ? ' aria-current="true"' : '' ?>><?= Security::e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <form action="/serveurs" method="get" class="filter-bar" role="search">
        <?php if ($filters['tag'] !== ''): ?>
            <input type="hidden" name="tag" value="<?= Security::e($filters['tag']) ?>">
        <?php endif; ?>
        <label class="filter-bar__search">
            <span class="visually-hidden">Rechercher un serveur</span>
            <?= View::icon('search') ?>
            <input type="search" name="q" value="<?= Security::e($filters['q']) ?>" maxlength="100" placeholder="Nom, adresse ou description…" class="filter-bar__input">
        </label>
        <label class="filter-bar__field">
            <span class="visually-hidden">Version</span>
            <select name="version" class="filter-bar__select" data-autosubmit>
                <option value="">Toutes les versions</option>
                <?php foreach (ServerDirectory::VERSIONS as $version): ?>
                    <option value="<?= Security::e($version) ?>"<?= $filters['version'] === $version ? ' selected' : '' ?>><?= Security::e($version) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-bar__field">
            <span class="visually-hidden">Origine</span>
            <select name="origine" class="filter-bar__select" data-autosubmit>
                <option value="">Hébergés et externes</option>
                <option value="<?= ServerDirectory::ORIGIN_HOSTED ?>"<?= $filters['origine'] === ServerDirectory::ORIGIN_HOSTED ? ' selected' : '' ?>>Hébergés sur Boötes</option>
                <option value="<?= ServerDirectory::ORIGIN_EXTERNAL ?>"<?= $filters['origine'] === ServerDirectory::ORIGIN_EXTERNAL ? ' selected' : '' ?>>Serveurs externes</option>
            </select>
        </label>
        <label class="filter-bar__toggle">
            <input type="checkbox" name="en_ligne" value="1"<?= $filters['en_ligne'] ? ' checked' : '' ?> data-autosubmit>
            En ligne uniquement
        </label>
        <label class="filter-bar__field">
            <span class="filter-bar__label"><?= View::icon('sliders-horizontal') ?> Trier</span>
            <select name="tri" class="filter-bar__select" data-autosubmit>
                <?php foreach (ServerDirectory::SORTS as $sortKey => $sortLabel): ?>
                    <option value="<?= Security::e($sortKey) ?>"<?= $filters['tri'] === $sortKey ? ' selected' : '' ?>><?= Security::e($sortLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="filter-bar__submit"><?= View::icon('search') ?> Rechercher</button>
    </form>

    <div class="list-toolbar">
        <p class="list-toolbar__count" role="status">
            <strong><?= View::number($result['total']) ?></strong> serveur<?= $result['total'] > 1 ? 's' : '' ?>
            <?php if ($totalPages > 1): ?>
                &middot; page <?= $currentPage ?> sur <?= $totalPages ?>
            <?php endif; ?>
            <?php if ($baseQuery !== [] && array_keys($baseQuery) !== ['tri']): ?>
                &middot; <a href="/serveurs" class="list-toolbar__reset">Effacer les filtres</a>
            <?php endif; ?>
        </p>
        <?php require __DIR__ . '/../../includes/view-switch.php'; ?>
    </div>

    <?php if (empty($result['items'])): ?>
        <div class="catalogue__empty">
            <span class="catalogue__empty-icon" aria-hidden="true"><?= View::icon('telescope') ?></span>
            <h2 class="catalogue__empty-title">Aucun serveur ne correspond</h2>
            <p class="catalogue__empty-text">Retirez un filtre, ou ajoutez le vôtre à l'annuaire : il apparaîtra ici pour toute la communauté.</p>
            <a href="/serveurs" class="catalogue__reset-btn"><?= View::icon('refresh-cw') ?> Voir tous les serveurs</a>
        </div>
    <?php elseif ($listMode === 'grille'): ?>
        <div class="card-grid directory__results">
            <?php foreach ($result['items'] as $server): ?>
                <?php
                $card = [
                    'id'          => $server['id_serveur'],
                    'name'        => $server['nom_serveur'],
                    'version'     => $server['version_mc'],
                    'mode'        => $server['mode_jeu'],
                    'status'      => $server['statut'],
                    'players'     => $server['joueurs_connectes'],
                    'slots'       => $server['slots_max'],
                    'description' => (string) ($server['resume'] ?? '') !== '' ? $server['resume'] : $server['description'],
                    'owner'       => $server['proprietaire'],
                    'rating'      => $server['note'],
                ];
                $cardIcon = $icons[(int) $server['id_serveur']] ?? null;
                require __DIR__ . '/../../includes/cards/server-card.php';
                ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="server-list directory__results">
            <?php foreach ($result['items'] as $index => $row): ?>
                <?php
                $rowRank = $showRank ? $offset + $index + 1 : null;
                $rowIcon = $icons[(int) $row['id_serveur']] ?? null;
                require __DIR__ . '/../../includes/lists/server-row.php';
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($result['items']) && $totalPages > 1): ?>
        <nav class="pagination" aria-label="Pagination de l'annuaire">
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
