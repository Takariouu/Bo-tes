<?php

declare(strict_types=1);

namespace Bootes\Src;

/**
 * Aides de présentation : URL versionnées des fichiers statiques, icônes du sprite SVG et thème d'affichage.
 */
final class View
{
    public const THEME_COOKIE  = 'bootes_theme';
    public const THEME_DARK    = 'dark';
    public const THEME_LIGHT   = 'light';
    public const DEFAULT_THEME = self::THEME_DARK;

    public const LIST_MODE_COOKIE = 'bootes_vue';
    public const LIST_MODES       = ['liste', 'grille'];

    private const ICON_SPRITE = '/assets/img/icons.svg';

    /**
     * Libellés lisibles des valeurs techniques stockées en base, par famille.
     * Une valeur absente de la table est simplement mise en forme (EXEMPLE_VALEUR -> « Exemple valeur »).
     */
    private const LABELS = [
        'role' => [
            'VISITEUR'    => 'Visiteur',
            'UTILISATEUR' => 'Client',
            'CREATEUR'    => 'Créateur',
            'ADMIN'       => 'Administrateur',
            'SUPER_ADMIN' => 'Super-admin',
        ],
        'offre' => [
            'STANDARD' => 'Standard',
            'PREMIUM'  => 'Premium',
        ],
        'statut_serveur' => [
            'EN_LIGNE'    => 'En ligne',
            'HORS_LIGNE'  => 'Hors ligne',
            'DEMARRAGE'   => 'Démarrage',
            'MAINTENANCE' => 'Maintenance',
        ],
        'loader' => [
            'VANILLA'  => 'Vanilla',
            'PAPER'    => 'Paper',
            'FABRIC'   => 'Fabric',
            'FORGE'    => 'Forge',
            'NEOFORGE' => 'NeoForge',
        ],
        'mode_jeu' => [
            'SURVIE'   => 'Survie',
            'CREATIF'  => 'Créatif',
            'HARDCORE' => 'Hardcore',
            'AVENTURE' => 'Aventure',
            'MODDE'    => 'Survie moddée',
        ],
        'type_ressource' => [
            'MOD'      => 'Mod',
            'PLUGIN'   => 'Plugin',
            'MOD_PACK' => 'Modpack',
            'MODPACK'  => 'Modpack',
        ],
        'moderation' => [
            'EN_ATTENTE' => 'En attente',
            'VALIDE'     => 'Validée',
            'SUSPENDU'   => 'Suspendue',
        ],
        'signalement' => [
            'EN_ATTENTE' => 'En attente',
            'TRAITE'     => 'Traité',
            'IGNORE'     => 'Sans suite',
        ],
        'cible' => [
            'SERVEUR'     => 'Serveur',
            'RESSOURCE'   => 'Ressource',
            'UTILISATEUR' => 'Utilisateur',
        ],
        'motif' => [
            'MALWARE'         => 'Code malveillant',
            'COPYRIGHT'       => 'Droits d\'auteur',
            'BROKEN'          => 'Archive défectueuse',
            'INAPPROPRIATE'   => 'Contenu inapproprié',
            'EULA_VIOLATION'  => 'Non-respect de l\'EULA',
            'HARASSMENT'      => 'Harcèlement',
            'RESOURCE_ABUSE'  => 'Abus matériel',
            'ILLEGAL_CONTENT' => 'Contenu illicite',
        ],
        'ticket' => [
            'OUVERT'  => 'Ouvert',
            'REPONDU' => 'Répondu',
            'FERME'   => 'Fermé',
        ],
        'categorie_ticket' => [
            'TECHNIQUE'   => 'Technique',
            'FACTURATION' => 'Offre et RAM',
            'MODERATION'  => 'Modération',
            'RGPD'        => 'Données personnelles',
        ],
        'acces' => [
            'LECTURE'      => 'Lecture seule',
            'CONSOLE'      => 'Console',
            'COMPLET'      => 'Gestion complète',
            'PROPRIETAIRE' => 'Propriétaire',
        ],
        'liste' => [
            'WHITELIST' => 'Liste blanche',
            'BLACKLIST' => 'Liste noire',
            'OP'        => 'Opérateur',
        ],
        'niveau_log' => [
            'INFO'     => 'Information',
            'WARNING'  => 'Avertissement',
            'CRITICAL' => 'Critique',
        ],
    ];

    /** Teinte des pastilles (classe badge--...) associée à chaque valeur. */
    private const TONES = [
        'statut_serveur' => ['EN_LIGNE' => 'online', 'HORS_LIGNE' => 'neutral', 'DEMARRAGE' => 'warning', 'MAINTENANCE' => 'maintenance'],
        'moderation'     => ['EN_ATTENTE' => 'warning', 'VALIDE' => 'success', 'SUSPENDU' => 'danger'],
        'signalement'    => ['EN_ATTENTE' => 'warning', 'TRAITE' => 'success', 'IGNORE' => 'neutral'],
        'ticket'         => ['OUVERT' => 'warning', 'REPONDU' => 'info', 'FERME' => 'neutral'],
        'niveau_log'     => ['INFO' => 'info', 'WARNING' => 'warning', 'CRITICAL' => 'danger'],
        'role'           => ['ADMIN' => 'accent', 'SUPER_ADMIN' => 'accent', 'CREATEUR' => 'info'],
        'offre'          => ['PREMIUM' => 'accent'],
        'liste'          => ['WHITELIST' => 'success', 'BLACKLIST' => 'danger', 'OP' => 'accent'],
        'acces'          => ['LECTURE' => 'neutral', 'CONSOLE' => 'info', 'COMPLET' => 'accent'],
    ];

    private static ?string $spriteUrl = null;

    private function __construct()
    {
    }

    /**
     * URL d'un fichier de /assets suffixée de sa date de modification : le navigateur recharge le fichier dès qu'il change.
     */
    public static function asset(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $file = dirname(__DIR__) . $path;

        return $path . '?v=' . (is_file($file) ? (string) filemtime($file) : '0');
    }

    /**
     * Icône du sprite (Lucide) : décorative par défaut, annoncée aux lecteurs d'écran si un libellé est fourni.
     */
    public static function icon(string $name, string $class = '', ?string $label = null): string
    {
        self::$spriteUrl ??= self::asset(self::ICON_SPRITE);

        $name = (string) preg_replace('/[^a-z0-9-]/', '', strtolower($name));
        $a11y = $label === null
            ? 'aria-hidden="true" focusable="false"'
            : 'role="img" aria-label="' . Security::e($label) . '"';

        return sprintf(
            '<svg class="%s" %s><use href="%s#%s"></use></svg>',
            Security::e(trim('icon ' . $class)),
            $a11y,
            Security::e(self::$spriteUrl),
            $name
        );
    }

    /**
     * Libellé lisible d'une valeur technique (ex. label('statut_serveur', 'EN_LIGNE') -> « En ligne »).
     */
    public static function label(string $group, mixed $value): string
    {
        $key = strtoupper(trim((string) $value));
        if ($key === '') {
            return '';
        }

        return self::LABELS[$group][$key] ?? self::humanize($key);
    }

    /**
     * Teinte de pastille associée à une valeur (online, success, warning, danger, info, accent...), neutral par défaut.
     */
    public static function tone(string $group, mixed $value): string
    {
        return self::TONES[$group][strtoupper(trim((string) $value))] ?? 'neutral';
    }

    /**
     * Pastille prête à l'emploi : libellé lisible + teinte, classes supplémentaires éventuelles.
     */
    public static function badge(string $group, mixed $value, string $class = ''): string
    {
        // Les statuts de serveur portent une pastille de couleur (animée lorsque le serveur est en ligne)
        $dot = $group === 'statut_serveur' ? ' badge--dot' : '';

        return sprintf(
            '<span class="%s">%s</span>',
            Security::e(trim('badge badge--' . self::tone($group, $value) . $dot . ' ' . $class)),
            Security::e(self::label($group, $value))
        );
    }

    /**
     * Nombre au format français : espace fine insécable pour les milliers, virgule décimale (1 240 ; 4,7).
     */
    public static function number(int|float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, ',', "\u{202F}");
    }

    /**
     * Nombre compact pour les listes : 950, 12,4 k, 1,2 M (sans « ,0 » inutile).
     */
    public static function compactNumber(int|float $value): string
    {
        foreach ([1_000_000 => 'M', 1_000 => 'k'] as $unit => $suffix) {
            if ($value >= $unit) {
                $scaled = round($value / $unit, 1);

                return self::number($scaled, $scaled >= 100 || floor($scaled) === $scaled ? 0 : 1) . "\u{202F}" . $suffix;
            }
        }

        return self::number($value);
    }

    /**
     * Taille lisible d'un fichier à partir d'un nombre d'octets (Ko, Mo, Go).
     */
    public static function fileSize(int|float $bytes): string
    {
        $units = ['octets', 'Ko', 'Mo', 'Go', 'To'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return self::number($bytes, $index === 0 || $bytes >= 100 ? 0 : 1) . ' ' . $units[$index];
    }

    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /**
     * Date en français : « 16 octobre 2026 », ou « 16 octobre 2026 à 19:26 » avec l'heure. Vide si la date est invalide.
     */
    public static function date(?string $value, bool $withTime = false): string
    {
        $timestamp = $value !== null && $value !== '' ? strtotime($value) : false;
        if ($timestamp === false) {
            return '';
        }

        $text = sprintf('%d %s %s', (int) date('j', $timestamp), self::MONTHS[(int) date('n', $timestamp) - 1], date('Y', $timestamp));

        return $withTime ? $text . ' à ' . date('H:i', $timestamp) : $text;
    }

    /**
     * Durée écoulée lisible : « à l'instant », « il y a 5 min », « il y a 3 h », « il y a 2 jours », puis la date.
     */
    public static function ago(?string $value): string
    {
        $timestamp = $value !== null && $value !== '' ? strtotime($value) : false;
        if ($timestamp === false) {
            return '';
        }

        $elapsed = time() - $timestamp;

        return match (true) {
            $elapsed < 60          => 'à l\'instant',
            $elapsed < 3600        => sprintf('il y a %d min', intdiv($elapsed, 60)),
            $elapsed < 86400       => sprintf('il y a %d h', intdiv($elapsed, 3600)),
            $elapsed < 2 * 86400   => 'hier',
            $elapsed < 30 * 86400  => sprintf('il y a %d jours', intdiv($elapsed, 86400)),
            default                => self::date($value),
        };
    }

    /**
     * Note sous forme de cinq étoiles (arrondie à l'entier), annoncée « Note : 4,5 sur 5 » aux lecteurs d'écran.
     */
    public static function stars(float|int|string $rating, string $class = ''): string
    {
        $value  = max(0.0, min(5.0, (float) $rating));
        $filled = (int) round($value);
        $icons  = '';
        for ($i = 1; $i <= 5; $i++) {
            $icons .= self::icon('star', $i <= $filled ? 'stars__icon stars__icon--on' : 'stars__icon');
        }

        return sprintf(
            '<span class="%s" role="img" aria-label="Note : %s sur 5">%s</span>',
            Security::e(trim('stars ' . $class)),
            self::number($value, 1),
            $icons
        );
    }

    /**
     * Graphique en aire SVG généré côté serveur (aucune bibliothèque, compatible CSP) pour une série de mesures.
     *
     * @param list<int|float> $values Mesures dans l'ordre chronologique
     * @param float           $max    Valeur correspondant au haut du graphique (plafond de la ressource)
     * @param string          $tone   Teinte : nebula, accent ou success
     */
    public static function areaChart(array $values, float $max, string $tone, string $label): string
    {
        $count = count($values);
        if ($count < 2) {
            return '<p class="chart__empty">Pas encore assez de mesures pour tracer un graphique.</p>';
        }

        $width  = 600;
        $height = 160;
        $max    = max($max, (float) max($values), 1.0);
        $points = [];
        foreach (array_values($values) as $i => $value) {
            $x        = round($i * $width / ($count - 1), 1);
            $y        = round($height - 6 - ((float) $value / $max) * ($height - 12), 1);
            $points[] = $x . ',' . $y;
        }

        $line  = implode(' ', $points);
        $grid  = '';
        foreach ([0.25, 0.5, 0.75] as $ratio) {
            $y     = round($height - 6 - $ratio * ($height - 12), 1);
            $grid .= sprintf('<line class="chart__grid" x1="0" x2="%d" y1="%s" y2="%s"/>', $width, $y, $y);
        }

        return sprintf(
            '<svg class="chart chart--%s" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="%s">%s'
            . '<polygon class="chart__area" points="0,%d %s %d,%d"/>'
            . '<polyline class="chart__line" points="%s" vector-effect="non-scaling-stroke"/></svg>',
            Security::e(preg_replace('/[^a-z]/', '', $tone) ?? 'nebula'),
            $width,
            $height,
            Security::e($label),
            $grid,
            $height,
            $line,
            $width,
            $height,
            $line
        );
    }

    /**
     * Ligne de journal Minecraft échappée et colorée selon son niveau (INFO, WARN, ERROR) et son horodatage.
     */
    public static function logLine(string $line): string
    {
        $upper = strtoupper($line);
        $level = match (true) {
            str_contains($upper, 'ERROR') || str_contains($upper, 'SEVERE') || str_contains($upper, 'FATAL') => 'error',
            str_contains($upper, 'WARN')                                                                   => 'warn',
            str_contains($upper, '[BOÖTES') || str_contains($upper, '[BOOTES')                             => 'system',
            default                                                                                        => 'info',
        };

        $escaped = Security::e($line);
        $escaped = (string) preg_replace('/^(\[\d{2}:\d{2}:\d{2}\])/', '<span class="log-line__time">$1</span>', $escaped);

        return sprintf('<span class="log-line log-line--%s">%s</span>', $level, $escaped);
    }

    /**
     * Initiales d'un pseudonyme pour les avatars (deux caractères au plus).
     */
    public static function initials(string $pseudo): string
    {
        $parts = preg_split('/[\s_.-]+/u', trim($pseudo), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = count($parts) >= 2
            ? mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1)
            : mb_substr($pseudo, 0, 2);

        return mb_strtoupper($letters);
    }

    /**
     * Visuel carré d'un élément (icône de mod, logo de serveur) : l'image téléversée, sinon ses
     * initiales sur une teinte tirée du nom (un projet garde toujours la même couleur).
     */
    public static function tile(?string $token, string $name, string $class = ''): string
    {
        $classes = trim('tile ' . $class);
        if (MediaManager::isValidToken($token)) {
            return sprintf(
                '<img class="%s" src="%s" alt="" width="256" height="256" loading="lazy" decoding="async">',
                Security::e($classes),
                Security::e(MediaManager::url((string) $token))
            );
        }

        return sprintf('<span class="%s tile--hue-%d" aria-hidden="true">%s</span>', Security::e($classes), self::hue($name), Security::e(self::initials($name)));
    }

    /**
     * Photo de profil d'un compte, ou ses initiales.
     */
    public static function avatar(?string $token, string $pseudo, string $class = ''): string
    {
        $classes = trim('avatar ' . $class);
        if (MediaManager::isValidToken($token)) {
            return sprintf(
                '<img class="%s avatar--photo" src="%s" alt="" width="192" height="192" loading="lazy" decoding="async">',
                Security::e($classes),
                Security::e(MediaManager::url((string) $token))
            );
        }

        return sprintf('<span class="%s" aria-hidden="true">%s</span>', Security::e($classes), Security::e(self::initials($pseudo)));
    }

    /** Teinte déterministe (0 à 7) tirée d'un nom, pour les visuels par défaut. */
    public static function hue(string $seed): int
    {
        return crc32(mb_strtolower($seed)) % 8;
    }

    /**
     * Vue d'une liste : « liste » compacte (par défaut) ou « grille ». Le paramètre ?vue= est mémorisé
     * un an dans un cookie, comme le thème.
     */
    public static function listMode(): string
    {
        $requested = $_GET['vue'] ?? null;
        if (is_string($requested) && in_array($requested, self::LIST_MODES, true)) {
            if (($_COOKIE[self::LIST_MODE_COOKIE] ?? null) !== $requested && !headers_sent()) {
                setcookie(self::LIST_MODE_COOKIE, $requested, [
                    'expires'  => time() + 31536000,
                    'path'     => '/',
                    'secure'   => Http::isHttps(),
                    'samesite' => 'Lax',
                ]);
            }

            return $requested;
        }

        $saved = $_COOKIE[self::LIST_MODE_COOKIE] ?? null;

        return is_string($saved) && in_array($saved, self::LIST_MODES, true) ? $saved : 'liste';
    }

    private static function humanize(string $value): string
    {
        $text = mb_strtolower(str_replace('_', ' ', $value));

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * Thème choisi par le visiteur (cookie posé par le bouton de bascule), sombre par défaut.
     */
    public static function theme(): string
    {
        $theme = $_COOKIE[self::THEME_COOKIE] ?? self::DEFAULT_THEME;

        return $theme === self::THEME_LIGHT ? self::THEME_LIGHT : self::THEME_DARK;
    }
}
