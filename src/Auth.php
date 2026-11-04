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

    // Niveaux d'accès à un serveur, du plus faible au plus fort
    public const SERVER_PERM_READ    = 'LECTURE';      // consultation seule
    public const SERVER_PERM_CONSOLE = 'CONSOLE';      // + démarrage/arrêt et commandes console
    public const SERVER_PERM_FULL    = 'COMPLET';      // + configuration, sauvegardes, listes joueurs, ressources
    public const SERVER_PERM_OWNER   = 'PROPRIETAIRE'; // + gestion des co-administrateurs (propriétaire ou admin global)

    public const MIN_PASSWORD_LENGTH = 10;
    public const MAX_PASSWORD_BYTES  = 4096;

    public const SESSION_IDLE_TIMEOUT     = 1800;  // 30 minutes d'inactivité
    public const SESSION_ABSOLUTE_TIMEOUT = 43200; // 12 heures après l'authentification

    /** Pseudonymes réservés (comparaison insensible à la casse) : compte système RGPD et usurpation du staff. */
    public const RESERVED_PSEUDOS = [
        'anonyme_rgpd', 'admin', 'administrateur', 'administrator', 'root',
        'system', 'systeme', 'support', 'moderateur', 'moderation', 'bootes',
    ];
    public const RESERVED_EMAIL_DOMAIN = '@bootes.local';

    private const ROLE_RANKS = [
        self::ROLE_VISITEUR    => 0,
        self::ROLE_UTILISATEUR => 1,
        self::ROLE_CREATEUR    => 1,
        self::ROLE_ADMIN       => 2,
        self::ROLE_SUPER_ADMIN => 3,
    ];

    private const SERVER_PERM_RANKS = [
        self::SERVER_PERM_READ    => 1,
        self::SERVER_PERM_CONSOLE => 2,
        self::SERVER_PERM_FULL    => 3,
        self::SERVER_PERM_OWNER   => 4,
    ];

    private const PASSWORD_OPTIONS = [
        'memory_cost' => 65536,
        'time_cost'   => 4,
        'threads'     => 2,
    ];

    /** Hash Argon2id d'un secret aléatoire : vérifié quand le compte n'existe pas (temps de réponse constant). */
    private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=2$eHhmQlZjdHQwaUNJZmhZeA$AWWXN8CsJs/NL1ZAKFi4zgR1JU2OdwYhiyUfuOHuoF8';

    private function __construct()
    {
    }

    /**
     * Initialise une session PHP durcie selon les préconisations OWASP :
     * cookie HttpOnly/SameSite, mode strict, expiration sur inactivité et durée maximale.
     */
    public static function initSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('BOOTES_SESSID');
        session_start();

        $now       = time();
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');

        if (!isset($_SESSION['initiated'])) {
            self::resetSession($now, $userAgent);
            return;
        }

        $agentChanged = !hash_equals((string) ($_SESSION['user_agent'] ?? ''), $userAgent);
        if ($agentChanged || self::isSessionExpired($_SESSION, $now)) {
            self::resetSession($now, $userAgent);
            return;
        }

        $_SESSION['last_activity'] = $now;
    }

    /**
     * Indique si une session a dépassé sa durée d'inactivité ou, une fois authentifiée, sa durée maximale.
     *
     * @param array<string, mixed> $session
     */
    public static function isSessionExpired(array $session, int $now): bool
    {
        $lastActivity = (int) ($session['last_activity'] ?? $session['created_at'] ?? 0);
        if ($now - $lastActivity > self::SESSION_IDLE_TIMEOUT) {
            return true;
        }

        return isset($session['user']) && $now - (int) ($session['auth_time'] ?? 0) > self::SESSION_ABSOLUTE_TIMEOUT;
    }

    /**
     * Inscription d'un nouvel utilisateur avec hachage Argon2id.
     */
    public static function register(string $pseudo, string $email, string $password, PDO $db): bool
    {
        $validPseudo = Security::validateString($pseudo, 3, 32);
        $validEmail  = filter_var(trim($email), FILTER_VALIDATE_EMAIL);

        if (
            $validPseudo === null ||
            $validEmail === false ||
            !self::isAcceptablePassword($password) ||
            self::isReservedIdentity($validPseudo, $validEmail)
        ) {
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

        $insertSql = '
            INSERT INTO UTILISATEUR (pseudo, email, mot_de_passe, role, type_compte, max_ram_allouable, date_inscription)
            VALUES (:pseudo, :email, :mot_de_passe, :role, :type_compte, :max_ram, NOW())
        ';

        $insertStmt = $db->prepare($insertSql);

        return $insertStmt->execute([
            ':pseudo'       => $validPseudo,
            ':email'        => $validEmail,
            ':mot_de_passe' => self::hashPassword($password),
            ':role'         => self::ROLE_UTILISATEUR,
            ':type_compte'  => self::ACCOUNT_STANDARD,
            ':max_ram'      => 2048,
        ]);
    }

    /**
     * Authentifie un utilisateur via son email ou pseudo et son mot de passe.
     * Le mot de passe est toujours vérifié (hash factice si le compte est inconnu) afin que
     * le temps de réponse ne révèle ni l'existence du compte ni son bannissement.
     */
    public static function login(string $identifier, string $password, PDO $db): bool
    {
        self::initSession();

        $identifier = trim($identifier);
        if ($identifier === '' || $password === '' || strlen($password) > self::MAX_PASSWORD_BYTES) {
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

        $user  = $stmt->fetch(PDO::FETCH_ASSOC);
        $hash  = is_array($user) ? (string) $user['mot_de_passe'] : self::DUMMY_PASSWORD_HASH;
        $valid = password_verify($password, $hash);

        if (!is_array($user) || !$valid || !empty($user['est_banni'])) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_ARGON2ID, self::PASSWORD_OPTIONS)) {
            $updStmt = $db->prepare('UPDATE UTILISATEUR SET mot_de_passe = :hash WHERE id_utilisateur = :id');
            $updStmt->execute([
                ':hash' => self::hashPassword($password),
                ':id'   => (int) $user['id_utilisateur'],
            ]);
        }

        // Nouvel identifiant de session (anti-fixation) et nouveau jeton CSRF à chaque élévation de privilèges.
        session_regenerate_id(true);
        Security::rotateCsrfToken();

        $now = time();
        $_SESSION['auth_time']     = $now;
        $_SESSION['last_activity'] = $now;
        $_SESSION['user']          = self::sessionUserFromRow($user);

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

        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
        }

        session_destroy();
    }

    /**
     * Resynchronise la session avec la base à chaque requête : un compte supprimé ou banni
     * est déconnecté immédiatement, un changement de rôle ou d'offre s'applique sans délai.
     */
    public static function refreshSession(PDO $db): void
    {
        if (!self::isLogged()) {
            return;
        }

        $stmt = $db->prepare('
            SELECT id_utilisateur, pseudo, email, role, type_compte, max_ram_allouable, est_banni
            FROM UTILISATEUR
            WHERE id_utilisateur = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => self::getUserId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || !empty($row['est_banni'])) {
            self::logout();
            self::initSession();
            return;
        }

        $_SESSION['user'] = self::sessionUserFromRow($row);
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
     * Vérifie si le compte connecté a l'offre Premium (synchronisée avec la base à chaque requête).
     */
    public static function isPremium(): bool
    {
        return self::isLogged() && strtoupper((string) ($_SESSION['user']['type_compte'] ?? '')) === self::ACCOUNT_PREMIUM;
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

    public static function roleRank(string $role): int
    {
        return self::ROLE_RANKS[strtoupper($role)] ?? 0;
    }

    /**
     * Un administrateur ne peut gérer (modifier, bannir, supprimer) qu'un compte de rang strictement inférieur :
     * un ADMIN gère les utilisateurs, seul un SUPER_ADMIN gère les ADMIN, personne ne gère un SUPER_ADMIN.
     */
    public static function canAdministrate(string $actorRole, string $targetRole): bool
    {
        $actorRank = self::roleRank($actorRole);

        return $actorRank >= self::ROLE_RANKS[self::ROLE_ADMIN] && $actorRank > self::roleRank($targetRole);
    }

    /**
     * Rôle d'un compte lu en base (null si le compte n'existe pas).
     */
    public static function getUserRole(int $userId, PDO $db): ?string
    {
        $stmt = $db->prepare('SELECT role FROM UTILISATEUR WHERE id_utilisateur = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $role = $stmt->fetchColumn();

        return $role !== false ? strtoupper((string) $role) : null;
    }

    /**
     * Niveau d'accès effectif d'un utilisateur sur un serveur (null = aucun accès) :
     * - Administrateur global ou propriétaire : PROPRIETAIRE
     * - Co-administrateur enregistré dans ADMINISTRER_SERVEUR : son niveau (LECTURE, CONSOLE ou COMPLET)
     */
    public static function getServerAccessLevel(int $userId, int $serverId, PDO $db): ?string
    {
        if ($userId <= 0 || $serverId <= 0) {
            return null;
        }

        try {
            $stmt = $db->prepare('
                SELECT
                    s.id_proprietaire,
                    u.role,
                    ads.id_utilisateur AS coadmin_id,
                    ads.niveau_permission
                FROM SERVEUR s
                LEFT JOIN UTILISATEUR u ON u.id_utilisateur = :uid_role
                LEFT JOIN ADMINISTRER_SERVEUR ads ON ads.id_serveur = s.id_serveur AND ads.id_utilisateur = :uid_coadmin
                WHERE s.id_serveur = :server_id
                LIMIT 1
            ');
            $stmt->execute([
                ':uid_role'    => $userId,
                ':uid_coadmin' => $userId,
                ':server_id'   => $serverId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][Auth Error] getServerAccessLevel : %s', $e->getMessage()));
            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        $role = strtoupper((string) ($row['role'] ?? ''));
        if (in_array($role, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true) || (int) $row['id_proprietaire'] === $userId) {
            return self::SERVER_PERM_OWNER;
        }

        if ($row['coadmin_id'] === null) {
            return null;
        }

        // Valeur absente : COMPLET (affichage historique) ; valeur inconnue : LECTURE (moindre privilège).
        $level = strtoupper((string) ($row['niveau_permission'] ?? self::SERVER_PERM_FULL));

        return in_array($level, [self::SERVER_PERM_READ, self::SERVER_PERM_CONSOLE, self::SERVER_PERM_FULL], true)
            ? $level
            : self::SERVER_PERM_READ;
    }

    public static function serverLevelAllows(?string $level, string $required): bool
    {
        if ($level === null || !isset(self::SERVER_PERM_RANKS[$level], self::SERVER_PERM_RANKS[$required])) {
            return false;
        }

        return self::SERVER_PERM_RANKS[$level] >= self::SERVER_PERM_RANKS[$required];
    }

    /**
     * Vérifie si un utilisateur dispose au moins du niveau requis sur un serveur Minecraft.
     */
    public static function canManageServer(int $userId, int $serverId, PDO $db, string $required = self::SERVER_PERM_READ): bool
    {
        return self::serverLevelAllows(self::getServerAccessLevel($userId, $serverId, $db), $required);
    }

    public static function serverPermissionError(?string $level): string
    {
        return sprintf(
            'Votre niveau d\'accès sur ce serveur (%s) ne permet pas cette action.',
            $level ?? 'AUCUN'
        );
    }

    /**
     * Identité réservée : pseudo du compte système ou du staff, ou adresse du domaine interne.
     */
    public static function isReservedIdentity(string $pseudo, string $email): bool
    {
        return in_array(mb_strtolower(trim($pseudo), 'UTF-8'), self::RESERVED_PSEUDOS, true)
            || str_ends_with(mb_strtolower(trim($email), 'UTF-8'), self::RESERVED_EMAIL_DOMAIN);
    }

    public static function isAcceptablePassword(string $password): bool
    {
        return mb_strlen($password, 'UTF-8') >= self::MIN_PASSWORD_LENGTH && strlen($password) <= self::MAX_PASSWORD_BYTES;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::PASSWORD_OPTIONS);
    }

    /**
     * Ré-authentification avant une opération sensible (changement de profil, suppression du compte).
     */
    public static function verifyPassword(int $userId, string $password, PDO $db): bool
    {
        if ($password === '' || strlen($password) > self::MAX_PASSWORD_BYTES) {
            return false;
        }

        $stmt = $db->prepare('SELECT mot_de_passe FROM UTILISATEUR WHERE id_utilisateur = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $hash = $stmt->fetchColumn();

        return password_verify($password, $hash !== false ? (string) $hash : self::DUMMY_PASSWORD_HASH) && $hash !== false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, pseudo: string, email: string, role: string, type_compte: string, max_ram: int}
     */
    private static function sessionUserFromRow(array $row): array
    {
        return [
            'id'          => (int) $row['id_utilisateur'],
            'pseudo'      => (string) $row['pseudo'],
            'email'       => (string) $row['email'],
            'role'        => strtoupper((string) $row['role']),
            'type_compte' => strtoupper((string) ($row['type_compte'] ?? self::ACCOUNT_STANDARD)),
            'max_ram'     => (int) ($row['max_ram_allouable'] ?? 2048),
        ];
    }

    /**
     * Repart d'une session vierge avec un nouvel identifiant (première visite, expiration, empreinte modifiée).
     */
    private static function resetSession(int $now, string $userAgent): void
    {
        $_SESSION = [];
        session_regenerate_id(true);

        $_SESSION['initiated']     = true;
        $_SESSION['created_at']    = $now;
        $_SESSION['last_activity'] = $now;
        $_SESSION['user_agent']    = $userAgent;
    }
}
