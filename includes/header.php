<?php

declare(strict_types=1);

use Bootes\Src\Auth;
use Bootes\Src\Security;
use Bootes\Src\View;

Auth::initSession();

$pageTitle     = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Boötes — Hébergement de serveurs Minecraft';
$currentRoute  = isset($currentRoute) && is_string($currentRoute) ? $currentRoute : '';
$layoutContext = isset($layoutContext) && is_string($layoutContext) ? $layoutContext : null;
$currentUser   = Auth::isLogged() && is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : null;
$csrfToken     = Security::generateCsrfToken();
$theme         = View::theme();
$themeLabel    = $theme === View::THEME_LIGHT ? 'Passer au thème sombre' : 'Passer au thème clair';
$stylesheets   = ['variables', 'style', 'components', 'layout', 'terminal', 'pages', 'vitrine'];

// Rubrique active de la navigation principale
$catalogueType = strtolower(is_string($_GET['type'] ?? null) ? $_GET['type'] : '');
$activeSection = match (true) {
    $currentRoute === 'home'                                                       => 'home',
    $currentRoute === 'serveur-public',
    $currentRoute === 'serveurs',
    $currentRoute === 'catalogue' && in_array($catalogueType, ['serveur', 'serveurs'], true) => 'servers',
    $currentRoute === 'catalogue', $currentRoute === 'ressource-detail'            => 'catalogue',
    $currentRoute === 'offres'                                                     => 'offers',
    str_starts_with($currentRoute, 'serveur/')                                     => 'my-servers',
    default                                                                        => '',
};

$mainNav = [
    ['id' => 'home',      'label' => 'Accueil',   'url' => '/home',                   'icon' => 'house'],
    ['id' => 'servers',   'label' => 'Serveurs',  'url' => '/serveurs',               'icon' => 'earth'],
    ['id' => 'catalogue', 'label' => 'Catalogue', 'url' => '/catalogue',              'icon' => 'blocks'],
    $currentUser !== null
        ? ['id' => 'my-servers', 'label' => 'Mes serveurs', 'url' => '/user/compte#section-serveurs', 'icon' => 'server']
        : ['id' => 'offers',     'label' => 'Offres',       'url' => '/offres',                       'icon' => 'gem'],
];

// Menu du compte (diagramme de navigation : Menu Compte, Atelier créateur, Administration, Améliorer le compte)
$userMenu = [];
if ($currentUser !== null) {
    $userMenu = [
        ['label' => 'Mon compte',            'url' => '/user/compte',                 'icon' => 'user-round'],
        ['label' => 'Mes serveurs',          'url' => '/user/compte#section-serveurs', 'icon' => 'server'],
        ['label' => 'Atelier créateur',      'url' => '/user/createur-edition',       'icon' => 'paintbrush'],
        ['label' => 'Statistiques créateur', 'url' => '/user/createur-statistiques',  'icon' => 'chart-column'],
        ['label' => 'Amis',                  'url' => '/user/compte#section-amis',    'icon' => 'users'],
        ['label' => 'Support',               'url' => '/user/compte#section-tickets', 'icon' => 'life-buoy'],
    ];
    if (Auth::isAdmin()) {
        $userMenu[] = ['label' => 'Administration', 'url' => '/admin/dashboard', 'icon' => 'shield-check', 'accent' => true];
    }
    if (($currentUser['type_compte'] ?? '') !== Auth::ACCOUNT_PREMIUM) {
        $userMenu[] = ['label' => 'Passer à Premium', 'url' => '/offres', 'icon' => 'gem', 'accent' => true];
    }
}

$userPseudo = $currentUser !== null ? (string) $currentUser['pseudo'] : '';
// Photo de profil du compte connecté (la base peut être indisponible sur une page d'erreur 503)
$userAvatar = null;
if (Auth::isLogged() && isset($db) && $db instanceof PDO) {
    try {
        $userAvatar = (new \Bootes\Src\MediaManager($db))->firstToken(\Bootes\Src\MediaManager::TARGET_USER, (int) Auth::getUserId(), \Bootes\Src\MediaManager::ROLE_AVATAR);
    } catch (PDOException) {
        $userAvatar = null;
    }
}
$userRole   = $currentUser !== null ? (string) $currentUser['role'] : '';
$userOffer  = $currentUser !== null ? (string) $currentUser['type_compte'] : '';
$userRamGo  = $currentUser !== null ? (int) floor((int) $currentUser['max_ram'] / 1024) : 0;
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= Security::e($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Boötes - Panel d'hébergement et d'administration de serveurs Minecraft auto-hébergé">
    <meta name="csrf-token" content="<?= Security::e($csrfToken) ?>">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="<?= $theme === View::THEME_LIGHT ? '#eef2f8' : '#060a14' ?>">
    <title><?= Security::e($pageTitle) ?></title>
    <link rel="icon" href="<?= Security::e(View::asset('/assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= Security::e(View::asset('/assets/img/favicon-32.png')) ?>" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= Security::e(View::asset('/assets/img/apple-touch-icon.png')) ?>">
    <link rel="preload" href="/assets/fonts/inter-latin-variable.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/fonts/archivo-latin-variable.woff2" as="font" type="font/woff2" crossorigin>
    <?php foreach ($stylesheets as $sheet): ?>
        <link rel="stylesheet" href="<?= Security::e(View::asset('/assets/css/' . $sheet . '.css')) ?>">
    <?php endforeach; ?>
    <script src="<?= Security::e(View::asset('/assets/js/script.js')) ?>" defer></script>
</head>
<body class="app">
    <a href="#contenu" class="skip-link">Aller au contenu</a>
    <header class="header" role="banner">
        <div class="header__container">
            <a href="/home" class="header__logo-link">
                <span class="brand-logo brand-logo--horizontal" aria-hidden="true"></span>
                <span class="visually-hidden">Boötes — accueil</span>
            </a>

            <nav class="header__nav" aria-label="Navigation principale">
                <ul class="header__nav-list">
                    <?php foreach ($mainNav as $item): ?>
                        <li class="header__nav-item">
                            <a href="<?= Security::e($item['url']) ?>" class="header__nav-link"<?= $activeSection === $item['id'] ? ' aria-current="page"' : '' ?>>
                                <?= View::icon($item['icon']) ?>
                                <?= Security::e($item['label']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <div class="header__actions">
                <button type="button" class="theme-toggle" data-theme-toggle aria-label="<?= Security::e($themeLabel) ?>" title="<?= Security::e($themeLabel) ?>">
                    <?= View::icon('sun', 'theme-toggle__icon--sun') ?>
                    <?= View::icon('moon', 'theme-toggle__icon--moon') ?>
                </button>

                <?php if ($currentUser !== null): ?>
                    <details class="user-menu" data-menu>
                        <summary class="user-menu__trigger" aria-label="Menu du compte <?= Security::e($userPseudo) ?>">
                            <?= View::avatar($userAvatar, $userPseudo) ?>
                            <span class="user-menu__name"><?= Security::e($userPseudo) ?></span>
                            <?= View::icon('chevron-down', 'user-menu__chevron') ?>
                        </summary>
                        <div class="user-menu__panel">
                            <div class="user-menu__identity">
                                <?= View::avatar($userAvatar, $userPseudo, 'avatar--lg') ?>
                                <div class="user-menu__identity-text">
                                    <p class="user-menu__pseudo"><?= Security::e($userPseudo) ?></p>
                                    <p class="user-menu__badges">
                                        <?= View::badge('role', $userRole) ?>
                                        <span class="badge badge--<?= Security::e(View::tone('offre', $userOffer)) ?>" title="Quota RAM : <?= (int) $currentUser['max_ram'] ?> Mo">
                                            <?= Security::e(View::label('offre', $userOffer)) ?> &middot; <?= $userRamGo ?> Go
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <ul class="user-menu__list">
                                <?php foreach ($userMenu as $item): ?>
                                    <li>
                                        <a href="<?= Security::e($item['url']) ?>" class="user-menu__item<?= !empty($item['accent']) ? ' user-menu__item--accent' : '' ?>">
                                            <?= View::icon($item['icon']) ?>
                                            <?= Security::e($item['label']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <form action="/auth" method="post" class="user-menu__logout">
                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                <input type="hidden" name="action" value="logout">
                                <button type="submit" class="user-menu__item user-menu__item--logout"><?= View::icon('log-out') ?> Déconnexion</button>
                            </form>
                        </div>
                    </details>
                <?php else: ?>
                    <a href="/auth?mode=login" class="header__btn header__btn--login">Connexion</a>
                    <a href="/auth?mode=register" class="header__btn header__btn--register">Créer un compte</a>
                <?php endif; ?>

                <details class="mobile-nav" data-menu>
                    <summary class="mobile-nav__toggle" aria-label="Menu de navigation">
                        <?= View::icon('menu', 'mobile-nav__icon--open') ?>
                        <?= View::icon('x', 'mobile-nav__icon--close') ?>
                    </summary>
                    <nav class="mobile-nav__panel" aria-label="Navigation mobile">
                        <ul class="mobile-nav__list">
                            <?php foreach ($mainNav as $item): ?>
                                <li>
                                    <a href="<?= Security::e($item['url']) ?>" class="mobile-nav__link"<?= $activeSection === $item['id'] ? ' aria-current="page"' : '' ?>>
                                        <?= View::icon($item['icon']) ?>
                                        <?= Security::e($item['label']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <?php if ($currentUser !== null): ?>
                                <li>
                                    <a href="/offres" class="mobile-nav__link"<?= $activeSection === 'offers' ? ' aria-current="page"' : '' ?>>
                                        <?= View::icon('gem') ?> Offres
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>

                        <?php if ($currentUser !== null): ?>
                            <div class="mobile-nav__section">
                                <div class="user-menu__identity">
                                    <?= View::avatar($userAvatar, $userPseudo, 'avatar--lg') ?>
                                    <div class="user-menu__identity-text">
                                        <p class="user-menu__pseudo"><?= Security::e($userPseudo) ?></p>
                                        <p class="user-menu__badges">
                                            <?= View::badge('role', $userRole) ?>
                                            <?= View::badge('offre', $userOffer) ?>
                                        </p>
                                    </div>
                                </div>
                                <ul class="mobile-nav__list">
                                    <?php foreach ($userMenu as $item): ?>
                                        <li>
                                            <a href="<?= Security::e($item['url']) ?>" class="mobile-nav__link<?= !empty($item['accent']) ? ' mobile-nav__link--accent' : '' ?>">
                                                <?= View::icon($item['icon']) ?>
                                                <?= Security::e($item['label']) ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <form action="/auth" method="post" class="user-menu__logout">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="logout">
                                    <button type="submit" class="mobile-nav__link mobile-nav__link--logout"><?= View::icon('log-out') ?> Déconnexion</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="mobile-nav__section mobile-nav__section--guest">
                                <a href="/auth?mode=login" class="header__btn header__btn--login">Connexion</a>
                                <a href="/auth?mode=register" class="header__btn header__btn--register">Créer un compte</a>
                            </div>
                        <?php endif; ?>
                    </nav>
                </details>
            </div>
        </div>
    </header>

    <div class="layout<?= $layoutContext !== null ? ' layout--with-sidebar' : '' ?>">
