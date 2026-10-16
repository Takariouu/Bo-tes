<?php

declare(strict_types=1);

$db       = Database::getInstance();
$userId   = (string) Auth::getCurrentUserId();
$serverId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($serverId <= 0 || !Auth::hasServerAccess($userId, $serverId, $db)) {
    addFlashNotification('error', 'Accès refusé : vous n\'êtes pas administrateur de ce serveur.');
    header('Location: /dashboard');
    exit;
}

// Traitement des actions POST (Allumer / Éteindre / Redémarrer)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;

    if (!Security::verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        addFlashNotification('error', 'Jeton de sécurité CSRF invalide.');
    } else {
        $powerAction = isset($_POST['power_action']) ? (string) $_POST['power_action'] : '';

        if ($powerAction === 'start') {
            $res = MinecraftServerManager::startServer($serverId, $db);
            addFlashNotification($res['success'] ? 'success' : 'error', $res['message']);
        } elseif ($powerAction === 'stop') {
            $stopped = MinecraftServerManager::stopServer($serverId, $db);
            addFlashNotification(
                $stopped ? 'info' : 'error',
                $stopped ? 'Le serveur a été arrêté proprement.' : 'Erreur lors de l\'arrêt du serveur.'
            );
        } elseif ($powerAction === 'restart') {
            MinecraftServerManager::stopServer($serverId, $db);
            usleep(300000); // Pause de 300ms avant relance
            $res = MinecraftServerManager::startServer($serverId, $db);
            addFlashNotification(
                $res['success'] ? 'success' : 'error',
                $res['success'] ? 'Serveur redémarré avec succès.' : $res['message']
            );
        }

        header('Location: /server-control?id=' . $serverId);
        exit;
    }
}

// Récupération des informations détaillées du serveur
$stmt = $db->prepare(
    'SELECT s.*, v.VER_LIBELLE, e.ETA_LIBELLE, m.MDJ_LIBELLE, mp.MOPA_NOM
     FROM SERVEUR s
     LEFT JOIN VERSION v ON s.VER_ID = v.VER_ID
     LEFT JOIN ETAT e ON s.ETA_ID = e.ETA_ID
     LEFT JOIN MODE_DE_JEU m ON s.MDJ_ID = m.MDJ_ID
     LEFT JOIN MOD_PACK mp ON s.MOPA_ID = mp.MOPA_ID
     WHERE s.SVR_ID = :svr_id
     LIMIT 1'
);
$stmt->execute([':svr_id' => $serverId]);
$server = $stmt->fetch();

if (!$server) {
    addFlashNotification('error', 'Serveur introuvable.');
    header('Location: /dashboard');
    exit;
}

// Synchronisation de l'état réel du processus Linux avec la base de données
$processAlive = MinecraftServerManager::isProcessRunning($serverId);
$isOnline     = ((int) $server['ETA_ID'] === MinecraftServerManager::STATE_RUNNING && $processAlive);

if ((int) $server['ETA_ID'] === MinecraftServerManager::STATE_RUNNING && !$processAlive) {
    MinecraftServerManager::stopServer($serverId, $db);
    $isOnline = false;
}

$connectionAddress = sprintf('%s:%d', $server['SVR_ADDRESSE'], (int) $server['SVR_PORT']);
$csrfToken         = Security::generateCsrfToken();

require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<main class="app-main">
    <!-- EN-TÊTE DU SERVEUR & STATUT EN DIRECT -->
    <div class="bento-card <?= $isOnline ? 'card-cyan-accent' : '' ?>" style="margin-bottom: 1.5rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.25rem;">
            <div>
                <div style="display: flex; align-items: center; gap: 0.85rem; margin-bottom: 0.4rem;">
                    <h1 style="font-size: 1.5rem;">
                        <?= Security::sanitizeString((string) $server['SVR_NOM']) ?>
                    </h1>
                    <?php if ($isOnline): ?>
                        <span class="badge badge-online">● En ligne</span>
                    <?php else: ?>
                        <span class="badge badge-offline">○ Éteint</span>
                    <?php endif; ?>
                </div>

                <p style="color: var(--text-secondary); font-size: 0.9rem;">
                    <?= Security::sanitizeString((string) ($server['SVR_DECRIPTION'] ?: 'Instance Minecraft Fabric optimisée.')) ?>
                </p>
            </div>

            <!-- Adresse de connexion ip:port copiable -->
            <div style="background: rgba(5, 5, 10, 0.85); border: 1px solid var(--border-cyan); border-radius: var(--radius-sm); padding: 0.65rem 1.1rem; display: flex; align-items: center; gap: 0.85rem;">
                <div>
                    <div style="font-size: 0.7rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.1em;">Adresse de connexion</div>
                    <code id="server-ip-port" class="mono" style="font-size: 1rem; color: var(--accent-cyan); font-weight: 600;">
                        <?= Security::sanitizeString($connectionAddress) ?>
                    </code>
                </div>
                <button type="button" class="btn btn-cyan" style="padding: 0.4rem 0.75rem; font-size: 0.75rem;" onclick="copyServerAddress()">
                    Copier
                </button>
            </div>
        </div>

        <!-- BARRE D'ACTIONS D'ALIMENTATION (ALLUMER / REDÉMARRER / ÉTEINDRE) -->
        <form method="POST" action="/server-control?id=<?= $serverId ?>" style="margin-top: 1.75rem; padding-top: 1.25rem; border-top: 1px solid var(--border-subtle); display: flex; gap: 1rem; flex-wrap: wrap;">
            <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">

            <?php if (!$isOnline): ?>
                <button type="submit" name="power_action" value="start" class="btn btn-start">
                    ▶ Allumer le serveur
                </button>
            <?php else: ?>
                <button type="submit" name="power_action" value="restart" class="btn btn-cyan">
                    ↻ Redémarrer
                </button>
                <button type="submit" name="power_action" value="stop" class="btn btn-stop">
                    ■ Éteindre le serveur
                </button>
            <?php endif; ?>
        </form>
    </div>

    <!-- INFORMATIONS TECHNIQUES & ACCÈS RAPIDES -->
    <div class="bento-grid">
        <!-- Spécifications de l'instance -->
        <div class="bento-col-4 bento-card">
            <h3 class="card-title" style="margin-bottom: 1rem;">Configuration Technique</h3>
            <ul style="display: flex; flex-direction: column; gap: 0.65rem; font-size: 0.9rem;">
                <li style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-secondary);">Moteur :</span>
                    <span class="mono">Fabric <?= Security::sanitizeString((string) ($server['VER_LIBELLE'] ?? '1.20.4')) ?></span>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-secondary);">RAM dédiée :</span>
                    <span class="mono" style="color: var(--accent-cyan);"><?= (int) $server['SVR_RAM_ALLOUEE_MO'] ?> Mo</span>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-secondary);">Port réseau :</span>
                    <span class="mono"><?= (int) $server['SVR_PORT'] ?></span>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-secondary);">Mode de jeu :</span>
                    <span><?= Security::sanitizeString((string) ($server['MDJ_LIBELLE'] ?? 'Survie')) ?></span>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-secondary);">Joueurs max :</span>
                    <span class="mono"><?= (int) $server['SVR_JOUEURS_MAX'] ?> slots</span>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-secondary);">Profil ModPack :</span>
                    <span><?= Security::sanitizeString((string) ($server['MOPA_NOM'] ?? 'Boötes Core')) ?></span>
                </li>
            </ul>
        </div>

        <!-- Liens rapides vers Console, Mods et Backups -->
        <div class="bento-col-8 bento-card">
            <h3 class="card-title" style="margin-bottom: 1rem;">Outils d'Administration de l'Instance</h3>

            <div class="stacked-list">
                <div class="stacked-item">
                    <div class="stacked-item-left">
                        <div class="stacked-cube-icon">&gt;_</div>
                        <div>
                            <div style="font-weight: 600;">Console Temps Réel &amp; Logs</div>
                            <div style="font-size: 0.85rem; color: var(--text-secondary);">
                                Surveillez le fichier <code>logs/latest.log</code> et exécutez des commandes opérateur.
                            </div>
                        </div>
                    </div>
                    <a href="/server-console?id=<?= $serverId ?>" class="btn btn-cyan">Ouvrir la Console</a>
                </div>

                <div class="stacked-item">
                    <div class="stacked-item-left">
                        <div class="stacked-cube-icon" style="border-color: var(--accent-orange); color: var(--accent-orange);">JAR</div>
                        <div>
                            <div style="font-weight: 600;">Catalogue de Mods Fabric (Symlinks)</div>
                            <div style="font-size: 0.85rem; color: var(--text-secondary);">
                                Installez Lithium, FerriteCore, Krypton ou vos propres mods sans dupliquer l'espace disque.
                            </div>
                        </div>
                    </div>
                    <a href="/server-mods?id=<?= $serverId ?>" class="btn btn-cyan">Gérer les Mods</a>
                </div>

                <div class="stacked-item">
                    <div class="stacked-item-left">
                        <div class="stacked-cube-icon">TAR</div>
                        <div>
                            <div style="font-weight: 600;">Sauvegardes du Monde (Backups .tar.gz)</div>
                            <div style="font-size: 0.85rem; color: var(--text-secondary);">
                                Compressez le dossier <code>world</code> et téléchargez vos archives de sécurité.
                            </div>
                        </div>
                    </div>
                    <a href="/server-backups?id=<?= $serverId ?>" class="btn btn-cyan">Gérer les Backups</a>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function copyServerAddress() {
    const addr = document.getElementById('server-ip-port').textContent.trim();
    navigator.clipboard.writeText(addr).then(() => {
        if (window.BootesNotify) {
            window.BootesNotify.success('Adresse ' + addr + ' copiée dans le presse-papiers !');
        }
    });
}
</script>