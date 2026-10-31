<?php

declare(strict_types=1);

namespace Bootes\Src;

use PDO;
use PDOException;

final class Auth
{
    public const ROLE_VISITEUR    = 'VISITEUR';
    public const ROLE_UTILISATEUR = 'UTILISATEUR';
    public const ROLE_CREATEUR    = 'CREATEUR';
    public const ROLE_ADMIN       = 'ADMIN';
    public const ROLE_SUPER_ADMIN = 'SUPER_ADMIN';

    public const ACCOUNT_STANDARD = 'STANDARD';
    public const ACCOUNT_PREMIUM  = 'PREMIUM';

    private function __construct()
    {
    }

    /**
     * Initialise une session PHP durcie selon les préconisations OWASP.
     */
    public static function initSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('BOOTES_SESSID');
        session_start();

        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated']  = true;
            $_SESSION['created_at'] = time();
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        } elseif (
            isset($_SESSION['user_agent']) &&
            !hash_equals((string) $_SESSION['user_agent'], (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'))
        ) {
            self::logout();
        }
    }

    /**
     * Inscription d'un nouvel utilisateur avec hachage Argon2id.
     */
    public static function register(string $pseudo, string $email, string $password, PDO $db): bool
    {
        $validPseudo = Security::validateString($pseudo, 3, 32);
        $validEmail  = filter_var(trim($email), FILTER_VALIDATE_EMAIL);

        if ($validPseudo === null || $validEmail === false || mb_strlen($password, 'UTF-8') < 10) {
            return false;
        }

        $checkSql = 'SELECT COUNT(*) FROM UTILISATEUR WHERE email = :email OR pseudo = :pseudo';
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([
            ':email'  => $validEmail,
            ':pseudo' => $validPseudo,
        ]);

        if ((int) $checkStmt->fetchColumn() > 0) {
            return false;
        }

        $passwordHash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 2,
        ]);

        $insertSql = '
            INSERT INTO UTILISATEUR (pseudo, email, mot_de_passe, role, type_compte, max_ram_allouable, date_inscription)
            VALUES (:pseudo, :email, :mot_de_passe, :role, :type_compte, :max_ram, NOW())
        ';

        $insertStmt = $db->prepare($insertSql);

        return $insertStmt->execute([
            ':pseudo'       => $validPseudo,
            ':email'        => $validEmail,
            ':mot_de_passe' => $passwordHash,
            ':role'         => self::ROLE_UTILISATEUR,
            ':type_compte'  => self::ACCOUNT_STANDARD,
            ':max_ram'      => 2048,
        ]);
    }

    /**
     * Authentifie un utilisateur via son email ou pseudo et son mot de passe.
     */
    public static function login(string $identifier, string $password, PDO $db): bool
    {
        self::initSession();

        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return false;
        }

        $sql = '
            SELECT id_utilisateur, pseudo, email, mot_de_passe, role, type_compte, max_ram_allouable, est_banni
            FROM UTILISATEUR
            WHERE email = :email_id OR pseudo = :pseudo_id
            LIMIT 1
        ';

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':email_id'  => $identifier,
            ':pseudo_id' => $identifier,
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !empty($user['est_banni'])) {
            return false;
        }

        $hash = (string) $user['mot_de_passe'];
        if (!password_verify($password, $hash)) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID);
            $updStmt = $db->prepare('UPDATE UTILISATEUR SET mot_de_passe = :hash WHERE id_utilisateur = :id');
            $updStmt->execute([
                ':hash' => $newHash,
                ':id'   => (int) $user['id_utilisateur'],
            ]);
        }

        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'          => (int) $user['id_utilisateur'],
            'pseudo'      => (string) $user['pseudo'],
            'email'       => (string) $user['email'],
            'role'        => strtoupper((string) $user['role']),
            'type_compte' => strtoupper((string) ($user['type_compte'] ?? self::ACCOUNT_STANDARD)),
            'max_ram'     => (int) ($user['max_ram_allouable'] ?? 2048),
        ];

        return true;
    }

    /**
     * Déconnecte l'utilisateur et détruit proprement la session et le cookie associé.
     */
    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::initSession();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Vérifie si l'utilisateur courant est authentifié.
     */
    public static function isLogged(): bool
    {
        self::initSession();

        return isset($_SESSION['user']['id']) && is_int($_SESSION['user']['id']) && $_SESSION['user']['id'] > 0;
    }

    /**
     * Retourne l'identifiant de l'utilisateur connecté ou null.
     */
    public static function getUserId(): ?int
    {
        return self::isLogged() ? (int) $_SESSION['user']['id'] : null;
    }

    /**
     * Retourne le rôle courant de l'utilisateur.
     */
    public static function getRole(): string
    {
        return self::isLogged() ? (string) $_SESSION['user']['role'] : self::ROLE_VISITEUR;
    }

    /**
     * Vérifie si l'utilisateur dispose des privilèges Admin ou Super-Admin.
     */
    public static function isAdmin(): bool
    {
        if (!self::isLogged()) {
            return false;
        }

        return in_array(self::getRole(), [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true);
    }

    /**
     * Vérifie si l'utilisateur dispose des privilèges Super-Admin.
     */
    public static function isSuperAdmin(): bool
    {
        if (!self::isLogged()) {
            return false;
        }

        return self::getRole() === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Vérifie si un utilisateur est autorisé à gérer un serveur Minecraft spécifique :
     * - Administrateur global ou Super-Administrateur (en session ou en base)
     * - Propriétaire du serveur dans la table SERVEUR
     * - Co-administrateur enregistré dans la table ADMINISTRER_SERVEUR
     */
    public static function canManageServer(int $userId, int $serverId, PDO $db): bool
    {
        if ($userId <= 0 || $serverId <= 0) {
            return false;
        }

        if (self::isLogged() && self::getUserId() === $userId && self::isAdmin()) {
            return true;
        }

        try {
            $roleStmt = $db->prepare('SELECT role FROM UTILISATEUR WHERE id_utilisateur = :user_id LIMIT 1');
            $roleStmt->execute([':user_id' => $userId]);
            $userRole = strtoupper((string) $roleStmt->fetchColumn());

            if (in_array($userRole, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true)) {
                return true;
            }

            $ownerStmt = $db->prepare('
                SELECT 1
                FROM SERVEUR
                WHERE id_serveur = :server_id
                  AND id_proprietaire = :user_id
                LIMIT 1
            ');
            $ownerStmt->execute([
                ':server_id' => $serverId,
                ':user_id'   => $userId,
            ]);

            if ($ownerStmt->fetchColumn() !== false) {
                return true;
            }

            $coAdminStmt = $db->prepare('
                SELECT 1
                FROM ADMINISTRER_SERVEUR
                WHERE id_serveur = :server_id
                  AND id_utilisateur = :user_id
                LIMIT 1
            ');
            $coAdminStmt->execute([
                ':server_id' => $serverId,
                ':user_id'   => $userId,
            ]);

            return $coAdminStmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][Auth Error] canManageServer : %s', $e->getMessage()));
            return false;
        }
    }
}