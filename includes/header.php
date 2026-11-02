<?php

declare(strict_types=1);

use Bootes\Src\Auth;
use Bootes\Src\Security;

Auth::initSession();

$pageTitle   = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Boötes — Hébergement de serveurs Minecraft';
$currentUser = $_SESSION['user'] ?? null;
$csrfToken   = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Boötes - Panel d'hébergement et d'administration de serveurs Minecraft auto-hébergé">
    <meta name="csrf-token" content="<?= Security::e($csrfToken) ?>">
    <title><?= Security::e($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/css/variables.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/layout.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <link rel="stylesheet" href="/assets/css/terminal.css">
    <script src="/assets/js/script.js" defer></script>
</head>
<body class="app">
    <header class="header" role="banner">
        <div class="header__container">
            <div class="header__brand">
                <a href="/home" class="header__logo-link" aria-label="Retour à l'accueil Boötes">
                    <span class="header__logo-title">Boötes</span>
                    <span class="header__logo-badge">Panel MC</span>
                </a>
            </div>

            <nav class="header__nav" aria-label="Navigation principale">
                <ul class="header__nav-list">
                    <li class="header__nav-item">
                        <a href="/home" class="header__nav-link">Accueil</a>
                    </li>
                    <li class="header__nav-item">
                        <a href="/catalogue" class="header__nav-link">Catalogue</a>
                    </li>
                    <?php if (Auth::isLogged()): ?>
                        <li class="header__nav-item">
                            <a href="/user/compte" class="header__nav-link">Mon Espace</a>
                        </li>
                        <li class="header__nav-item">
                            <a href="/user/createur-edition" class="header__nav-link">Atelier Créateur</a>
                        </li>
                    <?php endif; ?>
                    <?php if (Auth::isAdmin()): ?>
                        <li class="header__nav-item">
                            <a href="/admin/dashboard" class="header__nav-link header__nav-link--admin">Administration</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>

            <div class="header__user-actions">
                <?php if (Auth::isLogged() && is_array($currentUser)): ?>
                    <div class="header__profile">
                        <span class="header__profile-name"><?= Security::e((string) $currentUser['pseudo']) ?></span>
                        <span class="header__profile-tier" title="Quota RAM maximal : <?= (int) $currentUser['max_ram'] ?> Mo">
                            <?= Security::e((string) $currentUser['type_compte']) ?> (<?= (int) ($currentUser['max_ram'] / 1024) ?> Go max)
                        </span>
                        <span class="header__profile-role"><?= Security::e((string) $currentUser['role']) ?></span>
                    </div>
                    <form action="/auth" method="post" class="header__logout-form">
                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="header__btn header__btn--logout">Déconnexion</button>
                    </form>
                <?php else: ?>
                    <div class="header__guest">
                        <a href="/auth?mode=login" class="header__btn header__btn--login">Connexion</a>
                        <a href="/auth?mode=register" class="header__btn header__btn--register">Inscription</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="layout">