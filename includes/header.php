<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/Security.php';

Auth::initSession();

$currentPage = isset($_GET['page']) ? Security::sanitizeString((string) $_GET['page']) : 'home';
$isLogged    = Auth::isLogged();
$isAdmin     = Auth::isAdmin();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Boötes — Hébergeur de serveurs Minecraft Fabric haute performance.">
    <title>BOÖTES — Transformer le vide en lieu de vie</title>

    <!-- Google Fonts : Syncopate, Jost & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Jost:wght@300;400;500;600;700&family=Syncopate:wght@700&display=swap" rel="stylesheet">

    <!-- Stylesheet principal -->
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

    <!-- Conteneur d'injection pour Notification.js -->
    <div id="bootes-notifications" class="notification-container" aria-live="polite"></div>

    <!-- Barre de navigation supérieure -->
    <header class="top-navbar">
        <a href="/home" class="brand-logo">
            <span class="logo-star"></span>
            <span>BOÖTES</span>
        </a>

        <nav class="nav-links">
            <a href="/home" class="<?= $currentPage === 'home' ? 'active' : '' ?>">Accueil</a>
            <?php if ($isLogged): ?>
                <a href="/dashboard" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">Serveurs</a>
                <?php if ($isAdmin): ?>
                    <a href="/admin-dashboard" class="<?= $currentPage === 'admin-dashboard' ? 'active' : '' ?>">Supervision</a>
                <?php endif; ?>
            <?php else: ?>
                <a href="/home#presentation">Présentation</a>
                <a href="/home#serveurs">Serveurs</a>
                <a href="/home#modpacks">ModPacks</a>
            <?php endif; ?>
        </nav>

        <div>
            <?php if ($isLogged): ?>
                <span class="mono" style="font-size: 0.85rem; color: var(--text-secondary); margin-right: 1rem;">
                    <?= Security::sanitizeString((string) ($_SESSION['username'] ?? ' Pilote')) ?>
                </span>
                <a href="/auth?action=logout" class="btn btn-stop" style="padding: 0.45rem 1rem; font-size: 0.8rem;">Déconnexion</a>
            <?php else: ?>
                <a href="/auth" class="btn btn-start">Connexion</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="app-shell">