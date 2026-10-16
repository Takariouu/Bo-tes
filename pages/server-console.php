<?php

declare(strict_types=1);

$db       = Database::getInstance();
$userId   = (string) Auth::getCurrentUserId();
$serverId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($serverId <= 0 || !Auth::hasServerAccess($userId, $serverId, $db)) {
    if (isset($_GET['ajax'])) {
        ob_clean();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
        exit;
    }
    addFlashNotification('error', 'Accès refusé à la console de ce serveur.');
    header('Location: /dashboard');
    exit;
}

// Endpoint interne AJAX : Récupération des 100 dernières lignes de logs en JSON
if (isset($_GET['ajax']) && $_GET['ajax'] === 'logs') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $isOnline = MinecraftServerManager::isProcessRunning($serverId);
    $logs     = MinecraftServerManager::getLatestLogs($serverId, 100);

    echo json_encode([
        'success'   => true,
        'is_online' => $isOnline,
        'logs'      => $logs,
        'timestamp' => date('H:i:s'),
    ]);
    exit;
}

// Traitement de l'envoi d'une commande console (POST classique ou Fetch AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_command') {
    $isAjax         = isset($_GET['ajax']) && $_GET['ajax'] === 'command';
    $submittedToken = $_POST['csrf_token'] ?? null;

    if (!Security::verifyCsrfToken(is_string($submittedToken) ? $submittedToken : null)) {
        if ($isAjax) {
            ob_clean();
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Jeton CSRF invalide.']);
            exit;
        }
        addFlashNotification('error', 'Jeton de sécurité CSRF invalide.');
    } else {
        $rawCommand = isset($_POST['command']) ? (string) $_POST['command'] : '';

        try {
            $cleanCommand = Security::sanitizeConsoleCommand($rawCommand);
            $serverDir    = MinecraftServerManager::getServerDirectory($serverId);
            $logDir       = $serverDir . '/logs';

            if (!is_dir($logDir)) {
                @mkdir($logDir, 0750, true);
            }

            // Journalisation dans latest.log et dépôt dans le tampon d'entrée console.in
            $logEntry = sprintf("[%s] [Boötes WebConsole/INFO]: > /%s\n", date('H:i:s'), $cleanCommand);
            @file_put_contents($logDir . '/latest.log', $logEntry, FILE_APPEND | LOCK_EX);
            @file_put_contents($serverDir . '/console.in', $cleanCommand . "\n", FILE_APPEND | LOCK_EX);

            // Si l'utilisateur tape "stop", on déclenche l'arrêt propre du serveur
            if (strtolower($cleanCommand) === 'stop') {
                MinecraftServerManager::stopServer($serverId, $db);
            }

            if ($isAjax) {
                ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => sprintf('Commande "/%s" transmise au serveur.', $cleanCommand),
                ]);
                exit;
            }

            addFlashNotification('success', sprintf('Commande "/%s" transmise.', $cleanCommand));
        } catch (InvalidArgumentException $e) {
            if ($isAjax) {
                ob_clean();
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            addFlashNotification('error', $e->getMessage());
        }
    }

    header('Location: /server-console?id=' . $serverId);
    exit;
}

// Récupération des informations du serveur
$stmt = $db->prepare('SELECT SVR_ID, SVR_NOM, SVR_ADDRESSE, SVR_PORT, ETA_ID FROM SERVEUR WHERE SVR_ID = :id LIMIT 1');
$stmt->execute([':id' => $serverId]);
$server = $stmt->fetch();

if (!$server) {
    addFlashNotification('error', 'Serveur introuvable.');
    header('Location: /dashboard');
    exit;
}

$isOnline    = MinecraftServerManager::isProcessRunning($serverId);
$initialLogs = MinecraftServerManager::getLatestLogs($serverId, 100);
$csrfToken   = Security::generateCsrfToken();

require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<main class="app-main">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <h1 style="font-size: 1.4rem;">CONSOLE — <?= Security::sanitizeString((string) $server['SVR_NOM']) ?></h1>
                <span id="console-status-badge" class="badge <?= $isOnline ? 'badge-online' : 'badge-offline' ?>">
                    <?= $isOnline ? '● En ligne' : '○ Éteint' ?>
                </span>
            </div>
            <p class="mono" style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;">
                Flux direct : <code>/servers/<?= $serverId ?>/logs/latest.log</code> (100 dernières lignes)
            </p>
        </div>

        <div style="display: flex; gap: 0.75rem;">
            <button type="button" class="btn btn-cyan" onclick="fetchLatestLogs(true)">
                ↻ Rafraîchir maintenant
            </button>
            <a href="/server-control?id=<?= $serverId ?>" class="btn btn-stop">
                ← Retour au serveur
            </a>
        </div>
    </div>

    <!-- FENÊTRE TERMINAL LINUX -->
    <div class="terminal-window">
        <div class="terminal-topbar">
            <span>bootes@linux-mint:~/servers/<?= $serverId ?>$ tail -n 100 logs/latest.log</span>
            <span id="terminal-last-sync">Synchronisé à <?= date('H:i:s') ?></span>
        </div>

        <div id="terminal-log-output" class="terminal-body" aria-live="polite">
            <?php foreach ($initialLogs as $line): ?>
                <?php
                $cssClass = 'log-info';
                if (stripos($line, 'WARN') !== false) {
                    $cssClass = 'log-warn';
                } elseif (stripos($line, 'ERROR') !== false || stripos($line, 'Exception') !== false || stripos($line, 'CRASH') !== false) {
                    $cssClass = 'log-error';
                } elseif (stripos($line, 'WebConsole') !== false) {
                    $cssClass = 'log-cmd';
                }
                ?>
                <div class="log-line <?= $cssClass ?>"><?= Security::sanitizeString($line) ?></div>
            <?php endforeach; ?>
        </div>

        <!-- BARRE D'ENVOI DE COMMANDE -->
        <form id="console-command-form" method="POST" action="/server-console?id=<?= $serverId ?>" class="terminal-command-bar" style="display: flex; border-top: 1px solid var(--border-subtle); background: rgba(10, 10, 20, 0.95);">
            <input type="hidden" name="csrf_token" id="csrf_token_input" value="<?= Security::sanitizeString($csrfToken) ?>">
            <input type="hidden" name="action" value="send_command">
            <span class="mono" style="padding: 0.95rem 0 0.95rem 1.25rem; color: var(--accent-cyan); font-weight: 700;">/</span>
            <input
                type="text"
                id="console-command-input"
                name="command"
                placeholder="Tapez une commande Minecraft (ex: say Bonjour, list, whitelist add Pseudo, stop)..."
                maxlength="255"
                autocomplete="off"
                required
                style="flex: 1; background: transparent; border: none; padding: 0.95rem 0.75rem; color: var(--text-primary); font-family: var(--font-mono); font-size: 0.9rem; outline: none;"
            >
            <button type="submit" class="btn btn-start" style="border-radius: 0; padding: 0 1.75rem;">
                Envoyer
            </button>
        </form>
    </div>
</main>

<script>
const serverId = <?= $serverId ?>;
const terminalBody = document.getElementById('terminal-log-output');
const lastSyncLabel = document.getElementById('terminal-last-sync');
const statusBadge = document.getElementById('console-status-badge');
const commandForm = document.getElementById('console-command-form');
const commandInput = document.getElementById('console-command-input');
const csrfInput = document.getElementById('csrf_token_input');

function scrollToBottom() {
    terminalBody.scrollTop = terminalBody.scrollHeight;
}
scrollToBottom();

function classifyLogLine(line) {
    const upper = line.toUpperCase();
    if (upper.includes('ERROR') || upper.includes('EXCEPTION') || upper.includes('CRASH')) {
        return 'log-line log-error';
    }
    if (upper.includes('WARN')) {
        return 'log-line log-warn';
    }
    if (upper.includes('WEBCONSOLE')) {
        return 'log-line log-cmd';
    }
    return 'log-line log-info';
}

function renderLogs(lines) {
    const wasAtBottom = (terminalBody.scrollHeight - terminalBody.scrollTop - terminalBody.clientHeight) < 60;
    terminalBody.innerHTML = '';

    lines.forEach((line) => {
        const div = document.createElement('div');
        div.className = classifyLogLine(line);
        div.textContent = line;
        terminalBody.appendChild(div);
    });

    if (wasAtBottom) {
        scrollToBottom();
    }
}

async function fetchLatestLogs(manual = false) {
    try {
        const response = await fetch(`/server-console?id=${serverId}&ajax=logs`, {
            headers: { 'Accept': 'application/json' }
        });
        if (!response.ok) return;

        const data = await response.json();
        if (data.success && Array.isArray(data.logs)) {
            renderLogs(data.logs);
            lastSyncLabel.textContent = 'Synchronisé à ' + data.timestamp;

            if (data.is_online) {
                statusBadge.className = 'badge badge-online';
                statusBadge.textContent = '● En ligne';
            } else {
                statusBadge.className = 'badge badge-offline';
                statusBadge.textContent = '○ Éteint';
            }

            if (manual && window.BootesNotify) {
                window.BootesNotify.info('Journal de console actualisé.');
            }
        }
    } catch (err) {
        console.error('Erreur de rafraîchissement des logs :', err);
    }
}

commandForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const cmd = commandInput.value.trim();
    if (!cmd) return;

    const formData = new FormData();
    formData.append('csrf_token', csrfInput.value);
    formData.append('action', 'send_command');
    formData.append('command', cmd);

    try {
        const response = await fetch(`/server-console?id=${serverId}&ajax=command`, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            commandInput.value = '';
            if (window.BootesNotify) window.BootesNotify.success(data.message);
            fetchLatestLogs(false);
        } else if (window.BootesNotify) {
            window.BootesNotify.error(data.message || 'Erreur lors de l\'envoi de la commande.');
        }
    } catch (err) {
        if (window.BootesNotify) window.BootesNotify.error('Erreur réseau avec le serveur Boötes.');
    }
});

// Rafraîchissement automatique toutes les 3 secondes
setInterval(() => fetchLatestLogs(false), 3000);
</script>