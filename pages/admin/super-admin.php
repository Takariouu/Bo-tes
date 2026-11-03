<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Security;
use Bootes\Src\View;

// Contrôle strict : exclusivement accessible au Super-Administrateur
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    ?>
    <section class="super-admin super-admin--forbidden">
        <h1 class="super-admin__title">Accès strictement réservé au Super-Administrateur</h1>
        <p class="super-admin__message">
            Vos habilitations actuelles ne permettent pas de gérer le collège des Administrateurs ni d'inspecter les journaux d'audit système.
        </p>
        <a href="/admin/dashboard" class="super-admin__btn">Retour au tableau de bord</a>
    </section>
    <?php
    return;
}

$db            = $db ?? Database::getInstance();
$currentUserId = Auth::getUserId() ?? 0;
$csrfToken     = Security::generateCsrfToken();

$feedbackMessage = null;
$feedbackError   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 35) ?? '';

    if (!Security::verifyCsrfToken($postCsrf)) {
        $feedbackError = 'Jeton CSRF invalide ou expiré.';
    } else {
        try {
            if ($action === 'promote_admin') {
                $identifier = Security::validateString($_POST['user_identifier'] ?? '', 3, 100);
                if ($identifier === null) {
                    $feedbackError = 'Veuillez saisir le pseudonyme ou l\'adresse e-mail de l\'utilisateur à promouvoir.';
                } else {
                    $findStmt = $db->prepare('
                        SELECT id_utilisateur, pseudo, role
                        FROM UTILISATEUR
                        WHERE pseudo = :p OR email = :e
                        LIMIT 1
                    ');
                    $findStmt->execute([
                        ':p' => $identifier,
                        ':e' => $identifier,
                    ]);
                    $targetUser = $findStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$targetUser) {
                        $feedbackError = 'Aucun compte utilisateur trouvé avec cet identifiant.';
                    } elseif (in_array(strtoupper((string) $targetUser['role']), [Auth::ROLE_ADMIN, Auth::ROLE_SUPER_ADMIN], true)) {
                        $feedbackError = 'Cet utilisateur possède déjà des privilèges Administrateur ou Super-Administrateur.';
                    } else {
                        $targetUid = (int) $targetUser['id_utilisateur'];
                        $updStmt   = $db->prepare('UPDATE UTILISATEUR SET role = :role WHERE id_utilisateur = :uid');
                        $updStmt->execute([
                            ':role' => Auth::ROLE_ADMIN,
                            ':uid'  => $targetUid,
                        ]);

                        $logStmt = $db->prepare('
                            INSERT INTO LOG_SYSTEME (id_utilisateur, niveau, action, details, adresse_ip, date_log)
                            VALUES (:uid, \'CRITICAL\', \'PROMOTE_ADMIN\', :details, :ip, NOW())
                        ');
                        $logStmt->execute([
                            ':uid'     => $currentUserId,
                            ':details' => sprintf('Promotion de l\'utilisateur "%s" (#%d) au rang ADMIN.', (string) $targetUser['pseudo'], $targetUid),
                            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        ]);

                        $feedbackMessage = sprintf('L\'utilisateur "%s" (#%d) a été promu au rôle Administrateur.', (string) $targetUser['pseudo'], $targetUid);
                    }
                }
            } elseif ($action === 'revoke_admin') {
                $targetUid = Security::validatePositiveInt($_POST['admin_user_id'] ?? null);

                if ($targetUid === null || $targetUid === $currentUserId) {
                    $feedbackError = 'Vous ne pouvez pas révoquer vos propres droits de Super-Administrateur.';
                } else {
                    $chkStmt = $db->prepare('SELECT pseudo, role FROM UTILISATEUR WHERE id_utilisateur = :uid LIMIT 1');
                    $chkStmt->execute([':uid' => $targetUid]);
                    $targetAdmin = $chkStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$targetAdmin || strtoupper((string) $targetAdmin['role']) === Auth::ROLE_SUPER_ADMIN) {
                        $feedbackError = 'Impossible de révoquer un Super-Administrateur.';
                    } else {
                        $revStmt = $db->prepare('UPDATE UTILISATEUR SET role = :role WHERE id_utilisateur = :uid');
                        $revStmt->execute([
                            ':role' => Auth::ROLE_UTILISATEUR,
                            ':uid'  => $targetUid,
                        ]);

                        $logStmt = $db->prepare('
                            INSERT INTO LOG_SYSTEME (id_utilisateur, niveau, action, details, adresse_ip, date_log)
                            VALUES (:uid, \'CRITICAL\', \'REVOKE_ADMIN\', :details, :ip, NOW())
                        ');
                        $logStmt->execute([
                            ':uid'     => $currentUserId,
                            ':details' => sprintf('Révocation des privilèges ADMIN de "%s" (#%d).', (string) $targetAdmin['pseudo'], $targetUid),
                            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        ]);

                        $feedbackMessage = sprintf('Les privilèges Administrateur de "%s" (#%d) ont été révoqués.', (string) $targetAdmin['pseudo'], $targetUid);
                    }
                }
            } elseif ($action === 'purge_old_logs_rgpd') {
                $purgeStmt = $db->prepare('DELETE FROM LOG_SYSTEME WHERE date_log < DATE_SUB(NOW(), INTERVAL 12 MONTH)');
                $purgeStmt->execute();
                $deletedRows = $purgeStmt->rowCount();

                $logStmt = $db->prepare('
                    INSERT INTO LOG_SYSTEME (id_utilisateur, niveau, action, details, adresse_ip, date_log)
                    VALUES (:uid, \'INFO\', \'RGPD_LOG_PURGE\', :details, :ip, NOW())
                ');
                $logStmt->execute([
                    ':uid'     => $currentUserId,
                    ':details' => sprintf('Purge RGPD des logs système de plus de 12 mois (%d entrées supprimées).', $deletedRows),
                    ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                $feedbackMessage = sprintf('Purge RGPD exécutée : %d entrée(s) antérieure(s) à 12 mois effacée(s).', $deletedRows);
            }
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][SuperAdmin] Erreur action POST : %s', $e->getMessage()));
            $feedbackError = 'Erreur base de données lors de l\'opération Super-Admin.';
        }
    }
}

// 1. Chargement de l'équipe d'administration actuelle
$adminTeam = [];
try {
    $teamStmt = $db->query("
        SELECT id_utilisateur, pseudo, email, role, date_inscription
        FROM UTILISATEUR
        WHERE UPPER(role) IN ('ADMIN', 'SUPER_ADMIN')
        ORDER BY UPPER(role) = 'SUPER_ADMIN' DESC, pseudo ASC
    ");
    if ($teamStmt !== false) {
        $adminTeam = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][SuperAdmin] Erreur chargement équipe admin : %s', $e->getMessage()));
}

// 2. Chargement et filtrage du journal d'audit et de sécurité (LOG_SYSTEME)
$logLevelFilter = strtoupper(Security::validateString($_GET['level'] ?? 'ALL', 1, 15) ?? 'ALL');
$logSearch      = Security::validateString($_GET['q'] ?? '', 0, 100) ?? '';

$auditLogs = [];
try {
    $where  = ['1 = 1'];
    $params = [];

    if (in_array($logLevelFilter, ['INFO', 'WARNING', 'CRITICAL'], true)) {
        $where[]          = 'UPPER(l.niveau) = :lvl';
        $params[':lvl']   = $logLevelFilter;
    }

    if ($logSearch !== '') {
        $where[]          = '(l.action LIKE :q_act ESCAPE \'!\' OR l.details LIKE :q_det ESCAPE \'!\' OR u.pseudo LIKE :q_usr ESCAPE \'!\' OR l.adresse_ip LIKE :q_ip ESCAPE \'!\')';
        $like             = Security::likeContains($logSearch);
        $params[':q_act'] = $like;
        $params[':q_det'] = $like;
        $params[':q_usr'] = $like;
        $params[':q_ip']  = $like;
    }

    $logSql = '
        SELECT 
            l.id_log,
            l.id_utilisateur,
            l.niveau,
            l.action,
            l.details,
            l.adresse_ip,
            l.date_log,
            COALESCE(u.pseudo, \'Système / Anonyme\') AS auteur_pseudo,
            COALESCE(u.role, \'SYSTEM\') AS auteur_role
        FROM LOG_SYSTEME l
        LEFT JOIN UTILISATEUR u ON u.id_utilisateur = l.id_utilisateur
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY l.date_log DESC, l.id_log DESC
        LIMIT 150
    ';

    $lStmt = $db->prepare($logSql);
    $lStmt->execute($params);
    $auditLogs = $lStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][SuperAdmin] Lecture LOG_SYSTEME : %s', $e->getMessage()));
}
?>
<div class="super-admin">
    <header class="super-admin__header">
        <div class="super-admin__heading">
            <p class="super-admin__eyebrow">Niveau d'Accréditation Maximal — Super-Administrateur</p>
            <h1 class="super-admin__title">Gouvernance des Administrateurs &amp; Journal d'Audit Système</h1>
            <p class="super-admin__subtitle">
                Gérez les habilitations de l'équipe de modération Boötes et inspectez l'historique complet des actions sensibles et connexions au panel.
            </p>
        </div>
    </header>

    <?php if ($feedbackMessage !== null): ?>
        <div class="super-admin__alert super-admin__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="super-admin__alert super-admin__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <!-- SECTION 1 : GESTION DES DROITS ADMINISTRATEURS -->
    <section class="super-admin__section super-admin__section--admins" aria-labelledby="admins-mgmt-title">
        <h2 id="admins-mgmt-title" class="super-admin__section-title">
            1. Gestion des droits Administrateurs (<?= count($adminTeam) ?> membres habilités)
        </h2>

        <form action="/admin/super-admin" method="post" class="promote-admin-form">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="action" value="promote_admin">

            <label for="promote-identifier" class="promote-admin-form__label">
                Promouvoir un utilisateur au rôle d'administrateur (saisir son pseudonyme exact ou son adresse e-mail) :
            </label>
            <div class="promote-admin-form__controls">
                <input
                    type="text"
                    id="promote-identifier"
                    name="user_identifier"
                    placeholder="Ex : Moderateur_MC ou mail@domaine.fr"
                    minlength="3"
                    maxlength="100"
                    required
                    class="promote-admin-form__input"
                >
                <button type="submit" class="promote-admin-form__submit">Promouvoir Administrateur</button>
            </div>
        </form>

        <table class="admins-table">
            <caption class="admins-table__caption">Membres disposant des privilèges ADMIN ou SUPER_ADMIN</caption>
            <thead class="admins-table__head">
                <tr class="admins-table__row">
                    <th scope="col" class="admins-table__cell">ID</th>
                    <th scope="col" class="admins-table__cell">Pseudonyme</th>
                    <th scope="col" class="admins-table__cell">Adresse e-mail</th>
                    <th scope="col" class="admins-table__cell">Rôle Actuel</th>
                    <th scope="col" class="admins-table__cell">Inscrit le</th>
                    <th scope="col" class="admins-table__cell">Action d'Habilitation</th>
                </tr>
            </thead>
            <tbody class="admins-table__body">
                <?php foreach ($adminTeam as $adm): ?>
                    <?php
                    $admId     = (int) $adm['id_utilisateur'];
                    $isSuper   = strtoupper((string) $adm['role']) === Auth::ROLE_SUPER_ADMIN;
                    ?>
                    <tr class="admins-table__row">
                        <td class="admins-table__cell">#<?= $admId ?></td>
                        <th scope="row" class="admins-table__cell">
                            <a href="/user/compte?id=<?= $admId ?>" class="admins-table__link">
                                <?= Security::e((string) $adm['pseudo']) ?>
                            </a>
                        </th>
                        <td class="admins-table__cell"><?= Security::e((string) $adm['email']) ?></td>
                        <td class="admins-table__cell">
                            <?= View::badge('role', $adm['role']) ?>
                        </td>
                        <td class="admins-table__cell"><?= Security::e((string) $adm['date_inscription']) ?></td>
                        <td class="admins-table__cell">
                            <?php if ($isSuper || $admId === $currentUserId): ?>
                                <span class="admins-table__protected">Super-Admin protégé</span>
                            <?php else: ?>
                                <form action="/admin/super-admin" method="post" class="admins-table__form">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="revoke_admin">
                                    <input type="hidden" name="admin_user_id" value="<?= $admId ?>">
                                    <button type="submit" class="admins-table__revoke-btn" data-confirm="Révoquer les privilèges administrateur de ce compte ?">
                                        Révoquer les droits Admin
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <!-- SECTION 2 : JOURNAL D'AUDIT ET DE SÉCURITÉ -->
    <section class="super-admin__section super-admin__section--logs" aria-labelledby="audit-logs-title">
        <header class="super-admin__logs-header">
            <div class="super-admin__logs-heading">
                <h2 id="audit-logs-title" class="super-admin__section-title">
                    2. Journal d'Audit et de Sécurité Système
                </h2>
                <p class="super-admin__section-desc">
                    Traçabilité horodatée des promotions, révocations, arrêts forcés JVM, décisions de modération et connexions au panel.
                </p>
            </div>

            <form action="/admin/super-admin" method="post" class="super-admin__purge-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="action" value="purge_old_logs_rgpd">
                <button type="submit" class="super-admin__btn super-admin__btn--purge" data-confirm="Purger définitivement les journaux de plus de 12 mois ?">
                    Purger les logs &gt; 12 mois (Conformité RGPD)
                </button>
            </form>
        </header>

        <form action="/admin/super-admin" method="get" class="audit-filter-form" role="search">
            <div class="audit-filter-form__field">
                <label for="audit-level" class="audit-filter-form__label">Niveau de criticité</label>
                <select id="audit-level" name="level" class="audit-filter-form__select">
                    <option value="ALL" <?= $logLevelFilter === 'ALL' ? 'selected' : '' ?>>Tous les niveaux</option>
                    <option value="INFO" <?= $logLevelFilter === 'INFO' ? 'selected' : '' ?>>INFO (Connexions &amp; Opérations)</option>
                    <option value="WARNING" <?= $logLevelFilter === 'WARNING' ? 'selected' : '' ?>>WARNING (Modération &amp; Signalements)</option>
                    <option value="CRITICAL" <?= $logLevelFilter === 'CRITICAL' ? 'selected' : '' ?>>CRITICAL (Droits Admin &amp; SIGKILL)</option>
                </select>
            </div>

            <div class="audit-filter-form__field">
                <label for="audit-q" class="audit-filter-form__label">Rechercher (action, administrateur, IP, détail)</label>
                <input
                    type="search"
                    id="audit-q"
                    name="q"
                    value="<?= Security::e($logSearch) ?>"
                    placeholder="Ex : PROMOTE_ADMIN, 192.168..."
                    maxlength="100"
                    class="audit-filter-form__input"
                >
            </div>

            <button type="submit" class="audit-filter-form__submit">Filtrer le journal</button>
            <a href="/admin/super-admin" class="audit-filter-form__reset">Réinitialiser</a>
        </form>

        <?php if (empty($auditLogs)): ?>
            <p class="super-admin__empty">Aucune entrée d'audit enregistrée pour les critères sélectionnés.</p>
        <?php else: ?>
            <table class="audit-table">
                <caption class="audit-table__caption">Derniers événements sensibles enregistrés sur Boötes</caption>
                <thead class="audit-table__head">
                    <tr class="audit-table__row">
                        <th scope="col" class="audit-table__cell">ID</th>
                        <th scope="col" class="audit-table__cell">Horodatage</th>
                        <th scope="col" class="audit-table__cell">Niveau</th>
                        <th scope="col" class="audit-table__cell">Acteur</th>
                        <th scope="col" class="audit-table__cell">Code Action</th>
                        <th scope="col" class="audit-table__cell">Détails de l'opération</th>
                        <th scope="col" class="audit-table__cell">Adresse IP</th>
                    </tr>
                </thead>
                <tbody class="audit-table__body">
                    <?php foreach ($auditLogs as $log): ?>
                        <tr class="audit-table__row">
                            <td class="audit-table__cell">#<?= (int) $log['id_log'] ?></td>
                            <td class="audit-table__cell">
                                <time datetime="<?= Security::e((string) $log['date_log']) ?>">
                                    <?= Security::e((string) $log['date_log']) ?>
                                </time>
                            </td>
                            <td class="audit-table__cell">
                                <?= View::badge('niveau_log', $log['niveau']) ?>
                            </td>
                            <th scope="row" class="audit-table__cell">
                                <?= Security::e((string) $log['auteur_pseudo']) ?>
                                <small>(<?= Security::e((string) $log['auteur_role']) ?>)</small>
                            </th>
                            <td class="audit-table__cell"><code><?= Security::e((string) $log['action']) ?></code></td>
                            <td class="audit-table__cell"><?= Security::e((string) $log['details']) ?></td>
                            <td class="audit-table__cell"><code><?= Security::e((string) $log['adresse_ip']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>