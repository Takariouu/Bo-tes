<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Security;
use Bootes\Src\ServerManager;
use Bootes\Src\View;

// L'accès au serveur (propriétaire, co-admin ou admin) est vérifié par index.php avant d'arriver ici.
$db            = $db ?? Database::getInstance();
$serverManager = new ServerManager($db);
$serverId      = (int) ($activeServerId ?? 0);
$server        = $serverManager->getServerById($serverId);
$canManage     = Auth::serverLevelAllows($serverAccessLevel ?? null, Auth::SERVER_PERM_FULL);

if ($server === null) {
    ?>
    <section class="server-players server-players--not-found">
        <h1 class="server-players__title">Serveur introuvable</h1>
        <a href="/user/compte#section-serveurs" class="server-players__btn">Retour à mes serveurs</a>
    </section>
    <?php
    return;
}

// Sessions de jeu suivies (CONNEXION_JOUEUR) : joueurs connectés et historique récent
$onlinePlayers  = [];
$recentSessions = [];
try {
    $onlineStmt = $db->prepare('
        SELECT pseudo_mc, date_connexion
        FROM CONNEXION_JOUEUR
        WHERE id_serveur = :id AND date_deconnexion IS NULL
        ORDER BY date_connexion ASC
    ');
    $onlineStmt->execute([':id' => $serverId]);
    $onlinePlayers = $onlineStmt->fetchAll(PDO::FETCH_ASSOC);

    $historyStmt = $db->prepare('
        SELECT pseudo_mc, date_connexion, date_deconnexion, motif_deconnexion
        FROM CONNEXION_JOUEUR
        WHERE id_serveur = :id
        ORDER BY date_connexion DESC
        LIMIT 20
    ');
    $historyStmt->execute([':id' => $serverId]);
    $recentSessions = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log(sprintf('[Boötes][Joueurs] Erreur lecture des connexions : %s', $e->getMessage()));
}

$lists = [ServerManager::LIST_WHITELIST => [], ServerManager::LIST_OP => [], ServerManager::LIST_BLACKLIST => []];
foreach ($serverManager->getListedPlayers($serverId) as $entry) {
    $lists[strtoupper((string) $entry['type_liste'])][] = $entry;
}

$listMeta = [
    ServerManager::LIST_WHITELIST => ['icon' => 'shield-check', 'empty' => 'Personne sur la liste blanche.'],
    ServerManager::LIST_OP        => ['icon' => 'crown',        'empty' => 'Aucun opérateur.'],
    ServerManager::LIST_BLACKLIST => ['icon' => 'ban',          'empty' => 'Aucun joueur banni.'],
];

/** Durée lisible d'une session (« 1 h 25 », « 12 min »). */
$sessionDuration = static function (string $start, ?string $end): string {
    $seconds = max(0, (int) (($end !== null && $end !== '' ? strtotime($end) : time()) - strtotime($start)));
    $hours   = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    return $hours > 0 ? sprintf('%d h %02d', $hours, $minutes) : sprintf('%d min', max(1, $minutes));
};
?>
<div class="server-players">
    <header class="server-players__header">
        <h1 class="server-players__title">Joueurs</h1>
        <p class="server-players__subtitle">
            Qui joue en ce moment, qui a accès au monde et qui s'est connecté récemment.
        </p>
    </header>

    <dl class="server-players__stats">
        <div>
            <dt>Connectés (annoncés par le serveur)</dt>
            <dd><?= (int) $server['joueurs_connectes'] ?> / <?= (int) $server['slots_max'] ?></dd>
        </div>
        <div>
            <dt>Sessions suivies en cours</dt>
            <dd><?= count($onlinePlayers) ?></dd>
        </div>
        <div>
            <dt>Liste blanche</dt>
            <dd><?= count($lists[ServerManager::LIST_WHITELIST]) ?></dd>
        </div>
        <div>
            <dt>Joueurs bannis</dt>
            <dd><?= count($lists[ServerManager::LIST_BLACKLIST]) ?></dd>
        </div>
    </dl>

    <div class="server-players__grid">
        <section class="server-players__panel" aria-labelledby="online-title">
            <h2 id="online-title" class="server-players__panel-title"><?= View::icon('activity') ?> En ligne maintenant</h2>
            <?php if (empty($onlinePlayers)): ?>
                <p class="server-players__empty">Aucune session en cours n'est suivie pour ce serveur.</p>
            <?php else: ?>
                <ul class="player-list">
                    <?php foreach ($onlinePlayers as $player): ?>
                        <li class="player-list__item">
                            <span class="avatar" aria-hidden="true"><?= Security::e(View::initials((string) $player['pseudo_mc'])) ?></span>
                            <strong class="player-list__name"><?= Security::e((string) $player['pseudo_mc']) ?></strong>
                            <span class="player-list__meta">depuis <?= Security::e($sessionDuration((string) $player['date_connexion'], null)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="server-players__panel" aria-labelledby="lists-title">
            <header class="server-players__panel-header">
                <h2 id="lists-title" class="server-players__panel-title"><?= View::icon('list-checks') ?> Listes du serveur</h2>
                <?php if ($canManage): ?>
                    <a href="/serveur/acces-admin?id=<?= $serverId ?>" class="server-players__btn"><?= View::icon('pencil') ?> Gérer les listes</a>
                <?php endif; ?>
            </header>
            <?php foreach ($lists as $listType => $entries): ?>
                <h3 class="server-players__list-title"><?= View::icon($listMeta[$listType]['icon']) ?> <?= Security::e(View::label('liste', $listType)) ?></h3>
                <?php if (empty($entries)): ?>
                    <p class="server-players__empty"><?= Security::e($listMeta[$listType]['empty']) ?></p>
                <?php else: ?>
                    <ul class="player-chips">
                        <?php foreach ($entries as $entry): ?>
                            <li class="player-chips__item" title="<?= Security::e(trim('Ajouté le ' . View::date((string) $entry['date_ajout']) . ' ' . (string) ($entry['raison'] ?? ''))) ?>">
                                <span class="avatar" aria-hidden="true"><?= Security::e(View::initials((string) $entry['pseudo_mc'])) ?></span>
                                <?= Security::e((string) $entry['pseudo_mc']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    </div>

    <section class="server-players__panel" aria-labelledby="history-title">
        <h2 id="history-title" class="server-players__panel-title"><?= View::icon('history') ?> Dernières connexions</h2>
        <?php if (empty($recentSessions)): ?>
            <p class="server-players__empty">Aucune connexion enregistrée pour le moment.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="sessions-table">
                    <caption class="visually-hidden">Vingt dernières sessions de jeu</caption>
                    <thead>
                        <tr>
                            <th scope="col">Joueur</th>
                            <th scope="col">Connexion</th>
                            <th scope="col">Durée</th>
                            <th scope="col">Fin de session</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSessions as $session): ?>
                            <?php $isOpen = $session['date_deconnexion'] === null || $session['date_deconnexion'] === ''; ?>
                            <tr>
                                <th scope="row"><?= Security::e((string) $session['pseudo_mc']) ?></th>
                                <td><?= Security::e(View::date((string) $session['date_connexion'], true)) ?></td>
                                <td><?= Security::e($sessionDuration((string) $session['date_connexion'], $isOpen ? null : (string) $session['date_deconnexion'])) ?></td>
                                <td>
                                    <?php if ($isOpen): ?>
                                        <span class="badge badge--online badge--dot">En jeu</span>
                                    <?php else: ?>
                                        <?= Security::e((string) ($session['motif_deconnexion'] ?? 'Déconnexion')) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
