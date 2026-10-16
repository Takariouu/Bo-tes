<?php

declare(strict_types=1);

if (!Auth::isAdmin()) {
    addFlashNotification('error', 'Accès strictement réservé aux administrateurs Boötes.');
    header('Location: /dashboard');
    exit;
}

$db          = Database::getInstance();
$currentUser = (string) Auth::getCurrentUserId();

// Traitement des actions d'administration (POST sécurisé par CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;

    if (!Security::verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        addFlashNotification('error', 'Jeton CSRF invalide.');
    } else {
        $adminAction = isset($_POST['admin_action']) ? (string) $_POST['admin_action'] : '';

        // 1. Arrêt forcé d'urgence d'un serveur
        if ($adminAction === 'force_stop_server') {
            $targetServerId = isset($_POST['server_id']) ? (int) $_POST['server_id'] : 0;
            if ($targetServerId > 0) {
                MinecraftServerManager::stopServer($targetServerId, $db);
                addFlashNotification('info', sprintf('Arrêt forcé exécuté sur le serveur #%d.', $targetServerId));
            }
        }
        // 2. Changement du type de compte (Standard / Premium / Admin)
        elseif ($adminAction === 'update_user_role') {
            $targetUserId = trim((string) ($_POST['target_user_id'] ?? ''));
            $newRoleId    = (int) ($_POST['new_role_id'] ?? Auth::ROLE_STANDARD);

            if ($targetUserId !== '' && in_array($newRoleId, [Auth::ROLE_STANDARD, Auth::ROLE_PREMIUM, Auth::ROLE_ADMIN], true)) {
                $roleStmt = $db->prepare('UPDATE UTILISATEUR SET TDC_ID = :tdc WHERE UTI_ID = :id');
                $roleStmt->execute([
                    ':tdc' => $newRoleId,
                    ':id'  => $targetUserId,
                ]);
                addFlashNotification('success', 'Le rôle de l\'utilisateur a été mis à jour.');
            }
        }
        // 3. Suppression d'un compte utilisateur (Respect RGPD + utilisateur anonyme '0' pour les créations publiques)
        elseif ($adminAction === 'delete_user') {
            $targetUserId = trim((string) ($_POST['target_user_id'] ?? ''));

            if ($targetUserId !== '' && $targetUserId !== $currentUser) {
                // Création de l'utilisateur Anonyme '0' s'il n'existe pas pour conserver les mods/modpacks publics
                $anonHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);
                $anonStmt = $db->prepare(
                    "INSERT IGNORE INTO UTILISATEUR (UTI_ID, IMG_ID, TDC_ID, UTI_NOM, UTI_EMAIL, UTI_DATE_CREATION, UTI_MOT_DE_PASSE, UTI_DATE_DERNIERE_CONNEXION)
                     VALUES ('0', NULL, 1, 'Anonyme', 'anonyme@bootes.local', NOW(), :mdp, NULL)"
                );
                $anonStmt->execute([':mdp' => $anonHash]);

                // Réattribution des ModPacks, Mods et Plugins publics à l'utilisateur '0'
                $db->prepare("UPDATE MOD_PACK SET UTI_ID = '0' WHERE UTI_ID = :id AND MOPA_EST_PUBLIC = 1")->execute([':id' => $targetUserId]);
                $db->prepare("UPDATE `MOD` SET UTI_ID = '0' WHERE UTI_ID = :id")->execute([':id' => $targetUserId]);
                $db->prepare("UPDATE PLUGIN SET UTI_ID = '0' WHERE UTI_ID = :id")->execute([':id' => $targetUserId]);

                // Arrêt et suppression des serveurs de l'utilisateur s'il n'y a pas d'autre co-administrateur
                $ownedSrvStmt = $db->prepare('SELECT SVR_ID, MOPA_ID FROM SERVEUR WHERE UTI_ID = :id');
                $ownedSrvStmt->execute([':id' => $targetUserId]);
                $ownedServers = $ownedSrvStmt->fetchAll();

                foreach ($ownedServers as $os) {
                    $sId = (int) $os['SVR_ID'];
                    MinecraftServerManager::stopServer($sId, $db);

                    // Vérifie s'il existe un co-administrateur dans ADMINISTRER_SERVEUR
                    $coAdminStmt = $db->prepare('SELECT UTI_ID FROM ADMINISTRER_SERVEUR WHERE SVR_ID = :svr_id AND UTI_ID != :uti_id LIMIT 1');
                    $coAdminStmt->execute([':svr_id' => $sId, ':uti_id' =>$targetUserId]);
                    $newOwner =$coAdminStmt->fetchColumn();

                    if ($newOwner) {
                        // Transfert de propriété au co-administrateur
                        $db->prepare('UPDATE SERVEUR SET UTI_ID = :new_owner WHERE SVR_ID = :svr_id')
                           ->execute([':new_owner' => $newOwner, ':svr_id' =>$sId]);
                    } else {
                        // Suppression des dépendances et du serveur
                        $db->prepare('DELETE FROM BACKUPS WHERE SVR_ID = :svr_id')->execute([':svr_id' =>$sId]);
                        $db->prepare('DELETE FROM ADMINISTRER_SERVEUR WHERE SVR_ID = :svr_id')->execute([':svr_id' =>$sId]);
                        $db->prepare('DELETE FROM ETRE_LISTER WHERE SVR_ID = :svr_id')->execute([':svr_id' =>$sId]);
                        $db->prepare('DELETE FROM CONNEXION_JOUEUR WHERE SVR_ID = :svr_id')->execute([':svr_id' =>$sId]);
                        $db->prepare('DELETE FROM AVOIR_HISTORIQUE WHERE SVR_ID = :svr_id')->execute([':svr_id' =>$sId]);
                        $db->prepare('DELETE FROM EVALUATION_SERVEUR WHERE SVR_ID = :svr_id')->execute([':svr_id' =>$sId]);
                        $db->prepare('DELETE FROM SIGNALEMENT_SERVEUR WHERE SVR_ID = :svr_id')->execute([':svr_id' =>$sId]);
                        $db->prepare('DELETE FROM SERVEUR WHERE SVR_ID = :svr_id')->execute([':svr_id' =>$sId]);
                    }
                }

                // Nettoyage des liaisons restantes et suppression du compte
                $db->prepare('DELETE FROM MOD_PACK WHERE UTI_ID = :id')->execute([':id' => $targetUserId]);$db->prepare('DELETE FROM ADMINISTRER_SERVEUR WHERE UTI_ID = :id')->execute([':id' => $targetUserId]);$db->prepare('DELETE FROM RELATION_UTILISATEUR WHERE UTI_ID = :id1 OR UTI_ID_DEMANDEUR = :id2')->execute([':id1' => $targetUserId, ':id2' =>$targetUserId]);
                $db->prepare('DELETE FROM SIGNALEMENT_UTILISATEUR WHERE UTI_ID = :id')->execute([':id' =>$targetUserId]);
                $db->prepare('DELETE FROM SIGNALEMENT WHERE UTI_ID = :id')->execute([':id' =>$targetUserId]);
                $db->prepare('DELETE FROM UTILISATEUR WHERE UTI_ID = :id')->execute([':id' =>$targetUserId]);

                addFlashNotification('info', 'Compte supprimé (mods publics anonymisés sous ID 0).');
            }
        }
        // 4. Clôture / Suppression d'un signalement traité
        elseif ($adminAction === 'dismiss_report') {$sgnId = isset($_POST['sgn_id']) ? (int)$_POST['sgn_id'] : 0;
            if ($sgnId > 0) {
                $db->prepare('DELETE FROM SIGNALEMENT_SERVEUR WHERE SGN_ID = :id')->execute([':id' =>$sgnId]);
                $db->prepare('DELETE FROM SIGNALEMENT_MOD WHERE SGN_ID = :id')->execute([':id' =>$sgnId]);
                $db->prepare('DELETE FROM SIGNALEMENT_MODPACK WHERE SGN_ID = :id')->execute([':id' =>$sgnId]);
                $db->prepare('DELETE FROM SIGNALEMENT_PLUGIN WHERE SGN_ID = :id')->execute([':id' =>$sgnId]);
                $db->prepare('DELETE FROM SIGNALEMENT_UTILISATEUR WHERE SGN_ID = :id')->execute([':id' =>$sgnId]);
                $db->prepare('DELETE FROM SIGNALEMENT WHERE SGN_ID = :id')->execute([':id' =>$sgnId]);

                addFlashNotification('success', sprintf('Signalement #%d traité et archivé.', $sgnId));
            }
        }

        header('Location: /admin-dashboard');
        exit;
    }
}

// Calcul de la RAM allouée aux serveurs sur le plafond de 6144 Mo
$allocatedMcRamMo = MinecraftServerManager::getCurrentAllocatedRamMo($db);
$mcRamPercent     = min(100, round(($allocatedMcRamMo / MinecraftServerManager::MAX_GLOBAL_RAM_MO) * 100));

// Lecture de tous les serveurs hébergés
$allServersStmt =$db->query(
    'SELECT s.SVR_ID, s.SVR_NOM, s.SVR_PORT, s.SVR_RAM_ALLOUEE_MO, s.ETA_ID,
            u.UTI_NOM, v.VER_LIBELLE, e.ETA_LIBELLE
     FROM SERVEUR s
     LEFT JOIN UTILISATEUR u ON s.UTI_ID = u.UTI_ID
     LEFT JOIN VERSION v ON s.VER_ID = v.VER_ID
     LEFT JOIN ETAT e ON s.ETA_ID = e.ETA_ID
     ORDER BY s.ETA_ID DESC, s.SVR_ID DESC'
);
$allServers =$allServersStmt->fetchAll();

// Lecture de tous les utilisateurs inscrits
$allUsersStmt =$db->query(
    'SELECT u.UTI_ID, u.UTI_NOM, u.UTI_EMAIL, u.UTI_DATE_CREATION, u.UTI_DATE_DERNIERE_CONNEXION, u.TDC_ID,
            t.TDC_NOM_TYPE_COMPTE,
            (SELECT COUNT(*) FROM SERVEUR s WHERE s.UTI_ID = u.UTI_ID) AS nb_serveurs
     FROM UTILISATEUR u
     LEFT JOIN TYPE_DE_COMPTE t ON u.TDC_ID = t.TDC_ID
     WHERE u.UTI_ID != \'0\'
     ORDER BY u.UTI_DATE_CREATION DESC'
);
$allUsers =$allUsersStmt->fetchAll();

// Lecture des signalements (Table SIGNALEMENT et TYPE_SIGNALEMENT)
$reportsStmt =$db->query(
    'SELECT sg.SGN_ID, sg.SGN_MESSAGE, sg.SGN_DATE_PUBLICATION,
            u.UTI_NOM AS AUTEUR_NOM,
            ts.TSGN_LIBELLE
     FROM SIGNALEMENT sg
     LEFT JOIN UTILISATEUR u ON sg.UTI_ID = u.UTI_ID
     LEFT JOIN TYPE_SIGNALEMENT ts ON sg.TSGN_ID = ts.TSGN_ID
     ORDER BY sg.SGN_DATE_PUBLICATION DESC'
);
$allReports =$reportsStmt->fetchAll();

$csrfToken = Security::generateCsrfToken();

require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<main class="app-main">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.4rem; color: var(--accent-orange);">TOUR DE CONTRÔLE ADMINISTRATEUR</h1>
            <p style="color: var(--text-secondary); font-size: 0.9rem;">
                Supervision matérielle de l'hôte Linux Mint (8 Go RAM) et modération globale de Boötes.
            </p>
        </div>
        <span class="badge badge-online">Privilèges SuperAdmin (TDC_ID = 3)</span>
    </div>

    <!-- JAUGE DE SUPERVISION MATÉRIELLE (8 GO TOTAL : 1,5 GO OS + 0,5 GO WEB + 6,0 GO SERVEURS) -->
    <div class="bento-grid" style="margin-bottom: 2rem;">
        <div class="bento-col-8 bento-card card-orange-accent">
            <div class="card-header">
                <h2 class="card-title">Quotas RAM Serveurs Minecraft (Plafond : 6144 Mo)</h2>
                <span class="mono" style="color: var(--accent-cyan); font-weight: 700;">
                    <?= $allocatedMcRamMo ?> Mo / <?= MinecraftServerManager::MAX_GLOBAL_RAM_MO ?> Mo réservés (<?= $mcRamPercent ?>%)
                </span>
            </div>

            <div class="ram-progress-bar" style="height: 14px;">
                <div class="ram-progress-fill" style="width: <?= $mcRamPercent ?>%;"></div>
            </div>

            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.75rem;" class="mono">
                <span>Disponible pour nouvelles instances : <?= max(0, MinecraftServerManager::MAX_GLOBAL_RAM_MO - $allocatedMcRamMo) ?> Mo</span>
                <span>Instances totales : <?= count($allServers) ?></span>
            </div>
        </div>

        <div class="bento-col-4 bento-card">
            <h2 class="card-title" style="margin-bottom: 0.85rem;">Architecture Machine (8192 Mo)</h2>
            <ul class="mono" style="font-size: 0.82rem; display: flex; flex-direction: column; gap: 0.5rem; color: var(--text-secondary);">
                <li style="display: flex; justify-content: space-between;">
                    <span>OS Linux Mint :</span>
                    <strong style="color: var(--text-primary);">1536 Mo (1,5 Go)</strong>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span>Apache / PHP / MariaDB :</span>
                    <strong style="color: var(--text-primary);">512 Mo (0,5 Go)</strong>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span>Pool Serveurs Fabric :</span>
                    <strong style="color: var(--accent-cyan);">6144 Mo (6,0 Go)</strong>
                </li>
            </ul>
        </div>
    </div>

    <!-- LISTE GLOBALE DES SERVEURS AVEC ARRÊT D'URGENCE -->
    <section style="margin-bottom: 2.5rem;">
        <div class="bento-card">
            <div class="card-header">
                <h2 class="card-title">Supervision Globale des Serveurs (<?= count($allServers) ?>)</h2>
            </div>

            <div class="stacked-list">
                <?php if (empty($allServers)): ?>
                    <p style="color: var(--text-secondary); padding: 1rem 0;">Aucun serveur enregistré sur la plateforme.</p>
                <?php else: ?>
                    <?php foreach ($allServers as$srv): ?>
                        <?php
                        $sId      = (int)$srv['SVR_ID'];
                        $isOnline = ((int)$srv['ETA_ID'] === MinecraftServerManager::STATE_RUNNING);
                        ?>
                        <div class="stacked-item">
                            <div class="stacked-item-left">
                                <div class="stacked-cube-icon">#<?= $sId ?></div>
                                <div>
                                    <div style="font-weight: 600; color: var(--text-primary);">
                                        <?= Security::sanitizeString((string) $srv['SVR_NOM']) ?>
                                        <span class="mono" style="font-size: 0.8rem; color: var(--text-muted);">
                                            (Propriétaire : <?= Security::sanitizeString((string) ($srv['UTI_NOM'] ?? 'Inconnu')) ?>)
                                        </span>
                                    </div>
                                    <div class="mono" style="font-size: 0.8rem; color: var(--text-secondary);">
                                        Port : <?= (int) $srv['SVR_PORT'] ?>
                                        • RAM : <strong style="color: var(--accent-cyan);"><?= (int) $srv['SVR_RAM_ALLOUEE_MO'] ?> Mo</strong>
                                        • Version : <?= Security::sanitizeString((string) ($srv['VER_LIBELLE'] ?? 'Fabric')) ?>
                                    </div>
                                </div>
                            </div>

                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <?php if ($isOnline): ?>
                                    <span class="badge badge-online">● En ligne</span>
                                    <form method="POST" action="/admin-dashboard" onsubmit="return confirm('Forcer l\'arrêt immédiat de ce serveur ?');">
                                        <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">
                                        <input type="hidden" name="admin_action" value="force_stop_server">
                                        <input type="hidden" name="server_id" value="<?= $sId ?>">
                                        <button type="submit" class="btn btn-stop" style="padding: 0.45rem 0.9rem; font-size: 0.8rem;">
                                            ■ Kill / Arrêt d'urgence
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-offline">○ Éteint</span>
                                <?php endif; ?>

                                <a href="/server-control?id=<?= $sId ?>" class="btn btn-cyan" style="padding: 0.45rem 0.9rem; font-size: 0.8rem;">
                                    Inspecter
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- MODÉRATION DES UTILISATEURS & CONSULTATION DES SIGNALEMENTS -->
    <div class="bento-grid">
        <!-- Table de modération des utilisateurs -->
        <div class="bento-col-7 bento-card" style="grid-column: span 7;">
            <div class="card-header">
                <h2 class="card-title">Comptes Utilisateurs (<?= count($allUsers) ?>)</h2>
            </div>

            <div class="stacked-list">
                <?php foreach ($allUsers as$u): ?>
                    <?php $uId = trim((string)$u['UTI_ID']); ?>
                    <div class="stacked-item" style="flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <div style="font-weight: 600; color: var(--text-primary);">
                                <?= Security::sanitizeString(trim((string) $u['UTI_NOM'])) ?>
                                <span class="mono" style="font-size: 0.75rem; color: var(--accent-cyan);">
                                    [<?= (int) $u['nb_serveurs'] ?> srv]
                                </span>
                            </div>
                            <div class="mono" style="font-size: 0.78rem; color: var(--text-secondary);">
                                <?= Security::sanitizeString(trim((string) $u['UTI_EMAIL'])) ?>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <!-- Formulaire de changement d'offre (Standard 2 Go / Premium / Admin) -->
                            <form method="POST" action="/admin-dashboard" style="display: flex; align-items: center; gap: 0.4rem;">
                                <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">
                                <input type="hidden" name="admin_action" value="update_user_role">
                                <input type="hidden" name="target_user_id" value="<?= Security::sanitizeString($uId) ?>">
                                <select name="new_role_id" class="form-control" style="padding: 0.35rem 0.6rem; font-size: 0.8rem; width: auto;">
                                    <option value="1" <?= (int) $u['TDC_ID'] === 1 ? 'selected' : '' ?>>Standard (2 Go)</option>
                                    <option value="2" <?= (int) $u['TDC_ID'] === 2 ? 'selected' : '' ?>>Premium (6 Go)</option>
                                    <option value="3" <?= (int) $u['TDC_ID'] === 3 ? 'selected' : '' ?>>Admin</option>
                                </select>
                                <button type="submit" class="btn btn-cyan" style="padding: 0.4rem 0.75rem; font-size: 0.75rem;">
                                    OK
                                </button>
                            </form>

                            <?php if ($uId !==$currentUser): ?>
                                <form method="POST" action="/admin-dashboard" onsubmit="return confirm('Supprimer ce compte et anonymiser ses publications publiques ?');">
                                    <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">
                                    <input type="hidden" name="admin_action" value="delete_user">
                                    <input type="hidden" name="target_user_id" value="<?= Security::sanitizeString($uId) ?>">
                                    <button type="submit" class="btn btn-stop" style="padding: 0.4rem 0.75rem; font-size: 0.75rem;">
                                        Suppr.
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Consultation des signalements -->
        <div class="bento-col-5 bento-card" style="grid-column: span 5;">
            <div class="card-header">
                <h2 class="card-title">Signalements Récents (<?= count($allReports) ?>)</h2>
            </div>

            <?php if (empty($allReports)): ?>
                <p style="color: var(--text-secondary); font-size: 0.9rem; padding: 1.5rem 0;">
                    Aucun signalement en attente de modération.
                </p>
            <?php else: ?>
                <div class="stacked-list">
                    <?php foreach ($allReports as$rep): ?>
                        <div class="stacked-item" style="flex-direction: column; align-items: stretch; gap: 0.65rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #FCA5A5;">
                                    <?= Security::sanitizeString(trim((string) ($rep['TSGN_LIBELLE'] ?? 'Signalement'))) ?>
                                </span>
                                <span class="mono" style="font-size: 0.75rem; color: var(--text-muted);">
                                    Par <?= Security::sanitizeString(trim((string) ($rep['AUTEUR_NOM'] ?? 'Anonyme'))) ?>
                                </span>
                            </div>

                            <p style="font-size: 0.88rem; color: var(--text-primary);">
                                <?= Security::sanitizeString(trim((string) $rep['SGN_MESSAGE'])) ?>
                            </p>

                            <form method="POST" action="/admin-dashboard" style="align-self: flex-end;">
                                <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">
                                <input type="hidden" name="admin_action" value="dismiss_report">
                                <input type="hidden" name="sgn_id" value="<?= (int) $rep['SGN_ID'] ?>">
                                <button type="submit" class="btn btn-cyan" style="padding: 0.35rem 0.8rem; font-size: 0.75rem;">
                                    ✓ Marquer comme traité
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>