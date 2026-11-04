<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/ServerManager.php';

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Security;
use Bootes\Src\ServerManager;
use Bootes\Src\View;

$db            = $db ?? Database::getInstance();
$serverManager = new ServerManager($db);

$serverId      = isset($activeServerId) && is_int($activeServerId)
    ? $activeServerId
    : Security::validatePositiveInt($_GET['id'] ?? $_POST['server_id'] ?? null);
$currentUserId = Auth::getUserId();
$accessLevel   = $serverId !== null && $currentUserId !== null
    ? Auth::getServerAccessLevel($currentUserId, $serverId, $db)
    : null;

if ($serverId === null || $currentUserId === null || $accessLevel === null) {
    http_response_code(403);
    ?>
    <section class="server-console server-console--forbidden">
        <h1 class="server-console__title">Accès refusé à la console</h1>
        <a href="/user/compte" class="server-console__btn">Retour</a>
    </section>
    <?php
    return;
}

$server = $serverManager->getServerById($serverId);
if ($server === null) {
    ?>
    <section class="server-console server-console--not-found">
        <h1 class="server-console__title">Serveur introuvable</h1>
    </section>
    <?php
    return;
}

$csrfToken       = Security::generateCsrfToken();
$feedbackMessage = null;
$feedbackError   = null;
$senderPseudo    = (string) ($_SESSION['user']['pseudo'] ?? 'Operateur');
$canSendCommands = Auth::serverLevelAllows($accessLevel, Auth::SERVER_PERM_CONSOLE);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 30) ?? '';

    if (!Security::verifyCsrfToken($postCsrf)) {
        $feedbackError = 'Jeton CSRF invalide. Veuillez réessayer.';
    } elseif ($action === 'send_command' && !$canSendCommands) {
        $feedbackError = Auth::serverPermissionError($accessLevel);
    } elseif ($action === 'send_command') {
        $rawCommand = (string) ($_POST['command'] ?? '');
        $res        = $serverManager->sendConsoleCommand($serverId, $rawCommand, $senderPseudo);
        if ($res['success']) {
            $feedbackMessage = $res['message'];
            $server          = $serverManager->getServerById($serverId) ?? $server;
        } else {
            $feedbackError = $res['message'];
        }
    }
}

$isOnline = $server['statut'] === ServerManager::STATUS_ONLINE;
$logLines = $serverManager->readLatestLog($serverId, 120);
?>
<section class="server-console" aria-labelledby="console-title">
    <header class="server-console__header">
        <div class="server-console__heading">
            <h1 id="console-title" class="server-console__title">
                Console
            </h1>
            <p class="server-console__subtitle">
                Statut : <strong><?= Security::e(View::label('statut_serveur', $server['statut'])) ?></strong>
                &bull; Flux direct depuis <code>logs/latest.log</code>
            </p>
        </div>
        <div class="server-console__actions">
            <a href="/serveur/console?id=<?= $serverId ?>" class="server-console__refresh-btn">Actualiser le flux</a>
            <a href="/serveur/dashboard?id=<?= $serverId ?>" class="server-console__back-btn">Tableau de bord</a>
        </div>
    </header>

    <?php if ($feedbackMessage !== null): ?>
        <div class="server-console__alert server-console__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="server-console__alert server-console__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <div class="terminal" role="log" aria-live="polite" aria-label="Sortie standard du serveur Minecraft">
        <div class="terminal__header">
            <span class="terminal__prompt">jvm@bootes-mint:~/instances/server_<?= $serverId ?>$ tail -n 120 logs/latest.log</span>
        </div>
        <pre class="terminal__output"><code class="terminal__code"><?php
            foreach ($logLines as $line) {
                echo View::logLine($line) . "\n";
            }
        ?></code></pre>
    </div>

    <form action="/serveur/console?id=<?= $serverId ?>" method="post" class="console-form">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
        <input type="hidden" name="server_id" value="<?= $serverId ?>">
        <input type="hidden" name="action" value="send_command">

        <label for="mc-command-input" class="console-form__label">
            Envoyer une commande serveur (sans le slash initial, ex: <code>op Pseudo</code>, <code>gamemode creative Pseudo</code>, <code>save-all</code>, <code>stop</code>) :
        </label>
        <div class="console-form__input-group">
            <span class="console-form__prefix" aria-hidden="true">/</span>
            <input
                type="text"
                id="mc-command-input"
                name="command"
                placeholder="<?= !$canSendCommands ? "Votre niveau d'accès ne permet pas d'envoyer de commandes" : ($isOnline ? 'say Bonjour depuis le panel Boötes !' : 'Démarrez le serveur pour envoyer des commandes...') ?>"
                maxlength="200"
                required
                autocomplete="off"
                class="console-form__input"
                <?= !$isOnline || !$canSendCommands ? 'disabled' : '' ?>
            >
            <button type="submit" class="console-form__submit" <?= !$isOnline || !$canSendCommands ? 'disabled' : '' ?>>
                Exécuter
            </button>
        </div>
    </form>

    <aside class="server-console__shortcuts" aria-label="Aide-mémoire des commandes Minecraft">
        <h2 class="server-console__shortcuts-title">Commandes courantes</h2>
        <ul class="server-console__shortcut-list">
            <li class="server-console__shortcut-item"><code>whitelist on</code> / <code>whitelist add &lt;joueur&gt;</code> — Activer et gérer la liste blanche</li>
            <li class="server-console__shortcut-item"><code>op &lt;joueur&gt;</code> / <code>deop &lt;joueur&gt;</code> — Octroyer ou retirer les droits opérateur</li>
            <li class="server-console__shortcut-item"><code>gamemode survival|creative|spectator &lt;joueur&gt;</code> — Changer le mode de jeu</li>
            <li class="server-console__shortcut-item"><code>save-all</code> — Forcer l'écriture immédiate du monde sur le disque</li>
            <li class="server-console__shortcut-item"><code>stop</code> — Arrêter proprement le serveur</li>
        </ul>
    </aside>
</section>