<?php

declare(strict_types=1);

$db = Database::getInstance();

// Gestion de la déconnexion
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    Auth::logout();
    Auth::initSession();
    addFlashNotification('info', 'Vous avez été déconnecté avec succès.');
    header('Location: /home');
    exit;
}

$activeTab = 'login';

// Traitement des formulaires POST (Connexion ou Inscription)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? null;

    if (!Security::verifyCsrfToken(is_string($submittedToken) ? $submittedToken : null)) {
        addFlashNotification('error', 'Jeton de sécurité CSRF invalide. Veuillez réessayer.');
    } else {
        $formAction = isset($_POST['form_action']) ? (string) $_POST['form_action'] : 'login';

        if ($formAction === 'register') {
            $activeTab = 'register';
            $username  = isset($_POST['username']) ? (string) $_POST['username'] : '';
            $email     = isset($_POST['email']) ? (string) $_POST['email'] : '';
            $password  = isset($_POST['password']) ? (string) $_POST['password'] : '';
            $confirm   = isset($_POST['password_confirm']) ? (string) $_POST['password_confirm'] : '';

            if ($password !== $confirm) {
                addFlashNotification('error', 'Les deux mots de passe ne correspondent pas.');
            } else {
                // S'assure que le type de compte Standard (1) existe dans TYPE_DE_COMPTE
                $db->exec("INSERT IGNORE INTO TYPE_DE_COMPTE (TDC_ID, TDC_NOM_TYPE_COMPTE) VALUES (1, 'Standard'), (2, 'Premium'), (3, 'Administrateur')");

                $result = Auth::register($username, $email, $password, $db, Auth::ROLE_STANDARD);
                if ($result['success']) {
                    addFlashNotification('success', $result['message']);
                    $activeTab = 'login';
                } else {
                    addFlashNotification('error', $result['message']);
                }
            }
        } elseif ($formAction === 'login') {
            $identifier = isset($_POST['identifier']) ? (string) $_POST['identifier'] : '';
            $password   = isset($_POST['password']) ? (string) $_POST['password'] : '';

            $result = Auth::login($identifier, $password, $db);
            if ($result['success']) {
                addFlashNotification('success', 'Bienvenue dans votre espace Boötes.');
                if (!empty($result['is_admin'])) {
                    header('Location: /admin-dashboard');
                    exit;
                }
                header('Location: /dashboard');
                exit;
            } else {
                addFlashNotification('error', $result['message']);
            }
        }
    }
}

$csrfToken = Security::generateCsrfToken();
?>

<main class="app-main" style="display: flex; align-items: center; justify-content: center; min-height: calc(100vh - 180px);">
    <div class="bento-card card-cyan-accent" style="max-width: 480px; width: 100%;">
        <!-- Onglets de bascule Connexion / Inscription -->
        <div style="display: flex; gap: 0.5rem; margin-bottom: 1.75rem; border-bottom: 1px solid var(--border-subtle); padding-bottom: 1rem;">
            <button type="button" id="tab-btn-login" class="btn <?= $activeTab === 'login' ? 'btn-start' : 'btn-cyan' ?>" style="flex: 1;" onclick="switchAuthTab('login')">
                Connexion
            </button>
            <button type="button" id="tab-btn-register" class="btn <?= $activeTab === 'register' ? 'btn-start' : 'btn-cyan' ?>" style="flex: 1;" onclick="switchAuthTab('register')">
                Inscription
            </button>
        </div>

        <!-- FORMULAIRE DE CONNEXION -->
        <form id="form-login" method="POST" action="/auth" style="display: <?= $activeTab === 'login' ? 'block' : 'none' ?>;">
            <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">
            <input type="hidden" name="form_action" value="login">

            <h2 class="card-title" style="margin-bottom: 1.25rem; font-size: 1rem;">Accès au terminal</h2>

            <div class="form-group">
                <label class="form-label" for="login-identifier">Pseudo ou Adresse e-mail</label>
                <input type="text" id="login-identifier" name="identifier" class="form-control" required autocomplete="username" placeholder="ex: Arcturus">
            </div>

            <div class="form-group">
                <label class="form-label" for="login-password">Mot de passe</label>
                <input type="password" id="login-password" name="password" class="form-control" required autocomplete="current-password" placeholder="••••••••">
            </div>

            <button type="submit" class="btn btn-start" style="width: 100%; margin-top: 0.5rem;">
                Se connecter
            </button>
        </form>

        <!-- FORMULAIRE D'INSCRIPTION -->
        <form id="form-register" method="POST" action="/auth" style="display: <?= $activeTab === 'register' ? 'block' : 'none' ?>;">
            <input type="hidden" name="csrf_token" value="<?= Security::sanitizeString($csrfToken) ?>">
            <input type="hidden" name="form_action" value="register">

            <h2 class="card-title" style="margin-bottom: 1.25rem; font-size: 1rem;">Créer un compte pilote</h2>

            <div class="form-group">
                <label class="form-label" for="reg-username">Pseudo (3 à 24 caractères alphanumériques)</label>
                <input type="text" id="reg-username" name="username" class="form-control" required maxlength="24" pattern="[a-zA-Z0-9_-]{3,24}" placeholder="MonPseudo_MC">
            </div>

            <div class="form-group">
                <label class="form-label" for="reg-email">Adresse e-mail</label>
                <input type="email" id="reg-email" name="email" class="form-control" required maxlength="100" placeholder="pilote@bootes.fr">
            </div>

            <div class="form-group">
                <label class="form-label" for="reg-password">Mot de passe (8 caractères min.)</label>
                <input type="password" id="reg-password" name="password" class="form-control" required minlength="8" autocomplete="new-password" placeholder="••••••••">
            </div>

            <div class="form-group">
                <label class="form-label" for="reg-confirm">Confirmer le mot de passe</label>
                <input type="password" id="reg-confirm" name="password_confirm" class="form-control" required minlength="8" autocomplete="new-password" placeholder="••••••••">
            </div>

            <button type="submit" class="btn btn-start" style="width: 100%; margin-top: 0.5rem;">
                S'inscrire (Chiffrement Argon2id)
            </button>
        </form>
    </div>
</main>

<script>
function switchAuthTab(tab) {
    const loginForm = document.getElementById('form-login');
    const registerForm = document.getElementById('form-register');
    const loginBtn = document.getElementById('tab-btn-login');
    const registerBtn = document.getElementById('tab-btn-register');

    if (tab === 'register') {
        loginForm.style.display = 'none';
        registerForm.style.display = 'block';
        registerBtn.className = 'btn btn-start';
        loginBtn.className = 'btn btn-cyan';
    } else {
        loginForm.style.display = 'block';
        registerForm.style.display = 'none';
        loginBtn.className = 'btn btn-start';
        registerBtn.className = 'btn btn-cyan';
    }
}
</script>