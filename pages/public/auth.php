<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Http;
use Bootes\Src\RateLimiter;
use Bootes\Src\Security;

$db = $db ?? Database::getInstance();

// Anti brute-force : 5 échecs par compte et par adresse IP, 20 par adresse IP, sur 15 minutes.
$loginMaxPerAccount = 5;
$loginMaxPerIp      = 20;
$loginLockSeconds   = 900;
$registerMaxPerIp   = 5;
$registerWindow     = 3600;

$mode = Security::validateString($_GET['mode'] ?? 'login', 1, 15) ?? 'login';
if (!in_array($mode, ['login', 'register'], true)) {
    $mode = 'login';
}

$errors         = [];
$successMessage = null;
$rateLimiter    = RateLimiter::default();
$clientIp       = (string) ($_SERVER['REMOTE_ADDR'] ?? 'inconnue');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action         = Security::validateString($_POST['action'] ?? '', 1, 20) ?? '';

    if (!Security::verifyCsrfToken($submittedToken)) {
        $errors[] = 'La session du formulaire a expiré ou le jeton CSRF est invalide. Veuillez réessayer.';
    } else {
        if ($action === 'logout') {
            Auth::logout();
            $successMessage = 'Votre session a été fermée en toute sécurité.';
            $mode = 'login';
        } elseif ($action === 'login') {
            $mode       = 'login';
            $identifier = Security::validateString($_POST['identifier'] ?? '', 3, 120) ?? '';
            $password   = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
            $accountKey = 'login:account:' . mb_strtolower($identifier, 'UTF-8') . '|' . $clientIp;
            $ipKey      = 'login:ip:' . $clientIp;

            if ($identifier === '' || $password === '') {
                $errors[] = 'Veuillez renseigner votre identifiant (pseudo ou e-mail) ainsi que votre mot de passe.';
            } elseif (
                $rateLimiter->tooManyAttempts($accountKey, $loginMaxPerAccount) ||
                $rateLimiter->tooManyAttempts($ipKey, $loginMaxPerIp)
            ) {
                $waitMinutes = (int) ceil(max($rateLimiter->availableIn($accountKey), $rateLimiter->availableIn($ipKey)) / 60);
                $errors[]    = sprintf('Trop de tentatives de connexion. Réessayez dans %d minute(s).', max(1, $waitMinutes));
            } elseif (Auth::login($identifier, $password, $db)) {
                $rateLimiter->clear($accountKey);
                Http::redirect(Auth::isAdmin() ? '/admin/dashboard' : '/user/compte');
            } else {
                $rateLimiter->hit($accountKey, $loginLockSeconds);
                $rateLimiter->hit($ipKey, $loginLockSeconds);
                $errors[] = 'Identifiants incorrects ou compte suspendu par la modération.';
            }
        } elseif ($action === 'register') {
            $mode            = 'register';
            $pseudo          = Security::validateString($_POST['pseudo'] ?? '', 3, 32) ?? '';
            $email           = trim((string) ($_POST['email'] ?? ''));
            $password        = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
            $passwordConfirm = isset($_POST['password_confirm']) && is_string($_POST['password_confirm']) ? $_POST['password_confirm'] : '';
            $acceptCgu       = isset($_POST['accept_cgu']) && $_POST['accept_cgu'] === '1';

            if ($pseudo === '' || !preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $pseudo)) {
                $errors[] = 'Le pseudo doit contenir entre 3 et 32 caractères alphanumériques (tirets et underscores autorisés).';
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'Veuillez saisir une adresse e-mail valide.';
            }

            if (Auth::isReservedIdentity($pseudo, $email)) {
                $errors[] = 'Ce pseudo ou cette adresse e-mail est réservé(e) par la plateforme.';
            }

            if (!Auth::isAcceptablePassword($password)) {
                $errors[] = 'Le mot de passe doit comporter au moins 10 caractères.';
            }

            if (!hash_equals($password, $passwordConfirm)) {
                $errors[] = 'La confirmation du mot de passe ne correspond pas.';
            }

            if (!$acceptCgu) {
                $errors[] = 'Vous devez accepter les Conditions Générales d\'Utilisation et la politique RGPD pour créer un compte.';
            }

            $registerKey = 'register:ip:' . $clientIp;
            if (empty($errors) && $rateLimiter->tooManyAttempts($registerKey, $registerMaxPerIp)) {
                $errors[] = 'Trop de créations de compte depuis cette adresse. Réessayez plus tard.';
            }

            if (empty($errors)) {
                try {
                    if (Auth::register($pseudo, $email, $password, $db)) {
                        $rateLimiter->hit($registerKey, $registerWindow);
                        Auth::login($email, $password, $db);
                        Http::redirect('/user/compte', 'Bienvenue sur Boötes ! Votre compte a été créé.');
                    } else {
                        $errors[] = 'Ce pseudo ou cette adresse e-mail est déjà utilisé sur Boötes.';
                    }
                } catch (PDOException $e) {
                    error_log(sprintf('[Boötes][Auth] Erreur inscription : %s', $e->getMessage()));
                    $errors[] = 'Une erreur technique est survenue lors de la création du compte.';
                }
            }
        }
    }
}

$csrfToken = Security::generateCsrfToken();
?>
<section class="auth" aria-labelledby="auth-main-title">
    <div class="auth__container">
        <header class="auth__header">
            <h1 id="auth-main-title" class="auth__title">Portail d'authentification Boötes</h1>
            <p class="auth__subtitle">Accédez à vos serveurs Minecraft ou rejoignez l'infrastructure.</p>
        </header>

        <?php if ($successMessage !== null): ?>
            <div class="auth__alert auth__alert--success" role="status">
                <p class="auth__alert-text"><?= Security::e($successMessage) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="auth__alert auth__alert--error" role="alert">
                <h2 class="auth__alert-title">Impossible de valider le formulaire :</h2>
                <ul class="auth__error-list">
                    <?php foreach ($errors as $error): ?>
                        <li class="auth__error-item"><?= Security::e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (Auth::isLogged()): ?>
            <?php $targetDashboard = Auth::isAdmin() ? '/admin/dashboard' : '/user/compte'; ?>
            <article class="auth__card auth__card--logged">
                <h2 class="auth__card-title">Vous êtes déjà connecté</h2>
                <p class="auth__card-text">
                    Connecté en tant que <strong><?= Security::e((string) ($_SESSION['user']['pseudo'] ?? '')) ?></strong>
                    (Rôle : <?= Security::e(Auth::getRole()) ?>).
                </p>
                <div class="auth__actions">
                    <a href="<?= Security::e($targetDashboard) ?>" class="auth__btn auth__btn--primary">Accéder à mon tableau de bord</a>
                    <form action="/auth" method="post" class="auth__logout-form">
                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="auth__btn auth__btn--danger">Se déconnecter</button>
                    </form>
                </div>
            </article>
        <?php else: ?>
            <nav class="auth__tabs" aria-label="Choix du mode d'authentification">
                <a href="/auth?mode=login" class="auth__tab <?= $mode === 'login' ? 'auth__tab--active' : '' ?>">Connexion</a>
                <a href="/auth?mode=register" class="auth__tab <?= $mode === 'register' ? 'auth__tab--active' : '' ?>">Inscription</a>
            </nav>

            <div class="auth__panels">
                <?php if ($mode === 'login'): ?>
                    <article class="auth__panel auth__panel--login" aria-labelledby="login-heading">
                        <h2 id="login-heading" class="auth__panel-title">Connexion à votre compte</h2>
                        <form action="/auth?mode=login" method="post" class="auth__form" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                            <input type="hidden" name="action" value="login">

                            <div class="auth__field">
                                <label for="login-identifier" class="auth__label">Pseudo ou adresse e-mail</label>
                                <input
                                    type="text"
                                    id="login-identifier"
                                    name="identifier"
                                    value="<?= Security::e((string) ($_POST['identifier'] ?? '')) ?>"
                                    required
                                    autocomplete="username"
                                    class="auth__input"
                                >
                            </div>

                            <div class="auth__field">
                                <label for="login-password" class="auth__label">Mot de passe</label>
                                <input
                                    type="password"
                                    id="login-password"
                                    name="password"
                                    required
                                    autocomplete="current-password"
                                    class="auth__input"
                                >
                            </div>

                            <div class="auth__submit-group">
                                <button type="submit" class="auth__submit">Se connecter</button>
                            </div>
                        </form>
                        <footer class="auth__panel-footer">
                            <p class="auth__switch-text">
                                Pas encore de compte ? <a href="/auth?mode=register" class="auth__switch-link">Créer un compte Standard gratuit</a>.
                            </p>
                        </footer>
                    </article>
                <?php else: ?>
                    <article class="auth__panel auth__panel--register" aria-labelledby="register-heading">
                        <h2 id="register-heading" class="auth__panel-title">Création d'un compte Boötes</h2>
                        <form action="/auth?mode=register" method="post" class="auth__form" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                            <input type="hidden" name="action" value="register">

                            <div class="auth__field">
                                <label for="register-pseudo" class="auth__label">Nom d'utilisateur (Pseudo)</label>
                                <input
                                    type="text"
                                    id="register-pseudo"
                                    name="pseudo"
                                    value="<?= Security::e((string) ($_POST['pseudo'] ?? '')) ?>"
                                    minlength="3"
                                    maxlength="32"
                                    pattern="^[a-zA-Z0-9_-]{3,32}$"
                                    required
                                    autocomplete="username"
                                    class="auth__input"
                                >
                                <small class="auth__help">3 à 32 caractères (lettres, chiffres, tirets et underscores).</small>
                            </div>

                            <div class="auth__field">
                                <label for="register-email" class="auth__label">Adresse e-mail</label>
                                <input
                                    type="email"
                                    id="register-email"
                                    name="email"
                                    value="<?= Security::e((string) ($_POST['email'] ?? '')) ?>"
                                    required
                                    autocomplete="email"
                                    class="auth__input"
                                >
                            </div>

                            <div class="auth__field">
                                <label for="register-password" class="auth__label">Mot de passe (10 caractères minimum)</label>
                                <input
                                    type="password"
                                    id="register-password"
                                    name="password"
                                    minlength="10"
                                    required
                                    autocomplete="new-password"
                                    class="auth__input"
                                >
                                <small class="auth__help">Chiffré avec l'algorithme Argon2id.</small>
                            </div>

                            <div class="auth__field">
                                <label for="register-password-confirm" class="auth__label">Confirmer le mot de passe</label>
                                <input
                                    type="password"
                                    id="register-password-confirm"
                                    name="password_confirm"
                                    minlength="10"
                                    required
                                    autocomplete="new-password"
                                    class="auth__input"
                                >
                            </div>

                            <div class="auth__field auth__field--checkbox">
                                <input
                                    type="checkbox"
                                    id="register-accept-cgu"
                                    name="accept_cgu"
                                    value="1"
                                    required
                                    class="auth__checkbox"
                                    <?= (!empty($_POST['accept_cgu']) && $_POST['accept_cgu'] === '1') ? 'checked' : '' ?>
                                >
                                <label for="register-accept-cgu" class="auth__checkbox-label">
                                    J'ai lu et j'accepte sans réserve les <a href="/cgu" target="_blank" rel="noopener" class="auth__cgu-link">Conditions Générales d'Utilisation</a> ainsi que la politique de confidentialité RGPD.
                                </label>
                            </div>

                            <div class="auth__submit-group">
                                <button type="submit" class="auth__submit">Créer mon compte</button>
                            </div>
                        </form>
                        <footer class="auth__panel-footer">
                            <p class="auth__switch-text">
                                Vous possédez déjà un compte ? <a href="/auth?mode=login" class="auth__switch-link">Connectez-vous ici</a>.
                            </p>
                        </footer>
                    </article>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>