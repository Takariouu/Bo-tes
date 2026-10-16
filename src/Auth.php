<?php

declare(strict_types=1);

/**
 * Classe de gestion de l'authentification, des sessions et des permissions de Boötes.
 */
final class Auth
{
    public const ROLE_STANDARD = 1;
    public const ROLE_PREMIUM  = 2;
    public const ROLE_ADMIN    = 3;

    /**
     * Démarre la session PHP avec une configuration de cookies renforcée.
     */
    public static function initSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);

            ini_set('session.use_strict_mode', '1');
            session_start();
        }
    }

    /**
     * Inscrit un nouvel utilisateur dans la table UTILISATEUR avec Argon2id.
     *
     * @param string $username Pseudo de l'utilisateur (max 24 caractères).
     * @param string $email Adresse e-mail.
     * @param string $password Mot de passe en clair.
     * @param PDO $db Instance PDO.
     * @param int $accountTypeId Type de compte par défaut (1 = Standard).
     * @return array{success: bool, message: string, user_id?: string}
     */
    public static function register(
        string $username,
        string $email,
        string $password,
        PDO $db,
        int $accountTypeId = self::ROLE_STANDARD
    ): array {
        $username = trim($username);
        $email = strtolower(trim($email));

        if (!Security::validateAlphanumericDash($username, 3, 24)) {
            return [
                'success' => false,
                'message' => 'Le pseudo doit contenir entre 3 et 24 caractères alphanumériques (tirets autorisés).',
            ];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            return [
                'success' => false,
                'message' => 'Adresse e-mail invalide.',
            ];
        }

        if (strlen($password) < 8) {
            return [
                'success' => false,
                'message' => 'Le mot de passe doit contenir au moins 8 caractères.',
            ];
        }

        // Vérifier l'unicité du pseudo et de l'e-mail
        $checkStmt = $db->prepare(
            'SELECT UTI_ID FROM UTILISATEUR WHERE UTI_EMAIL = :email OR UTI_NOM = :nom LIMIT 1'
        );
        $checkStmt->execute([
            ':email' => $email,
            ':nom'   => $username,
        ]);

        if ($checkStmt->fetch()) {
            return [
                'success' => false,
                'message' => 'Ce pseudo ou cette adresse e-mail est déjà utilisé.',
            ];
        }

        // Génération d'un identifiant unique de 32 caractères compatible CHAR(32) / VARCHAR(36)
        $userId = bin2hex(random_bytes(16));

        // Hachage du mot de passe avec Argon2id
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

        $insertStmt = $db->prepare(
            'INSERT INTO UTILISATEUR (
                UTI_ID, IMG_ID, TDC_ID, UTI_NOM, UTI_EMAIL, UTI_DATE_CREATION, UTI_MOT_DE_PASSE, UTI_DATE_DERNIERE_CONNEXION
            ) VALUES (
                :id, NULL, :tdc_id, :nom, :email, NOW(), :mdp, NULL
            )'
        );

        $insertStmt->execute([
            ':id'     => $userId,
            ':tdc_id' => $accountTypeId,
            ':nom'    => $username,
            ':email'  => $email,
            ':mdp'    => $passwordHash,
        ]);

        return [
            'success' => true,
            'message' => 'Compte créé avec succès. Vous pouvez maintenant vous connecter.',
            'user_id' => $userId,
        ];
    }

    /**
     * Authentifie un utilisateur, régénère la session et met à jour UTI_DATE_DERNIERE_CONNEXION.
     *
     * @param string $identifier Pseudo ou adresse e-mail.
     * @param string $password Mot de passe en clair.
     * @param PDO $db Instance PDO.
     * @return array{success: bool, message: string, is_admin?: bool}
     */
    public static function login(string $identifier, string $password, PDO $db): array
    {
        self::initSession();
        $identifier = trim($identifier);

        if ($identifier === '' || $password === '') {
            return [
                'success' => false,
                'message' => 'Veuillez renseigner tous les champs.',
            ];
        }

        $stmt = $db->prepare(
            'SELECT UTI_ID, TDC_ID, UTI_NOM, UTI_EMAIL, UTI_MOT_DE_PASSE
             FROM UTILISATEUR
             WHERE UTI_EMAIL = :email OR UTI_NOM = :nom
             LIMIT 1'
        );
        $stmt->execute([
            ':email' => strtolower($identifier),
            ':nom'   => $identifier,
        ]);

        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['UTI_MOT_DE_PASSE'])) {
            return [
                'success' => false,
                'message' => 'Identifiants incorrects.',
            ];
        }

        // Re-hachage transparent si les paramètres Argon2id ont évolué
        if (password_needs_rehash((string) $user['UTI_MOT_DE_PASSE'], PASSWORD_ARGON2ID)) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID);
            $rehashStmt = $db->prepare('UPDATE UTILISATEUR SET UTI_MOT_DE_PASSE = :mdp WHERE UTI_ID = :id');
            $rehashStmt->execute([
                ':mdp' => $newHash,
                ':id'  => $user['UTI_ID'],
            ]);
        }

        // Mise à jour de la date de dernière connexion
        $updateLoginStmt = $db->prepare(
            'UPDATE UTILISATEUR SET UTI_DATE_DERNIERE_CONNEXION = NOW() WHERE UTI_ID = :id'
        );
        $updateLoginStmt->execute([':id' => $user['UTI_ID']]);

        // Régénération de l'identifiant de session (protection contre la fixation de session)
        session_regenerate_id(true);

        $_SESSION['user_id']         = (string) $user['UTI_ID'];
        $_SESSION['username']        = (string) $user['UTI_NOM'];
        $_SESSION['email']           = (string) $user['UTI_EMAIL'];
        $_SESSION['account_type_id'] = (int) $user['TDC_ID'];

        Security::refreshCsrfToken();

        return [
            'success'  => true,
            'message'  => 'Connexion réussie.',
            'is_admin' => ((int) $user['TDC_ID'] === self::ROLE_ADMIN),
        ];
    }

    /**
     * Déconnecte l'utilisateur et détruit proprement sa session.
     */
    public static function logout(): void
    {
        self::initSession();
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
     * Vérifie si l'utilisateur actuel est connecté.
     *
     * @return bool
     */
    public static function isLogged(): bool
    {
        self::initSession();
        return !empty($_SESSION['user_id']);
    }

    /**
     * Vérifie si l'utilisateur connecté possède le rôle Administrateur.
     *
     * @return bool
     */
    public static function isAdmin(): bool
    {
        self::initSession();
        return self::isLogged()
            && isset($_SESSION['account_type_id'])
            && (int) $_SESSION['account_type_id'] === self::ROLE_ADMIN;
    }

    /**
     * Vérifie si l'utilisateur connecté possède un compte Premium (ou Admin).
     *
     * @return bool
     */
    public static function isPremium(): bool
    {
        self::initSession();
        return self::isLogged()
            && isset($_SESSION['account_type_id'])
            && in_array((int) $_SESSION['account_type_id'], [self::ROLE_PREMIUM, self::ROLE_ADMIN], true);
    }

    /**
     * Retourne l'identifiant de l'utilisateur connecté (ou null).
     *
     * @return string|null
     */
    public static function getCurrentUserId(): ?string
    {
        self::initSession();
        return isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null;
    }

    /**
     * Vérifie si un utilisateur a le droit d'administrer un serveur donné
     * (Propriétaire dans SERVEUR, co-administrateur dans ADMINISTRER_SERVEUR, ou SuperAdmin).
     *
     * @param int|string $userId Identifiant de l'utilisateur (UTI_ID).
     * @param int $serverId Identifiant du serveur (SVR_ID).
     * @param PDO $db Instance PDO.
     * @return bool
     */
    public static function hasServerAccess(int|string $userId, int $serverId, PDO $db): bool
    {
        if (self::isAdmin()) {
            return true;
        }

        $userIdStr = (string) $userId;

        $sql = '
            SELECT 1
            FROM SERVEUR s
            LEFT JOIN ADMINISTRER_SERVEUR a ON s.SVR_ID = a.SVR_ID AND a.UTI_ID = :co_admin_id
            WHERE s.SVR_ID = :server_id
              AND (s.UTI_ID = :owner_id OR a.UTI_ID IS NOT NULL)
            LIMIT 1
        ';

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':server_id'   => $serverId,
            ':owner_id'    => $userIdStr,
            ':co_admin_id' => $userIdStr,
        ]);

        return (bool) $stmt->fetchColumn();
    }
}