<?php

declare(strict_types=1);

use Bootes\Src\Auth;
use Bootes\Src\Security;

$currentRoute  = isset($currentRoute) && is_string($currentRoute) ? $currentRoute : 'home';
$activeServerId = isset($activeServerId) && is_int($activeServerId) ? $activeServerId : Security::validatePositiveInt($_GET['id'] ?? $_GET['server_id'] ?? null);

$isAdminContext  = str_starts_with($currentRoute, 'admin/') && Auth::isAdmin();
$isServerContext = str_starts_with($currentRoute, 'serveur/') && $activeServerId !== null && Auth::isLogged();
?>
<aside class="sidebar" aria-label="Menu contextuel latéral">
    <div class="sidebar__inner">
        <?php if ($isServerContext && $activeServerId !== null): ?>
            <section class="sidebar__section sidebar__section--server">
                <header class="sidebar__header">
                    <h2 class="sidebar__title">Serveur #<?= (int) $activeServerId ?></h2>
                    <p class="sidebar__subtitle">Gestion de l'instance</p>
                </header>
                <nav class="sidebar__nav" aria-label="Navigation du serveur Minecraft">
                    <ul class="sidebar__list">
                        <li class="sidebar__item">
                            <a href="/serveur/dashboard?id=<?= (int) $activeServerId ?>"
                               class="sidebar__link <?= $currentRoute === 'serveur/dashboard' ? 'sidebar__link--active' : '' ?>">
                                Dashboard
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/serveur/console?id=<?= (int) $activeServerId ?>"
                               class="sidebar__link <?= $currentRoute === 'serveur/console' ? 'sidebar__link--active' : '' ?>">
                                Console Temps Réel
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/serveur/logs?id=<?= (int) $activeServerId ?>"
                               class="sidebar__link <?= $currentRoute === 'serveur/logs' ? 'sidebar__link--active' : '' ?>">
                                Journaux (Logs)
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/serveur/map?id=<?= (int) $activeServerId ?>"
                               class="sidebar__link <?= $currentRoute === 'serveur/map' ? 'sidebar__link--active' : '' ?>">
                                Map 3D
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/serveur/backups?id=<?= (int) $activeServerId ?>"
                               class="sidebar__link <?= $currentRoute === 'serveur/backups' ? 'sidebar__link--active' : '' ?>">
                                Sauvegardes (Backups)
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/serveur/joueurs?id=<?= (int) $activeServerId ?>"
                               class="sidebar__link <?= $currentRoute === 'serveur/joueurs' ? 'sidebar__link--active' : '' ?>">
                                Joueurs &amp; Whitelist
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/serveur/acces-admin?id=<?= (int) $activeServerId ?>"
                               class="sidebar__link <?= $currentRoute === 'serveur/acces-admin' ? 'sidebar__link--active' : '' ?>">
                                Accès &amp; Co-Admins
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/serveur/statistiques?id=<?= (int) $activeServerId ?>"
                               class="sidebar__link <?= $currentRoute === 'serveur/statistiques' ? 'sidebar__link--active' : '' ?>">
                                Statistiques Serveur
                            </a>
                        </li>
                    </ul>
                </nav>
            </section>

        <?php elseif ($isAdminContext): ?>
            <section class="sidebar__section sidebar__section--admin">
                <header class="sidebar__header">
                    <h2 class="sidebar__title">Supervision Boötes</h2>
                    <p class="sidebar__subtitle">Hôte Linux Mint (6 Go MC / 8 Go)</p>
                </header>
                <nav class="sidebar__nav" aria-label="Navigation d'administration globale">
                    <ul class="sidebar__list">
                        <li class="sidebar__item">
                            <a href="/admin/dashboard"
                               class="sidebar__link <?= $currentRoute === 'admin/dashboard' ? 'sidebar__link--active' : '' ?>">
                                Vue d'ensemble
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/admin/hardware"
                               class="sidebar__link <?= $currentRoute === 'admin/hardware' ? 'sidebar__link--active' : '' ?>">
                                Supervision Hardware (RAM/CPU)
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/admin/utilisateurs"
                               class="sidebar__link <?= $currentRoute === 'admin/utilisateurs' ? 'sidebar__link--active' : '' ?>">
                                Gestion des Utilisateurs
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/admin/signalements"
                               class="sidebar__link <?= $currentRoute === 'admin/signalements' ? 'sidebar__link--active' : '' ?>">
                                Modération &amp; Signalements
                            </a>
                        </li>
                        <?php if (Auth::isSuperAdmin()): ?>
                            <li class="sidebar__item sidebar__item--super-admin">
                                <a href="/admin/super-admin"
                                   class="sidebar__link <?= $currentRoute === 'admin/super-admin' ? 'sidebar__link--active' : '' ?>">
                                    Super-Admin &amp; Logs Système
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </section>

        <?php else: ?>
            <section class="sidebar__section sidebar__section--public">
                <header class="sidebar__header">
                    <h2 class="sidebar__title">Navigation</h2>
                    <p class="sidebar__subtitle">Explorer Boötes</p>
                </header>
                <nav class="sidebar__nav" aria-label="Navigation publique et utilisateur">
                    <ul class="sidebar__list">
                        <li class="sidebar__item">
                            <a href="/home"
                               class="sidebar__link <?= $currentRoute === 'home' ? 'sidebar__link--active' : '' ?>">
                                Accueil
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/catalogue"
                               class="sidebar__link <?= $currentRoute === 'catalogue' ? 'sidebar__link--active' : '' ?>">
                                Catalogue de Ressources
                            </a>
                        </li>
                        <li class="sidebar__item">
                            <a href="/serveur-public"
                               class="sidebar__link <?= $currentRoute === 'serveur-public' ? 'sidebar__link--active' : '' ?>">
                                Serveurs Publics
                            </a>
                        </li>
                        <?php if (Auth::isLogged()): ?>
                            <li class="sidebar__item">
                                <a href="/user/compte"
                                   class="sidebar__link <?= $currentRoute === 'user/compte' ? 'sidebar__link--active' : '' ?>">
                                    Mon Compte &amp; Mes Serveurs
                                </a>
                            </li>
                            <li class="sidebar__item">
                                <a href="/user/createur-edition"
                                   class="sidebar__link <?= $currentRoute === 'user/createur-edition' ? 'sidebar__link--active' : '' ?>">
                                    Publier une Ressource
                                </a>
                            </li>
                            <li class="sidebar__item">
                                <a href="/user/createur-statistiques"
                                   class="sidebar__link <?= $currentRoute === 'user/createur-statistiques' ? 'sidebar__link--active' : '' ?>">
                                    Statistiques Créateur
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="sidebar__item">
                                <a href="/auth"
                                   class="sidebar__link <?= $currentRoute === 'auth' ? 'sidebar__link--active' : '' ?>">
                                    Connexion / Inscription
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="sidebar__item">
                            <a href="/cgu"
                               class="sidebar__link <?= $currentRoute === 'cgu' ? 'sidebar__link--active' : '' ?>">
                                Conditions Générales (CGU)
                            </a>
                        </li>
                    </ul>
                </nav>
            </section>
        <?php endif; ?>
    </div>
</aside>