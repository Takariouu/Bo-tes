<?php

declare(strict_types=1);

namespace Bootes\Src;

use Bootes\Config\AppConfig;
use PDO;
use PDOException;

/**
 * Annuaire public des serveurs Minecraft : instances hébergées par Boötes (origine HEBERGE) et
 * serveurs externes référencés par les membres (origine EXTERNE, simple fiche sans instance).
 *
 * Les deux cohabitent dans la table SERVEUR pour partager favoris, avis, signalements et pages
 * personnalisées ; tout le code d'hébergement (ports, RAM, quotas, panneau) filtre origine = HEBERGE.
 */
final class ServerDirectory
{
    public const ORIGIN_HOSTED   = 'HEBERGE';
    public const ORIGIN_EXTERNAL = 'EXTERNE';

    /** Adresse publique des instances hébergées (les fiches externes ne peuvent pas l'utiliser). */
    public const HOSTED_ADDRESS = 'play.bootes.local';
    public const DEFAULT_PORT   = 25565;

    public const STANDARD_MAX_LISTINGS = 2;
    public const PREMIUM_MAX_LISTINGS  = 10;
    public const MAX_TAGS              = 5;
    public const CHECK_INTERVAL_MIN    = 5;

    public const TAGS = [
        'survie'        => 'Survie',
        'creatif'       => 'Créatif',
        'pvp'           => 'PvP',
        'faction'       => 'Faction',
        'skyblock'      => 'Skyblock',
        'mini-jeux'     => 'Mini-jeux',
        'bedwars'       => 'BedWars',
        'rp'            => 'Roleplay',
        'modde'         => 'Moddé',
        'economie'      => 'Économie',
        'aventure'      => 'Aventure',
        'hardcore'      => 'Hardcore',
        'vanilla'       => 'Vanilla',
        'communautaire' => 'Communautaire',
    ];

    public const VERSIONS = ['1.21.4', '1.21.1', '1.20.4', '1.20.1', '1.19.4', '1.18.2', '1.16.5', '1.12.2', '1.8.9'];

    public const SORTS = [
        'populaires' => 'En ligne d\'abord',
        'joueurs'    => 'Les plus peuplés',
        'notes'      => 'Les mieux notés',
        'recents'    => 'Les plus récents',
        'nom'        => 'Ordre alphabétique',
    ];

    public function __construct(
        private readonly PDO $db
    ) {
    }

    public static function maxListings(bool $isPremium): int
    {
        return $isPremium ? self::PREMIUM_MAX_LISTINGS : self::STANDARD_MAX_LISTINGS;
    }

    /** Adresse telle qu'on la saisit dans Minecraft (le port par défaut est omis). */
    public static function formatAddress(string $host, int|string $port): string
    {
        return (int) $port === self::DEFAULT_PORT ? $host : sprintf('%s:%d', $host, (int) $port);
    }

    /** @param array<int, mixed> $tags */
    public static function encodeTags(array $tags): string
    {
        $valid = array_values(array_intersect(array_keys(self::TAGS), array_map('strval', $tags)));

        return $valid === [] ? '' : ',' . implode(',', array_slice($valid, 0, self::MAX_TAGS)) . ',';
    }

    /** @return array<int, string> */
    public static function decodeTags(?string $raw): array
    {
        $tags = array_filter(explode(',', (string) $raw), static fn (string $tag): bool => isset(self::TAGS[$tag]));

        return array_values(array_intersect(array_keys(self::TAGS), $tags));
    }

    /**
     * Normalise une adresse saisie (« Play.Exemple.fr:25566 » -> hôte + port) et refuse les adresses
     * internes, l'adresse de l'hébergement Boötes et les noms non publics.
     *
     * @return array{0: ?string, 1: int, 2: ?string} hôte, port, message d'erreur
     */
    public static function normalizeAddress(mixed $rawHost, mixed $rawPort): array
    {
        $host = strtolower(Security::validateString($rawHost, 3, 253) ?? '');
        $port = Security::validatePort($rawPort === '' || $rawPort === null ? self::DEFAULT_PORT : $rawPort);
        if (preg_match('/^(.+):(\d{1,5})$/', $host, $m) === 1) {
            [$host, $port] = [$m[1], Security::validatePort($m[2])];
        }

        if ($host === '' || $port === null) {
            return [null, self::DEFAULT_PORT, 'Indiquez l\'adresse du serveur (ex. play.monserveur.fr) et un port valide.'];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ServerPing::isPublicIpv4($host)
                ? [$host, $port, null]
                : [null, $port, 'Seules les adresses IP publiques peuvent être référencées.'];
        }

        $isHostname = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && preg_match('/\.[a-z]{2,24}$/', $host) === 1
            && preg_match('/(^|\.)(localhost|local|internal|lan|home|test|invalid|example)$/', $host) !== 1;
        if (!$isHostname || $host === self::HOSTED_ADDRESS) {
            return [null, $port, 'Cette adresse n\'est pas un nom de domaine public valide.'];
        }

        return [$host, $port, null];
    }

    /**
     * Valide les champs d'une fiche. Pour une instance hébergée, seuls les champs de présentation sont
     * modifiables ici (adresse, version et mode restent gérés par le panneau du serveur).
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, string>} données propres, erreurs par champ
     */
    public static function validate(array $input, bool $external): array
    {
        $data   = [];
        $errors = [];

        $data['resume'] = Security::validateString($input['resume'] ?? '', 0, 160);
        if ($data['resume'] === null) {
            $errors['resume'] = 'Le slogan fait 160 caractères au maximum.';
        }

        $data['description'] = Security::validateMultiline($input['description'] ?? '', 0, 5000);
        if ($data['description'] === null) {
            $errors['description'] = 'La présentation fait 5 000 caractères au maximum.';
        }

        $tags = is_array($input['etiquettes'] ?? null) ? $input['etiquettes'] : [];
        if (count($tags) > self::MAX_TAGS) {
            $errors['etiquettes'] = sprintf('Choisissez %d étiquettes au maximum.', self::MAX_TAGS);
        }
        $data['etiquettes'] = self::encodeTags($tags);

        foreach (['site_web' => 'Le site web', 'discord' => 'Le lien Discord'] as $field => $label) {
            $raw = trim((string) (is_string($input[$field] ?? null) ? $input[$field] : ''));
            $data[$field] = $raw === '' ? null : Security::validateWebUrl($raw);
            if ($raw !== '' && $data[$field] === null) {
                $errors[$field] = $label . ' doit être une adresse web complète (https://...).';
            }
        }
        if ($data['discord'] !== null
            && !in_array(strtolower((string) parse_url($data['discord'], PHP_URL_HOST)), ['discord.gg', 'discord.com', 'www.discord.com'], true)) {
            $errors['discord'] = 'Le lien Discord doit être une invitation discord.gg ou discord.com.';
        }

        if (!$external) {
            return [$data, $errors];
        }

        $data['nom_serveur'] = Security::validateString($input['nom_serveur'] ?? '', 3, 64);
        if ($data['nom_serveur'] === null) {
            $errors['nom_serveur'] = 'Le nom du serveur fait entre 3 et 64 caractères.';
        }

        [$data['adresse_ip'], $data['port'], $addressError] = self::normalizeAddress($input['adresse_ip'] ?? '', $input['port'] ?? '');
        if ($addressError !== null) {
            $errors['adresse_ip'] = $addressError;
        }

        $version            = is_string($input['version_mc'] ?? null) ? $input['version_mc'] : '';
        $data['version_mc'] = in_array($version, self::VERSIONS, true) ? $version : null;
        if ($data['version_mc'] === null) {
            $errors['version_mc'] = 'Choisissez la version principale du serveur.';
        }

        $mode             = is_string($input['mode_jeu'] ?? null) ? $input['mode_jeu'] : '';
        $data['mode_jeu'] = array_key_exists($mode, ServerManager::GAME_MODES) ? $mode : null;
        if ($data['mode_jeu'] === null) {
            $errors['mode_jeu'] = 'Choisissez le mode de jeu principal.';
        }

        $data['est_public'] = !empty($input['est_public']) ? 1 : 0;

        return [$data, $errors];
    }

    public function countListings(int $ownerId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM SERVEUR WHERE id_proprietaire = :owner AND origine = :origin');
        $stmt->execute([':owner' => $ownerId, ':origin' => self::ORIGIN_EXTERNAL]);

        return (int) $stmt->fetchColumn();
    }

    public function addressTaken(string $host, int $port, int $exceptServerId = 0): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM SERVEUR WHERE LOWER(adresse_ip) = :host AND port = :port AND id_serveur != :except LIMIT 1');
        $stmt->execute([':host' => strtolower($host), ':port' => $port, ':except' => $exceptServerId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Référence un serveur externe.
     *
     * @param array<string, mixed> $data données issues de validate()
     * @return array{success: bool, message: string, id?: int}
     */
    public function create(int $ownerId, bool $ownerIsPremium, array $data): array
    {
        $limit = self::maxListings($ownerIsPremium);
        if ($this->countListings($ownerId) >= $limit) {
            return ['success' => false, 'message' => sprintf('Votre offre permet de référencer %d serveur%s : passez Premium pour en ajouter davantage.', $limit, $limit > 1 ? 's' : '')];
        }
        if ($this->addressTaken((string) $data['adresse_ip'], (int) $data['port'])) {
            return ['success' => false, 'message' => 'Ce serveur est déjà référencé dans l\'annuaire.'];
        }

        try {
            $stmt = $this->db->prepare('
                INSERT INTO SERVEUR (id_proprietaire, origine, nom_serveur, adresse_ip, port, version_mc, type_loader, mode_jeu,
                                     statut, joueurs_connectes, slots_max, ram_allouee, description, resume, etiquettes,
                                     site_web, discord, est_public, date_creation)
                VALUES (:owner, :origin, :nom, :host, :port, :version, \'VANILLA\', :mode,
                        \'HORS_LIGNE\', 0, 0, 0, :description, :resume, :tags,
                        :site, :discord, :public, NOW())
            ');
            $stmt->execute([
                ':owner'       => $ownerId,
                ':origin'      => self::ORIGIN_EXTERNAL,
                ':nom'         => $data['nom_serveur'],
                ':host'        => $data['adresse_ip'],
                ':port'        => $data['port'],
                ':version'     => $data['version_mc'],
                ':mode'        => $data['mode_jeu'],
                ':description' => $data['description'],
                ':resume'      => $data['resume'],
                ':tags'        => $data['etiquettes'],
                ':site'        => $data['site_web'],
                ':discord'     => $data['discord'],
                ':public'      => $data['est_public'],
            ]);
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][Annuaire] Création de fiche impossible : %s', $e->getMessage()));
            return ['success' => false, 'message' => 'La fiche n\'a pas pu être enregistrée, veuillez réessayer.'];
        }

        return ['success' => true, 'message' => 'Serveur ajouté à l\'annuaire.', 'id' => (int) $this->db->lastInsertId()];
    }

    /**
     * Met à jour une fiche (champs de présentation ; adresse, version et visibilité pour une fiche externe).
     *
     * @param array<string, mixed> $server ligne actuelle (ServerManager::getServerById)
     * @param array<string, mixed> $data   données issues de validate()
     * @return array{success: bool, message: string}
     */
    public function update(array $server, array $data): array
    {
        $serverId = (int) $server['id_serveur'];
        $external = (string) $server['origine'] === self::ORIGIN_EXTERNAL;

        if ($external && $this->addressTaken((string) $data['adresse_ip'], (int) $data['port'], $serverId)) {
            return ['success' => false, 'message' => 'Un autre serveur de l\'annuaire utilise déjà cette adresse.'];
        }

        $sets   = ['resume = :resume', 'description = :description', 'etiquettes = :tags', 'site_web = :site', 'discord = :discord'];
        $params = [
            ':resume'      => $data['resume'],
            ':description' => $data['description'],
            ':tags'        => $data['etiquettes'],
            ':site'        => $data['site_web'],
            ':discord'     => $data['discord'],
            ':id'          => $serverId,
        ];
        if ($external) {
            $addressChanged = strtolower((string) $server['adresse_ip']) !== $data['adresse_ip'] || (int) $server['port'] !== (int) $data['port'];
            $sets[] = 'nom_serveur = :nom';
            $sets[] = 'adresse_ip = :host';
            $sets[] = 'port = :port';
            $sets[] = 'version_mc = :version';
            $sets[] = 'mode_jeu = :mode';
            $sets[] = 'est_public = :public';
            if ($addressChanged) {
                $sets[] = 'date_verification = NULL';
            }
            $params += [
                ':nom'     => $data['nom_serveur'],
                ':host'    => $data['adresse_ip'],
                ':port'    => $data['port'],
                ':version' => $data['version_mc'],
                ':mode'    => $data['mode_jeu'],
                ':public'  => $data['est_public'],
            ];
        }

        try {
            $this->db->prepare('UPDATE SERVEUR SET ' . implode(', ', $sets) . ' WHERE id_serveur = :id')->execute($params);
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][Annuaire] Mise à jour de la fiche #%d impossible : %s', $serverId, $e->getMessage()));
            return ['success' => false, 'message' => 'La fiche n\'a pas pu être enregistrée, veuillez réessayer.'];
        }

        return ['success' => true, 'message' => 'Fiche du serveur enregistrée.'];
    }

    /**
     * Actualise le statut d'un serveur externe par un ping : au plus toutes les 5 minutes, ou une fois
     * par minute sur demande explicite du propriétaire.
     *
     * @param array<string, mixed> $server
     */
    public function refreshStatus(array $server, bool $force = false): bool
    {
        // BOOTES_DIRECTORY_PING=0 coupe les connexions sortantes (tests automatisés, hébergement sans réseau)
        if ((string) ($server['origine'] ?? '') !== self::ORIGIN_EXTERNAL || AppConfig::env('BOOTES_DIRECTORY_PING', '1') === '0') {
            return false;
        }

        $last     = !empty($server['date_verification']) ? (int) strtotime((string) $server['date_verification']) : 0;
        $minDelay = $force ? 60 : self::CHECK_INTERVAL_MIN * 60;
        if ($last > 0 && time() - $last < $minDelay) {
            return false;
        }

        $result  = ServerPing::query((string) $server['adresse_ip'], (int) $server['port']);
        $version = (string) $server['version_mc'];
        if ($result['version'] !== null && preg_match('/\b(1\.\d{1,2}(?:\.\d{1,2})?)\b/', $result['version'], $m) === 1) {
            $version = $m[1];
        }

        $stmt = $this->db->prepare('
            UPDATE SERVEUR
            SET statut = :statut, joueurs_connectes = :players, slots_max = :slots, version_mc = :version, date_verification = NOW()
            WHERE id_serveur = :id AND origine = :origin
        ');
        $stmt->execute([
            ':statut'  => $result['online'] ? ServerManager::STATUS_ONLINE : ServerManager::STATUS_OFFLINE,
            ':players' => min(65535, $result['players']),
            ':slots'   => $result['online'] ? min(65535, $result['max']) : (int) $server['slots_max'],
            ':version' => $version,
            ':id'      => (int) $server['id_serveur'],
            ':origin'  => self::ORIGIN_EXTERNAL,
        ]);

        return true;
    }

    /**
     * Recherche dans l'annuaire public (serveurs publics de comptes non bannis).
     *
     * @param array{q?: string, tag?: string, version?: string, origine?: string, en_ligne?: bool, tri?: string} $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        $where  = ['s.est_public = 1', 'u.est_banni = 0'];
        $params = [];

        $query = (string) ($filters['q'] ?? '');
        if ($query !== '') {
            $like  = Security::likeContains($query);
            $where[] = "(s.nom_serveur LIKE :q1 ESCAPE '!' OR s.adresse_ip LIKE :q2 ESCAPE '!' OR s.resume LIKE :q3 ESCAPE '!' OR s.description LIKE :q4 ESCAPE '!')";
            $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }

        $tag = (string) ($filters['tag'] ?? '');
        if (isset(self::TAGS[$tag])) {
            $where[]         = 's.etiquettes LIKE :tag';
            $params[':tag'] = '%,' . $tag . ',%';
        }

        $version = (string) ($filters['version'] ?? '');
        if ($version !== '' && in_array($version, self::VERSIONS, true)) {
            $where[]             = 's.version_mc = :version';
            $params[':version'] = $version;
        }

        $origin = (string) ($filters['origine'] ?? '');
        if (in_array($origin, [self::ORIGIN_HOSTED, self::ORIGIN_EXTERNAL], true)) {
            $where[]            = 's.origine = :origin';
            $params[':origin'] = $origin;
        }

        if (!empty($filters['en_ligne'])) {
            $where[] = "s.statut = 'EN_LIGNE'";
        }

        $order = match ((string) ($filters['tri'] ?? 'populaires')) {
            'joueurs' => 's.joueurs_connectes DESC, note DESC',
            'notes'   => 'note DESC, nb_avis DESC',
            'recents' => 's.date_creation DESC',
            'nom'     => 's.nom_serveur ASC',
            default   => "CASE s.statut WHEN 'EN_LIGNE' THEN 0 WHEN 'DEMARRAGE' THEN 1 WHEN 'MAINTENANCE' THEN 2 ELSE 3 END, s.joueurs_connectes DESC, note DESC",
        };
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM SERVEUR s INNER JOIN UTILISATEUR u ON u.id_utilisateur = s.id_proprietaire WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT
                s.id_serveur, s.origine, s.nom_serveur, s.adresse_ip, s.port, s.version_mc, s.mode_jeu, s.statut,
                s.joueurs_connectes, s.slots_max, s.resume, s.description, s.etiquettes, s.date_creation,
                s.date_verification, u.id_utilisateur AS proprietaire_id, u.pseudo AS proprietaire,
                u.type_compte AS proprietaire_offre,
                COALESCE((SELECT AVG(a.note) FROM AVIS_SERVEUR a WHERE a.id_serveur = s.id_serveur), 0) AS note,
                (SELECT COUNT(*) FROM AVIS_SERVEUR a2 WHERE a2.id_serveur = s.id_serveur) AS nb_avis,
                (SELECT COUNT(*) FROM FAVORI_SERVEUR f WHERE f.id_serveur = s.id_serveur) AS nb_favoris
            FROM SERVEUR s
            INNER JOIN UTILISATEUR u ON u.id_utilisateur = s.id_proprietaire
            WHERE {$whereSql}
            ORDER BY {$order}, s.id_serveur ASC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * Fiches externes d'un membre (espace compte).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listingsOf(int $ownerId): array
    {
        $stmt = $this->db->prepare('
            SELECT id_serveur, nom_serveur, adresse_ip, port, version_mc, statut, joueurs_connectes, slots_max, est_public, date_verification
            FROM SERVEUR
            WHERE id_proprietaire = :owner AND origine = :origin
            ORDER BY date_creation DESC
        ');
        $stmt->execute([':owner' => $ownerId, ':origin' => self::ORIGIN_EXTERNAL]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fiches externes à vérifier (tâche planifiée cron/verifier-annuaire.php).
     *
     * @return array<int, array<string, mixed>>
     */
    public function dueForCheck(int $limit = 50): array
    {
        $stmt = $this->db->prepare('
            SELECT id_serveur, origine, adresse_ip, port, version_mc, slots_max, date_verification
            FROM SERVEUR
            WHERE origine = :origin AND est_public = 1
            ORDER BY date_verification IS NOT NULL, date_verification ASC
            LIMIT :limit
        ');
        $stmt->bindValue(':origin', self::ORIGIN_EXTERNAL, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
