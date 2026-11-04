<?php

declare(strict_types=1);

use Bootes\Src\Auth;
use Bootes\Src\Security;
use Bootes\Src\View;

$currentRoute      = isset($currentRoute) && is_string($currentRoute) ? $currentRoute : '';
$layoutContext     = isset($layoutContext) && is_string($layoutContext) ? $layoutContext : null;
$activeServer      = isset($activeServer) && is_array($activeServer) ? $activeServer : null;
$serverAccessLevel = isset($serverAccessLevel) && is_string($serverAccessLevel) ? $serverAccessLevel : null;
$pendingReports    = isset($pendingReports) && is_int($pendingReports) ? $pendingReports : 0;
$csrfToken         = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : Security::generateCsrfToken();

// Pages publiques : pas de colonne latérale, le contenu occupe toute la largeur.
if ($layoutContext === null || ($layoutContext === 'server' && $activeServer === null)) {
    return;
}

/**
 * @param array<int, array{label: string, url: string, icon: string, route?: string, count?: int}> $links
 */
$renderLinks = static function (array $links, string $currentRoute): void {
    foreach ($links as $link) {
        $isActive = isset($link['route']) && $link['route'] === $currentRoute;
        ?>
        <li class="sidebar__item">
            <a href="<?= Security::e($link['url']) ?>" class="sidebar__link<?= $isActive ? ' sidebar__link--active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                <?= View::icon($link['icon'], 'sidebar__icon') ?>
                <span class="sidebar__label"><?= Security::e($link['label']) ?></span>
                <?php if (!empty($link['count'])): ?>
                    <span class="sidebar__count" title="<?= (int) $link['count'] ?> en attente"><?= (int) $link['count'] ?></span>
                <?php endif; ?>
            </a>
        </li>
        <?php
    }
};
?>
<?php if ($layoutContext === 'server' && $activeServer !== null): ?>
    <?php
    $serverId   = (int) $activeServer['id_serveur'];
    $status     = (string) $activeServer['statut'];
    $isOnline   = $status === 'EN_LIGNE';
    $address    = sprintf('%s:%d', (string) $activeServer['adresse_ip'], (int) $activeServer['port']);
    $canOperate = Auth::serverLevelAllows($serverAccessLevel, Auth::SERVER_PERM_CONSOLE);
    $serverLinks = [
        ['route' => 'serveur/dashboard',    'label' => 'Tableau de bord',    'icon' => 'layout-dashboard'],
        ['route' => 'serveur/console',      'label' => 'Console',            'icon' => 'square-terminal'],
        ['route' => 'serveur/logs',         'label' => 'Journaux',           'icon' => 'scroll-text'],
        ['route' => 'serveur/joueurs',      'label' => 'Joueurs',            'icon' => 'users'],
        ['route' => 'serveur/backups',      'label' => 'Sauvegardes',        'icon' => 'archive'],
        ['route' => 'serveur/map',          'label' => 'Carte',              'icon' => 'map'],
        ['route' => 'serveur/statistiques', 'label' => 'Statistiques',       'icon' => 'chart-line'],
        ['route' => 'serveur/acces-admin',  'label' => 'Accès et co-admins', 'icon' => 'key-round'],
    ];
    foreach ($serverLinks as &$link) {
        $link['url'] = '/' . $link['route'] . '?id=' . $serverId;
    }
    unset($link);
    ?>
    <aside class="sidebar sidebar--server" aria-label="Gestion du serveur">
        <div class="sidebar__inner">
            <div class="server-summary">
                <div class="server-summary__head">
                    <span class="server-summary__icon" aria-hidden="true"><?= View::icon('server') ?></span>
                    <div class="server-summary__identity">
                        <p class="server-summary__name"><?= Security::e((string) $activeServer['nom_serveur']) ?></p>
                        <p class="server-summary__meta">
                            <?= Security::e(View::label('loader', $activeServer['type_loader'])) ?>
                            <?= Security::e((string) $activeServer['version_mc']) ?>
                            &middot; <?= Security::e(View::label('mode_jeu', $activeServer['mode_jeu'])) ?>
                        </p>
                    </div>
                </div>

                <div class="server-summary__state">
                    <?= View::badge('statut_serveur', $status) ?>
                    <span class="server-summary__players" title="Joueurs connectés">
                        <?= View::icon('users') ?>
                        <?= (int) $activeServer['joueurs_connectes'] ?>/<?= (int) $activeServer['slots_max'] ?>
                    </span>
                </div>

                <div class="server-summary__address">
                    <code><?= Security::e($address) ?></code>
                    <button type="button" class="copy-btn" data-copy="<?= Security::e($address) ?>" aria-label="Copier l'adresse du serveur" title="Copier l'adresse">
                        <?= View::icon('copy') ?>
                    </button>
                </div>

                <?php if ($canOperate && $currentRoute !== 'serveur/dashboard'): ?>
                    <div class="server-summary__power" role="group" aria-label="Alimentation du serveur">
                        <?php
                        $powerActions = [
                            ['action' => 'start',   'label' => 'Démarrer',   'icon' => 'play',      'enabled' => !$isOnline, 'confirm' => null],
                            ['action' => 'restart', 'label' => 'Redémarrer', 'icon' => 'rotate-cw', 'enabled' => $isOnline,  'confirm' => null],
                            ['action' => 'stop',    'label' => 'Arrêter',    'icon' => 'square',    'enabled' => $isOnline,  'confirm' => 'Arrêter le serveur ? Les joueurs connectés seront déconnectés.'],
                        ];
                        ?>
                        <?php foreach ($powerActions as $power): ?>
                            <form action="/serveur/dashboard?id=<?= $serverId ?>" method="post" class="server-summary__power-form">
                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                <input type="hidden" name="server_id" value="<?= $serverId ?>">
                                <input type="hidden" name="action" value="<?= Security::e($power['action']) ?>">
                                <button type="submit" class="power-btn power-btn--<?= Security::e($power['action']) ?>" title="<?= Security::e($power['label']) ?>"
                                    <?= $power['enabled'] ? '' : 'disabled' ?>
                                    <?= $power['confirm'] !== null ? 'data-confirm="' . Security::e($power['confirm']) . '"' : '' ?>>
                                    <?= View::icon($power['icon']) ?>
                                    <span class="power-btn__label"><?= Security::e($power['label']) ?></span>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <nav class="sidebar__nav" aria-label="Rubriques du serveur">
                <ul class="sidebar__list">
                    <?php $renderLinks($serverLinks, $currentRoute); ?>
                </ul>
            </nav>

            <a href="/user/compte#section-serveurs" class="sidebar__back"><?= View::icon('arrow-left') ?> Tous mes serveurs</a>
        </div>
    </aside>

<?php elseif ($layoutContext === 'admin'): ?>
    <?php
    $adminLinks = [
        ['route' => 'admin/dashboard',    'url' => '/admin/dashboard',    'label' => 'Vue d\'ensemble',          'icon' => 'layout-dashboard'],
        ['route' => 'admin/hardware',     'url' => '/admin/hardware',     'label' => 'Supervision matérielle',   'icon' => 'cpu'],
        ['route' => 'admin/utilisateurs', 'url' => '/admin/utilisateurs', 'label' => 'Utilisateurs et contenus', 'icon' => 'users'],
        ['route' => 'admin/signalements', 'url' => '/admin/signalements', 'label' => 'Modération',               'icon' => 'flag', 'count' => $pendingReports],
    ];
    if (Auth::isSuperAdmin()) {
        $adminLinks[] = ['route' => 'admin/super-admin', 'url' => '/admin/super-admin', 'label' => 'Super-admin et journaux', 'icon' => 'crown'];
    }
    ?>
    <aside class="sidebar sidebar--admin" aria-label="Administration">
        <div class="sidebar__inner">
            <header class="sidebar__header">
                <p class="sidebar__title"><?= View::icon('shield-check') ?> Administration</p>
                <p class="sidebar__subtitle">Hôte Linux Mint &middot; 6 Go pour Minecraft sur 8 Go</p>
            </header>
            <nav class="sidebar__nav" aria-label="Rubriques d'administration">
                <ul class="sidebar__list">
                    <?php $renderLinks($adminLinks, $currentRoute); ?>
                </ul>
            </nav>
        </div>
    </aside>

<?php else: ?>
    <?php
    $sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : ['pseudo' => '', 'role' => '', 'type_compte' => ''];
    $accountLinks = [
        ['route' => 'user/compte',                'url' => '/user/compte',                 'label' => 'Mon compte',            'icon' => 'user-round'],
        ['url' => '/user/compte#section-serveurs', 'label' => 'Mes serveurs',          'icon' => 'server'],
        ['url' => '/user/compte#section-amis',     'label' => 'Amis',                  'icon' => 'users'],
        ['url' => '/user/compte#section-tickets',  'label' => 'Support',               'icon' => 'life-buoy'],
        ['route' => 'user/createur-edition',      'url' => '/user/createur-edition',      'label' => 'Atelier créateur',      'icon' => 'paintbrush'],
        ['route' => 'user/createur-statistiques', 'url' => '/user/createur-statistiques', 'label' => 'Statistiques créateur', 'icon' => 'chart-column'],
    ];
    ?>
    <aside class="sidebar sidebar--account" aria-label="Mon espace">
        <div class="sidebar__inner">
            <header class="sidebar__header sidebar__header--profile">
                <span class="avatar" aria-hidden="true"><?= Security::e(View::initials((string) $sessionUser['pseudo'])) ?></span>
                <div>
                    <p class="sidebar__title"><?= Security::e((string) $sessionUser['pseudo']) ?></p>
                    <p class="sidebar__subtitle">
                        <?= Security::e(View::label('role', $sessionUser['role'])) ?> &middot; offre <?= Security::e(View::label('offre', $sessionUser['type_compte'])) ?>
                    </p>
                </div>
            </header>
            <nav class="sidebar__nav" aria-label="Rubriques de mon espace">
                <ul class="sidebar__list">
                    <?php $renderLinks($accountLinks, $currentRoute); ?>
                </ul>
            </nav>
        </div>
    </aside>
<?php endif; ?>
