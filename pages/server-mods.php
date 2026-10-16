<?php

declare(strict_types=1);

$db       = Database::getInstance();
$userId   = (string) Auth::getCurrentUserId();
$serverId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($serverId <= 0 || !Auth::hasServerAccess($userId, $serverId, $db)) {
    addFlashNotification('error', 'Accès refusé à la gestion des mods de ce serveur.');
    header('Location: /dashboard');
    exit;
}

// Récupération des informations du serveur
$srvStmt = $db->prepare('SELECT SVR_ID, SVR_NOM, VER_ID, MOPA_ID FROM SERVEUR WHERE SVR_ID = :id LIMIT 1');
$srvStmt->execute([':id' => $serverId]);
$server = $srvStmt->fetch();

if (!$server) {
    addFlashNotification('error', 'Serveur introuvable.');
    header('Location: /dashboard');
    exit;
}

$modsRepoDir   = rtrim(getenv('BOOTES_MODS_PATH') ?: dirname(__DIR__) . '/data/mods', '/');
$serverModsDir = MinecraftServerManager::getServerDirectory($serverId) . '/mods';

if (!is_dir($serverModsDir)) {
    @mkdir($serverModsDir, 0750, true);
}

// Auto-initialisation du catalogue de mods Fabric d'optimisation s'il est vide
$modCount = (int) $db->query('SELECT COUNT(*) FROM `MOD`')->fetchColumn();
if ($modCount === 0) {
    $defaultMods = [
        [
            'id'     => 1,
            'nom'    => 'Lithium',
            'folder' => 'lithium',
            'file'   => 'lithium-fabric-mc1.20.4-0.12.1.jar',
            'taille' => 1,
            'desc'   => 'Optimisation majeure de la physique, de l\'IA des monstres et du tickrate serveur sous Fabric.',
        ],
        [
            'id'     => 2,
            'nom'    => 'FerriteCore',
            'folder' => 'ferritecore',
            'file'   => 'ferritecore-6.0.3-fabric.jar',
            'taille' => 1,
            'desc'   => 'Réduit de 40% l\'empreinte mémoire RAM des blocs et modèles (indispensable sur machine 8 Go).',
        ],
        [
            'id'     => 3,
            'nom'    => 'Krypton',
            'folder' => 'krypton',
            'file'   => 'krypton-0.2.6.jar',
            'taille' => 1,
            'desc'   => 'Optimise la pile réseau Netty de Minecraft pour réduire l\'usage CPU et la latence des joueurs.',
        ],
        [
            'id'     => 4,
            'nom'    => 'Spark Profiler',
            'folder' => 'spark',
            'file'   => 'spark-1.10.53-fabric.jar',
            'taille' => 4,
            'desc'   => 'Outil d\'analyse des performances TPS, CPU et mémoire vive en temps réel.',
        ],
    ];

    $insMod = $db->prepare(
        'INSERT IGNORE INTO `MOD` (MOD_ID, UTI_ID, IMG_ID, MOD_NOM, MOD_DATE_PUBLICATION, MOD_CHEMIN_DOSSIER, MOD_DESCRIPTION)
         VALUES (:id, :uti, NULL, :nom, NOW(), :folder, :descr)'
    );
    $insFile = $db->prepare(
        'INSERT IGNORE INTO FICHIER_MOD (FMOD_ID, MOD_ID, VER_ID, FMOD_NOM_FICHIER, FMOD_TAILLE_MO, FMOD_DATE_PUBLICATION, FMOD_NB_DOWNLOAD, FMOD_DESCRIPTION)
         VALUES (:fmod_id, :mod_id, :ver_id, :fname, :taille, NOW(), 0, :descr)'
    );

    foreach ($defaultMods as $dm) {
        $insMod->execute([
            ':id'     => $dm['id'],
            ':uti'    => $userId,
            ':nom'    => $dm['nom'],
            ':folder' => $dm['folder'],
            ':descr'  => $dm['desc'],
        ]);
        $insFile->execute([
            ':fmod_id' => $dm['id'],
            ':mod_id'  => $dm['id'],
            ':ver_id'  => (int) $server['VER_ID'],
            ':fname'   => $dm['file'],
            ':taille'  => $dm['taille'],
            ':descr'   => 'Archive Fabric officielle',
        ]);

        // Création du fichier .jar source dans /data/mods/{folder}/ s'il n'existe pas encore
        $targetRepoFolder = $modsRepoDir . '/' . $dm['folder'];
        if (!is_dir($targetRepoFolder)) {
            @mkdir($targetRepoFolder, 0750, true);
        }
        $jarPath = $targetRepoFolder . '/' . $dm['file'];
        if (!is_file($jarPath)) {
            @file_put_contents($jarPath, 'Fabric Mod Binary Placeholder - ' . $dm['nom']);
        }
    }
}

// Traitement POST : Installation ou Désinstallation d'un mod par lien symbolique
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;

    if (!Security::verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        addFlashNotification('error', 'Jeton CSRF invalide.');
    } else {
        $modAction = isset($_POST['mod_action']) ? (string) $_POST['mod_action'] : '';

        if ($modAction === 'install') {
            $modId = isset($_POST['mod_id']) ? (int) $_POST['mod_id'] : 0;

            if ($modId > 0 && MinecraftServerManager::installMod($serverId, $modId, $db)) {
                addFlashNotification('success', 'Mod installé avec succès via lien symbolique (0 Mo dupliqué).');
            } else {
                addFlashNotification('error', 'Impossible de créer le lien symbolique pour ce mod.');
            }
        } elseif ($modAction === 'uninstall') {
            $jarFile = isset($_POST['jar_file']) ? basename(trim((string) $_POST['jar_file'])) : '';
            $linkPath = $serverModsDir . '/' . $jarFile;

            if ($jarFile !== '' && (is_link($linkPath) || is_file($linkPath))) {
                @unlink($linkPath);
                addFlashNotification('info', sprintf('Le mod "%s" a été retiré du serveur.', $jarFile));
            }
        }

        header('Location: /server-mods?id=' . $serverId);
        exit;
    }
}

// Lecture des mods actuellement présents dans /servers/{id}/mods/
$installedFiles = [];
if (is_dir($serverModsDir)) {
    $scan = scandir($serverModsDir);
    if (is_array($scan)) {
        foreach ($scan as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $serverModsDir . '/' . $entry;
            if (str_ends_with(strtolower($entry), '.jar')) {
                $installedFiles[$entry] = [
                    'name'       => $entry,
                    'is_symlink' => is_link($fullPath),
                    'target'     => is_link($fullPath) ? (string) readlink($fullPath) : 'Fichier local',
                ];
            }
        }
    }
}

// Récupération du catalogue complet des mods disponibles
$catalogStmt = $db->query(
    'SELECT m.MOD_ID, m.MOD_NOM, m.MOD_DESCRIPTION, m.MOD_CHEMIN_DOSSIER,
            fm.FMOD_NOM_FICHIER, fm.FMOD_TAILLE_MO, fm.FMOD_NB_DOWNLOAD
     FROM `MOD` m
     INNER JOIN FICHIER_MOD fm ON m.MOD_ID = fm.MOD_ID
     ORDER BY m.MOD_ID ASC'
);
$catalogMods = $catalogStmt->fetchAll();

$csrfToken = Security::generateCsrfToken();

require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<main class="app-main">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.4rem;">MODS FABRIC — <?= Security::sanitizeString((string) $server['SVR_NOM']) ?></h1>
            <p style="color: var(--text-secondary); font-size: 0.9rem;">
                Installation instantanée par liens symboliques Linux (<code>symlinks</code>) depuis le dépôt central <code>/data/mods/</code>.
            </p>
        </div>
        <a href="/server-control?id=<?= $serverId ?>" class="btn btn-cyan">← Retour au serveur</a>
    </div>

    <div class="bento-grid">
        <!-- COLONNE GAUCHE : MODS INSTALLÉS SUR L'INSTANCE -->
        <div class="bento-col-5 bento-card card-cyan-accent" style="grid-column: span 5;">
            <div class="card-header">
                <h2 class="card-title">Mods actifs sur le serveur (<?= count($installedFiles) ?>)</h2>
                <span class="mono" style="font-size: 0.75rem; color: var(--accent-cyan);">/servers/<?= $serverId ?>/mods/</span>
            </div>

            <?php if (empty($installedFiles)): ?>
                <p style="color: var(--text-secondary); font-size: 0.9rem; padding: 1.5rem 0;">
                    Aucun mod n'est encore lié à cette instance. Sélectionnez les mods d'optimisation à droite.
                </p>
            <?php else: ?>
                <div class="stacked-list">
                    <?php foreach ($installedFiles as $fileInfo): ?>
                        <div class="stacked-item">
                            <div class="stacked-item-left">
                                <div class="stacked-cube-icon">SYM</div>
                                <div>
                                    <div class="mono" style="font-weight: 600; font-size: 0.85rem; color: var(--text-primary);">
                                        <?= Security::sanitizeString($fileInfo['name']) ?>
                                    </div>
                                    <div class="mono" style="font-size: 0.72rem; color: var(--text-muted);">
                                        <?= $fileInfo['is_symlink'] ? 'Lien symbolique actif' : 'Fichier physique' ?>
                                    </div>
                                </div>
                            </div>

                            <form method="POST" action="/server-mods?id=<?= $serverId ?>">
                                <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">
                                <input type="hidden" name="mod_action" value="uninstall">
                                <input type="hidden" name="jar_file" value="<?= Security::sanitizeString($fileInfo['name']) ?>">
                                <button type="submit" class="btn btn-stop" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;">
                                    Retirer
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- COLONNE DROITE : CATALOGUE D'OPTIMISATION FABRIC -->
        <div class="bento-col-7 bento-card" style="grid-column: span 7;">
            <div class="card-header">
                <h2 class="card-title">Catalogue Fabric &amp; Optimisation Serveur</h2>
                <span class="badge badge-online">Symlink Ready</span>
            </div>

            <div class="stacked-list">
                <?php foreach ($catalogMods as $mod): ?>
                    <?php
                    $modId         = (int) $mod['MOD_ID'];
                    $jarName       = trim((string) $mod['FMOD_NOM_FICHIER']);
                    $alreadyLinked = isset($installedFiles[$jarName]);
                    ?>
                    <div class="stacked-item">
                        <div class="stacked-item-left">
                            <div class="stacked-cube-icon" style="border-color: var(--accent-orange); color: var(--accent-orange);">
                                MOD
                            </div>
                            <div>
                                <div style="display: flex; align-items: center; gap: 0.6rem;">
                                    <strong style="color: var(--text-primary);">
                                        <?= Security::sanitizeString(trim((string) $mod['MOD_NOM'])) ?>
                                    </strong>
                                    <span class="mono" style="font-size: 0.75rem; color: var(--text-muted);">
                                        (<?= (int) $mod['FMOD_TAILLE_MO'] ?> Mo • <?= (int) $mod['FMOD_NB_DOWNLOAD'] ?> inst.)
                                    </span>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.2rem;">
                                    <?= Security::sanitizeString(trim((string) $mod['MOD_DESCRIPTION'])) ?>
                                </div>
                            </div>
                        </div>

                        <div>
                            <?php if ($alreadyLinked): ?>
                                <span class="badge badge-online">Installé</span>
                            <?php else: ?>
                                <form method="POST" action="/server-mods?id=<?= $serverId ?>">
                                    <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">
                                    <input type="hidden" name="mod_action" value="install">
                                    <input type="hidden" name="mod_id" value="<?= $modId ?>">
                                    <button type="submit" class="btn btn-start" style="padding: 0.5rem 1rem; font-size: 0.8rem;">
                                        + Installer
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</main>