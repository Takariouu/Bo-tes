<?php

declare(strict_types=1);

$currentPage = isset($_GET['page']) ? (string) $_GET['page'] : 'dashboard';
$activeServerId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
?>
<aside class="app-sidebar">
    <div>
        <div class="sidebar-section-title">Navigation</div>
        <ul class="sidebar-menu">
            <li>
                <a href="/home" class="sidebar-link <?= $currentPage === 'home' ? 'active' : '' ?>">
                    <span>Accueil</span>
                </a>
            </li>
            <li>
                <a href="/dashboard" class="sidebar-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <span>Mes Serveurs</span>
                </a>
            </li>
            <li>
                <a href="/dashboard#create-server" class="sidebar-link">
                    <span>+ Créer un serveur</span>
                </a>
            </li>
        </ul>

        <?php if ($activeServerId > 0): ?>
            <div class="sidebar-section-title">Instance #<?= $activeServerId ?></div>
            <ul class="sidebar-menu">
                <li>
                    <a href="/server-control?id=<?= $activeServerId ?>" class="sidebar-link <?= $currentPage === 'server-control' ? 'active' : '' ?>">
                        <span>Panneau de contrôle</span>
                    </a>
                </li>
                <li>
                    <a href="/server-console?id=<?= $activeServerId ?>" class="sidebar-link <?= $currentPage === 'server-console' ? 'active' : '' ?>">
                        <span>Console & Logs</span>
                    </a>
                </li>
                <li>
                    <a href="/server-mods?id=<?= $activeServerId ?>" class="sidebar-link <?= $currentPage === 'server-mods' ? 'active' : '' ?>">
                        <span>Fichiers & Mods</span>
                    </a>
                </li>
                <li>
                    <a href="/server-backups?id=<?= $activeServerId ?>" class="sidebar-link <?= $currentPage === 'server-backups' ? 'active' : '' ?>">
                        <span>Backups (.tar.gz)</span>
                    </a>
                </li>
            </ul>
        <?php endif; ?>

        <?php if (Auth::isAdmin()): ?>
            <div class="sidebar-section-title">Administration</div>
            <ul class="sidebar-menu">
                <li>
                    <a href="/admin-dashboard" class="sidebar-link <?= $currentPage === 'admin-dashboard' ? 'active' : '' ?>">
                        <span>Machine Hôte (8 Go)</span>
                    </a>
                </li>
            </ul>
        <?php endif; ?>
    </div>

    <div style="border-top: 1px solid var(--border-subtle); padding-top: 1rem;">
        <a href="/auth?action=logout" class="sidebar-link danger-link">
            <span>Déconnexion</span>
        </a>
    </div>
</aside>