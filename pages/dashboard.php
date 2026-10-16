<?php

declare(strict_types=1);

$db     = Database::getInstance();
$userId = (string) Auth::getCurrentUserId();

// Initialisation des référentiels de base s'ils sont vides (ETAT, MODE_DE_JEU, TYPE_DE_VERSION, VERSION)
$db->exec("INSERT IGNORE INTO ETAT (ETA_ID, ETA_LIBELLE, ETA_DESCRIPTION) VALUES (1, 'Éteint', 'Hors ligne'), (2, 'Allumé', 'En ligne'), (3, 'Démarrage', 'Initialisation'), (4, 'Crash', 'Erreur processus')");
$db->exec("INSERT IGNORE INTO MODE_DE_JEU (MDJ_ID, MDJ_LIBELLE, MDJ_DESCRIPTION) VALUES (1, 'Survie', 'Mode survie standard'), (2, 'Créatif', 'Construction libre'), (3, 'Aventure', 'Cartes scénarisées')");
$db->exec("INSERT IGNORE INTO TYPE_DE_VERSION (TDV_ID, TDV_LIBELLE, TDV_DESCRIPTION) VALUES (1, 'Fabric Optimisé', 'Vanilla sous Fabric avec Lithium et FerriteCore')");
$db->exec("INSERT IGNORE INTO VERSION (VER_ID, TDV_ID, VER_LIBELLE) VALUES (1, 1, '1.20.4'), (2, 1, '1.20.1'), (3, 1, '1.19.4')");

$maxUserRamMo = Auth::isPremium()
    ? MinecraftServerManager::MAX_PREMIUM_RAM_MO
    : MinecraftServerManager::MAX_STANDARD_RAM_MO;

// Traitement de la création d'un nouveau serveur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_server') {
    $csrf = $_POST['csrf_token'] ?? null;

    if (!Security::verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        addFlashNotification('error', 'Jeton CSRF invalide.');
    } else {
        $serverName = trim((string) ($_POST['server_name'] ?? ''));
        $ramMo      = (int) ($_POST['server_ram'] ?? 1024);
        $maxPlayers = (int) ($_POST['max_players'] ?? 10);
        $versionId  = (int) ($_POST['version_id'] ?? 1);
        $modeId     = (int) ($_POST['mode_id'] ?? 1);
        $desc       = trim((string) ($_POST['server_desc'] ?? 'Serveur Fabric Boötes'));

        if (!Security::validateAlphanumericDash($serverName, 3, 32)) {
            addFlashNotification('error', 'Nom invalide : utilisez 3 à 32 caractères alphanumériques, tirets ou underscores.');
        } elseif ($ramMo < MinecraftServerManager::MIN_SERVER_RAM_MO || $ramMo > $maxUserRamMo) {
            addFlashNotification('error', sprintf('Allocation RAM non autorisée (min 512 Mo, max %d Mo pour votre compte).', $maxUserRamMo));
        } else {
            // Recherche du premier port libre entre 25565 et 25585
            $usedPortsStmt = $db->query('SELECT SVR_PORT FROM SERVEUR');
            $usedPorts     = array_map('intval', $usedPortsStmt->fetchAll(PDO::FETCH_COLUMN));

            $assignedPort = null;
            for ($port = Security::MIN_MC_PORT; $port <= Security::MAX_MC_PORT; $port++) {
                if (!in_array($port, $usedPorts, true)) {
                    $assignedPort = $port;
                    break;
                }
            }

            if ($assignedPort === null || !Security::validateMinecraftPort($assignedPort)) {
                addFlashNotification('error', 'Aucun port réseau disponible sur la plage 25565-25585.');
            } else {
                // Génération des identifiants compatibles avec ou sans AUTO_INCREMENT
                $nextCmapId = (int) $db->query('SELECT COALESCE(MAX(CMAP_ID), 0) + 1 FROM CONFIGURATION_MAP')->fetchColumn();
                $nextMopaId = (int) $db->query('SELECT COALESCE(MAX(MOPA_ID), 0) + 1 FROM MOD_PACK')->fetchColumn();
                $nextSvrId  = (int) $db->query('SELECT COALESCE(MAX(SVR_ID), 0) + 1 FROM SERVEUR')->fetchColumn();

                // 1. Création de la configuration Map par défaut
                $cmapStmt = $db->prepare('INSERT INTO CONFIGURATION_MAP (CMAP_ID, CMAP_AFFICHER_JOUEURS, CMAP_2D) VALUES (:id, 1, 0)');
                $cmapStmt->execute([':id' => $nextCmapId]);

                // 2. Création du profil ModPack privé associé au serveur
                $mopaStmt = $db->prepare(
                    'INSERT INTO MOD_PACK (
                        MOPA_ID, VER_ID, IMG_ID, UTI_ID, MOPA_NOM, MOPA_DATE_PUBLICATION, MOPA_CHEMIN_DOSSIER, MOPA_EST_PUBLIC, MOPA_NB_DOWNLOAD, MOPA_DESCRIPTION
                    ) VALUES (
                        :id, :ver_id, NULL, :uti_id, :nom, NOW(), :chemin, 0, 0, :descr
                    )'
                );
                $mopaStmt->execute([
                    ':id'     => $nextMopaId,
                    ':ver_id' => $versionId,
                    ':uti_id' => $userId,
                    ':nom'    => substr('Pack_' . $serverName, 0, 32),
                    ':chemin' => 'srv_' . $nextSvrId,
                    ':descr'  => 'Profil Fabric privé généré automatiquement.',
                ]);

                // 3. Création du serveur dans la table SERVEUR
                $hostIp = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
                $svrStmt = $db->prepare(
                    'INSERT INTO SERVEUR (
                        SVR_ID, ETA_ID, MOPA_ID, CMAP_ID, UTI_ID, VER_ID, IMG_ID, MDJ_ID,
                        SVR_NOM, SVR_ADDRESSE, SVR_PORT, SVR_DATE_CREATION, SVR_RAM_ALLOUEE_MO,
                        SVR_JOUEURS_MAX, SVR_DECRIPTION, SVR_NB_SECONDES_OUVERT
                    ) VALUES (
                        :svr_id, :eta_id, :mopa_id, :cmap_id, :uti_id, :ver_id, NULL, :mdj_id,
                        :nom, :addr, :port, CURDATE(), :ram, :slots, :descr, 0
                    )'
                );
                $svrStmt->execute([
                    ':svr_id'  => $nextSvrId,
                    ':eta_id'  => MinecraftServerManager::STATE_STOPPED,
                    ':mopa_id' => $nextMopaId,
                    ':cmap_id' => $nextCmapId,
                    ':uti_id'  => $userId,
                    ':ver_id'  => $versionId,
                    ':mdj_id'  => $modeId,
                    ':nom'     => $serverName,
                    ':addr'    => $hostIp,
                    ':port'    => $assignedPort,
                    ':ram'     => $ramMo,
                    ':slots'   => max(2, min($maxPlayers, 99)),
                    ':descr'   => substr($desc, 0, 1000),
                ]);

                // Préparation de l'arborescence physique du serveur sur Linux
                $serverDir = MinecraftServerManager::getServerDirectory($nextSvrId);
                @mkdir($serverDir . '/mods', 0750, true);
                @mkdir($serverDir . '/logs', 0750, true);
                @mkdir($serverDir . '/world', 0750, true);
                @file_put_contents($serverDir . '/eula.txt', "eula=true\n");
                @file_put_contents(
                    $serverDir . '/server.properties',
                    sprintf("server-port=%d\nmax-players=%d\nmotd=%s\n", $assignedPort, $maxPlayers, $serverName)
                );

                addFlashNotification('success', sprintf('Serveur "%s" créé sur le port %d !', $serverName, $assignedPort));
                header('Location: /server-control?id=' . $nextSvrId);
                exit;
            }
        }
    }
}

// Récupération des serveurs possédés ou co-administrés par l'utilisateur connecté
$serversStmt = $db->prepare(
    'SELECT DISTINCT s.*, v.VER_LIBELLE, e.ETA_LIBELLE, m.MDJ_LIBELLE
     FROM SERVEUR s
     LEFT JOIN ADMINISTRER_SERVEUR a ON s.SVR_ID = a.SVR_ID
     LEFT JOIN VERSION v ON s.VER_ID = v.VER_ID
     LEFT JOIN ETAT e ON s.ETA_ID = e.ETA_ID
     LEFT JOIN MODE_DE_JEU m ON s.MDJ_ID = m.MDJ_ID
     WHERE s.UTI_ID = :owner_id OR a.UTI_ID = :co_admin_id
     ORDER BY s.SVR_ID DESC'
);
$serversStmt->execute([
    ':owner_id'    => $userId,
    ':co_admin_id' => $userId,
]);
$userServers = $serversStmt->fetchAll();

$versionsList  = $db->query('SELECT VER_ID, VER_LIBELLE FROM VERSION ORDER BY VER_ID ASC')->fetchAll();
$gameModesList = $db->query('SELECT MDJ_ID, MDJ_LIBELLE FROM MODE_DE_JEU ORDER BY MDJ_ID ASC')->fetchAll();
$globalUsedRam = MinecraftServerManager::getCurrentAllocatedRamMo($db);
$csrfToken     = Security::generateCsrfToken();

require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<main class="app-main">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.4rem;">MES SERVEURS MINECRAFT</h1>
            <p style="color: var(--text-secondary); font-size: 0.95rem;">
                Gérez vos instances Fabric et surveillez vos ressources mémoire.
            </p>
        </div>
        <a href="#create-server" class="btn btn-start">+ Créer un serveur</a>
    </div>

    <!-- Jauge globale de santé de la machine hôte (6 Go allouables sur 8 Go) -->
    <div class="bento-card" style="margin-bottom: 2rem;">
        <div class="card-header" style="margin-bottom: 0.5rem;">
            <span class="card-title">CHARGE GLOBALE DES SERVEURS BOÖTES (CAPACITÉ : 6144 MO)</span>
            <span class="mono" style="color: var(--accent-cyan); font-size: 0.9rem;">
                <?= $globalUsedRam ?> Mo / <?= MinecraftServerManager::MAX_GLOBAL_RAM_MO ?> Mo actifs
            </span>
        </div>
        <div class="ram-progress-bar">
            <?php $globalPercent = min(100, round(($globalUsedRam / MinecraftServerManager::MAX_GLOBAL_RAM_MO) * 100)); ?>
            <div class="ram-progress-fill" style="width: <?= $globalPercent ?>%;"></div>
        </div>
    </div>

    <!-- GRILLE BENTO DES SERVEURS DE L'UTILISATEUR -->
    <div class="bento-grid">
        <?php if (empty($userServers)): ?>
            <div class="bento-col-12 bento-card" style="text-align: center; padding: 3rem;">
                <h3 class="card-title" style="margin-bottom: 0.75rem;">Aucun serveur actif dans votre flotte</h3>
                <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
                    Utilisez le formulaire ci-dessous pour déployer votre premier serveur Minecraft Fabric optimisé.
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($userServers as $srv): ?>
                <?php
                $srvId      = (int) $srv['SVR_ID'];
                $isOnline   = ((int) $srv['ETA_ID'] === MinecraftServerManager::STATE_RUNNING);
                $ramMo      = (int) $srv['SVR_RAM_ALLOUEE_MO'];
                $ramPercent = min(100, round(($ramMo / $maxUserRamMo) * 100));
                ?>
                <div class="bento-col-6 bento-card <?= $isOnline ? 'card-cyan-accent' : '' ?>">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title" style="font-size: 1.05rem;">
                                <?= Security::sanitizeString((string) $srv['SVR_NOM']) ?>
                            </h3>
                            <span class="mono" style="font-size: 0.8rem; color: var(--text-secondary);">
                                <?= Security::sanitizeString((string) $srv['SVR_ADDRESSE']) ?>:<?= (int) $srv['SVR_PORT'] ?>
                            </span>
                        </div>
                        <?php if ($isOnline): ?>
                            <span class="badge badge-online">● En ligne</span>
                        <?php else: ?>
                            <span class="badge badge-offline">○ Éteint</span>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; gap: 1.5rem; margin-bottom: 1rem; font-size: 0.9rem; color: var(--text-secondary);">
                        <div>Version : <strong style="color: var(--text-primary);"><?= Security::sanitizeString((string) ($srv['VER_LIBELLE'] ?? '1.20.4')) ?></strong></div>
                        <div>Mode : <strong style="color: var(--text-primary);"><?= Security::sanitizeString((string) ($srv['MDJ_LIBELLE'] ?? 'Survie')) ?></strong></div>
                        <div>Slots : <strong style="color: var(--text-primary);"><?= (int) $srv['SVR_JOUEURS_MAX'] ?></strong></div>
                    </div>

                    <!-- Jauge de RAM allouée -->
                    <div style="margin-bottom: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.8rem;" class="mono">
                            <span>RAM allouée à l'instance</span>
                            <span style="color: var(--accent-cyan);"><?= $ramMo ?> Mo / <?= $maxUserRamMo ?> Mo max</span>
                        </div>
                        <div class="ram-progress-bar">
                            <div class="ram-progress-fill" style="width: <?= $ramPercent ?>%;"></div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem;">
                        <a href="/server-control?id=<?= $srvId ?>" class="btn btn-start" style="flex: 1;">
                            Panneau de contrôle
                        </a>
                        <a href="/server-console?id=<?= $srvId ?>" class="btn btn-cyan">
                            Console
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- FORMULAIRE DE CRÉATION D'UN SERVEUR -->
    <section id="create-server" style="margin-top: 3.5rem;">
        <div class="bento-card card-orange-accent">
            <h2 class="card-title" style="font-size: 1rem; margin-bottom: 1.25rem; color: var(--accent-orange);">
                + DÉPLOYER UNE NOUVELLE INSTANCE FABRIC
            </h2>

            <form method="POST" action="/dashboard">
                <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">
                <input type="hidden" name="action" value="create_server">

                <div class="bento-grid" style="margin-top: 0;">
                    <div class="bento-col-6 form-group">
                        <label class="form-label" for="server_name">Nom du serveur (alphanumérique, tirets)</label>
                        <input type="text" id="server_name" name="server_name" class="form-control" required maxlength="32" pattern="[a-zA-Z0-9_-]{3,32}" placeholder="MonServeur_Survie">
                    </div>

                    <div class="bento-col-6 form-group">
                        <label class="form-label" for="server_ram">Mémoire RAM allouée (Plafond compte : <?= $maxUserRamMo ?> Mo)</label>
                        <select id="server_ram" name="server_ram" class="form-control">
                            <option value="1024">1024 Mo (1,0 Go — Recommandé petit comité)</option>
                            <option value="1536">1536 Mo (1,5 Go — Fluide)</option>
                            <option value="2048" selected>2048 Mo (2,0 Go — Maximum Standard)</option>
                            <?php if (Auth::isPremium()): ?>
                                <option value="3072">3072 Mo (3,0 Go — Offre Premium)</option>
                                <option value="4096">4096 Mo (4,0 Go — Offre Premium ModPack lourd)</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="bento-col-4 form-group">
                        <label class="form-label" for="version_id">Version Minecraft (Fabric)</label>
                        <select id="version_id" name="version_id" class="form-control">
                            <?php foreach ($versionsList as $ver): ?>
                                <option value="<?= (int) $ver['VER_ID'] ?>">
                                    Fabric <?= Security::sanitizeString((string) $ver['VER_LIBELLE']) ?> (Optimisé)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="bento-col-4 form-group">
                        <label class="form-label" for="mode_id">Mode de jeu</label>
                        <select id="mode_id" name="mode_id" class="form-control">
                            <?php foreach ($gameModesList as $mdj): ?>
                                <option value="<?= (int) $mdj['MDJ_ID'] ?>">
                                    <?= Security::sanitizeString((string) $mdj['MDJ_LIBELLE']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="bento-col-4 form-group">
                        <label class="form-label" for="max_players">Joueurs maximum (Slots)</label>
                        <input type="number" id="max_players" name="max_players" class="form-control" value="10" min="2" max="50" required>
                    </div>

                    <div class="bento-col-12 form-group">
                        <label class="form-label" for="server_desc">Description du serveur</label>
                        <input type="text" id="server_desc" name="server_desc" class="form-control" maxlength="255" placeholder="Serveur survie entre amis avec Lithium et FerriteCore.">
                    </div>
                </div>

                <button type="submit" class="btn btn-start">
                    Créer et configurer le serveur
                </button>
            </form>
        </div>
    </section>
</main>