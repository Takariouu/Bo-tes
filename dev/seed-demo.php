<?php

declare(strict_types=1);

/**
 * Données de démonstration de l'annuaire, des galeries et des pages Premium (développement uniquement).
 *
 *  - serveurs externes référencés (un par offre, statuts variés) ;
 *  - icônes, photos de profil, bannières et captures d'écran dessinées en pixel art par GD
 *    (aucune image n'est versionnée : tout est généré puis importé par MediaManager) ;
 *  - une page « Standard » et des pages « Personnalisées par un membre Premium » ;
 *  - archives de démonstration pour que le téléchargement fonctionne.
 *
 * Idempotent : un élément déjà présent n'est pas recréé. Lancé par dev/start.sh après l'import des
 * données de démonstration, ou à la main : php dev/seed-demo.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Bootes\\Src\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use Bootes\Config\AppConfig;
use Bootes\Config\Database;
use Bootes\Src\MediaManager;
use Bootes\Src\PageLayout;
use Bootes\Src\ResourceManager;

AppConfig::load();
$db    = Database::getInstance();
$media = new MediaManager($db);
$tmp   = sys_get_temp_dir() . '/bootes-demo-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);

// ---------------------------------------------------------------------------------------------
// Pixel art procédural (GD) : rendu en basse définition puis agrandi sans lissage
// ---------------------------------------------------------------------------------------------
final class DemoArt
{
    private const GLYPHS = [
        'pioche'    => ['.######.', '#..##..#', '...##...', '...##...', '...##...', '...##...', '...##...', '...##...'],
        'epee'      => ['......##', '.....###', '....###.', '#..###..', '.####...', '..##....', '.#.##...', '#...#...'],
        'carte'     => ['########', '#..#...#', '#.##.#.#', '#....#.#', '##.###.#', '#..#...#', '#.##.#.#', '########'],
        'etoile'    => ['...##...', '...##...', '########', '.######.', '..####..', '.##..##.', '.#....#.', '........'],
        'piece'     => ['..####..', '.#....#.', '#..##..#', '#.#....#', '#..##..#', '#....#.#', '.#.##.#.', '..####..'],
        'eclair'    => ['....###.', '...###..', '..###...', '.######.', '...###..', '..###...', '.##.....', '#.......'],
        'engrenage' => ['.#.##.#.', '########', '.##..##.', '##....##', '##....##', '.##..##.', '########', '.#.##.#.'],
        'coeur'     => ['........', '.##..##.', '########', '########', '.######.', '..####..', '...##...', '........'],
        'cube'      => ['...##...', '.##..##.', '#......#', '#.#..#.#', '#..##..#', '#..##..#', '.#.##.#.', '..####..'],
    ];

    /** Icône 256 × 256 : texture de bloc bruitée, surmontée d'un symbole. */
    public static function icon(string $file, string $seed, array $base, array $top, string $glyph): void
    {
        mt_srand(crc32($seed));
        $img = imagecreatetruecolor(16, 16);
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $color = $y < 4 - (int) (mt_rand(0, 10) > 7) ? $top : $base;
                imagesetpixel($img, $x, $y, self::color($img, self::shade($color, mt_rand(78, 118) / 100)));
            }
        }
        $edge = self::color($img, self::shade($base, 0.55));
        imagerectangle($img, 0, 0, 15, 15, $edge);

        $light  = self::color($img, [250, 250, 255]);
        $shadow = self::color($img, self::shade($base, 0.35));
        foreach (self::GLYPHS[$glyph] as $gy => $row) {
            foreach (str_split($row) as $gx => $cell) {
                if ($cell === '#') {
                    imagesetpixel($img, 5 + $gx, 5 + $gy, $shadow);
                    imagesetpixel($img, 4 + $gx, 4 + $gy, $light);
                }
            }
        }
        self::save($img, $file, 16);
    }

    /** Tête de joueur 192 × 192 (8 × 8 pixels) : peau, cheveux, yeux, bouche. */
    public static function face(string $file, string $seed): void
    {
        mt_srand(crc32($seed));
        $skins = [[247, 205, 170], [224, 172, 128], [181, 125, 85], [120, 80, 55], [255, 220, 190]];
        $hairs = [[60, 40, 25], [20, 20, 25], [200, 150, 60], [170, 60, 30], [90, 60, 140], [230, 230, 240]];
        $eyes  = [[60, 110, 220], [60, 160, 90], [110, 70, 40], [150, 60, 200]];
        $skin  = $skins[mt_rand(0, count($skins) - 1)];
        $hair  = $hairs[mt_rand(0, count($hairs) - 1)];
        $eye   = $eyes[mt_rand(0, count($eyes) - 1)];

        $img = imagecreatetruecolor(8, 8);
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $isHair = $y < 2 || ($y < 4 && ($x === 0 || $x === 7)) || ($y === 2 && mt_rand(0, 3) === 0);
                $color  = $isHair ? self::shade($hair, mt_rand(85, 115) / 100) : self::shade($skin, mt_rand(92, 106) / 100);
                imagesetpixel($img, $x, $y, self::color($img, $color));
            }
        }
        $white = self::color($img, [245, 245, 245]);
        $iris  = self::color($img, $eye);
        imagesetpixel($img, 1, 4, $white);
        imagesetpixel($img, 2, 4, $iris);
        imagesetpixel($img, 5, 4, $iris);
        imagesetpixel($img, 6, 4, $white);
        $mouth = self::color($img, self::shade($skin, 0.6));
        imagefilledrectangle($img, 3, 6, 4, 6, $mouth);
        imagesetpixel($img, 2, 6, self::color($img, self::shade($skin, 0.8)));
        imagesetpixel($img, 5, 6, self::color($img, self::shade($skin, 0.8)));
        self::save($img, $file, 24);
    }

    /**
     * Paysage en blocs (bannière 1600 × 400 ou capture 1280 × 800) selon une ambiance :
     * jour, couchant, nuit, neige, nether, ocean, foret.
     */
    public static function landscape(string $file, string $seed, string $mood, int $width, int $height): void
    {
        mt_srand(crc32($seed . $mood));
        $w   = intdiv($width, 4);
        $h   = intdiv($height, 4);
        $img = imagecreatetruecolor($w, $h);

        $palettes = [
            'jour'     => ['sky' => [[112, 178, 255], [190, 226, 255]], 'far' => [120, 150, 200], 'top' => [96, 170, 70],  'soil' => [134, 96, 67],  'stone' => [125, 125, 130]],
            'couchant' => ['sky' => [[255, 120, 90], [255, 200, 120]],  'far' => [150, 90, 120],  'top' => [80, 140, 60],  'soil' => [120, 80, 55],  'stone' => [110, 100, 110]],
            'nuit'     => ['sky' => [[10, 14, 40], [40, 50, 100]],       'far' => [40, 50, 90],    'top' => [40, 90, 50],   'soil' => [70, 50, 40],   'stone' => [70, 70, 85]],
            'neige'    => ['sky' => [[150, 190, 230], [220, 235, 250]],  'far' => [170, 190, 215], 'top' => [240, 245, 250], 'soil' => [120, 100, 90], 'stone' => [140, 145, 155]],
            'nether'   => ['sky' => [[60, 5, 10], [140, 30, 20]],        'far' => [110, 20, 20],   'top' => [150, 40, 40],  'soil' => [110, 30, 30],  'stone' => [80, 20, 25]],
            'ocean'    => ['sky' => [[90, 170, 240], [200, 235, 255]],   'far' => [110, 160, 200], 'top' => [220, 205, 140], 'soil' => [200, 180, 120], 'stone' => [130, 130, 135]],
            'foret'    => ['sky' => [[120, 190, 230], [210, 240, 220]],  'far' => [70, 120, 90],   'top' => [70, 140, 60],  'soil' => [110, 80, 55],  'stone' => [115, 115, 120]],
        ];
        $p = $palettes[$mood] ?? $palettes['jour'];

        // Ciel en dégradé, étoiles ou nuages
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            imageline($img, 0, $y, $w, $y, self::color($img, self::mix($p['sky'][0], $p['sky'][1], $t)));
        }
        if ($mood === 'nuit') {
            for ($i = 0; $i < (int) ($w * $h / 90); $i++) {
                imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, (int) ($h * 0.6)), self::color($img, [255, 255, 230]));
            }
            imagefilledrectangle($img, (int) ($w * 0.8), (int) ($h * 0.12), (int) ($w * 0.8) + 6, (int) ($h * 0.12) + 6, self::color($img, [235, 235, 210]));
        } elseif ($mood !== 'nether') {
            $sun = $mood === 'couchant' ? [255, 230, 150] : [255, 250, 200];
            imagefilledrectangle($img, (int) ($w * 0.15), (int) ($h * 0.1), (int) ($w * 0.15) + 7, (int) ($h * 0.1) + 7, self::color($img, $sun));
            for ($i = 0; $i < 5; $i++) {
                $cx = mt_rand(0, $w);
                $cy = mt_rand(2, (int) ($h * 0.35));
                imagefilledrectangle($img, $cx, $cy, $cx + mt_rand(10, 22), $cy + 3, self::color($img, [255, 255, 255]));
                imagefilledrectangle($img, $cx + 3, $cy - 2, $cx + mt_rand(8, 14), $cy, self::color($img, [255, 255, 255]));
            }
        }

        // Montagnes lointaines puis relief principal (marche aléatoire)
        $far = (int) ($h * 0.45);
        for ($x = 0; $x < $w; $x++) {
            $far += mt_rand(-1, 1);
            $far  = max((int) ($h * 0.3), min((int) ($h * 0.6), $far));
            imageline($img, $x, $far, $x, $h, self::color($img, $p['far']));
        }

        $ground  = (int) ($h * 0.68);
        $water   = $mood === 'ocean' ? (int) ($h * 0.72) : -1;
        $heights = [];
        for ($x = 0; $x < $w; $x++) {
            if ($x % 3 === 0) {
                $ground += mt_rand(-1, 1);
                $ground  = max((int) ($h * 0.55), min((int) ($h * 0.82), $ground));
            }
            $heights[$x] = $ground;
            for ($y = $ground; $y < $h; $y++) {
                $depth = $y - $ground;
                $color = $depth < 2 ? $p['top'] : ($depth < 6 ? $p['soil'] : $p['stone']);
                if ($mood === 'nether' && mt_rand(0, 60) === 0) {
                    $color = [255, 200, 80]; // pierre lumineuse
                }
                imagesetpixel($img, $x, $y, self::color($img, self::shade($color, mt_rand(85, 112) / 100)));
            }
        }
        if ($water > 0) {
            for ($x = 0; $x < $w; $x++) {
                if ($heights[$x] > $water) {
                    imageline($img, $x, $water, $x, $heights[$x], self::color($img, self::shade([50, 110, 210], mt_rand(90, 110) / 100)));
                }
            }
        }
        if ($mood === 'nether') {
            for ($i = 0; $i < 3; $i++) {
                $lx = mt_rand(0, $w - 20);
                imagefilledrectangle($img, $lx, $heights[$lx] + 1, $lx + mt_rand(8, 18), $heights[$lx] + 3, self::color($img, [255, 120, 20]));
            }
        }

        // Arbres et maisons posés sur le relief
        $treeLeaves = $mood === 'neige' ? [230, 240, 245] : ($mood === 'couchant' ? [70, 120, 50] : [50, 130, 50]);
        $trees      = $mood === 'foret' ? 18 : ($mood === 'nether' ? 0 : 7);
        for ($i = 0; $i < $trees; $i++) {
            $tx = mt_rand(3, $w - 4);
            $ty = $heights[$tx];
            if ($water > 0 && $ty > $water) {
                continue;
            }
            imagefilledrectangle($img, $tx, $ty - 5, $tx, $ty - 1, self::color($img, [100, 70, 40]));
            imagefilledrectangle($img, $tx - 2, $ty - 9, $tx + 2, $ty - 5, self::color($img, self::shade($treeLeaves, mt_rand(85, 110) / 100)));
            imagefilledrectangle($img, $tx - 1, $ty - 10, $tx + 1, $ty - 10, self::color($img, self::shade($treeLeaves, 1.1)));
        }
        if (in_array($mood, ['jour', 'couchant', 'neige', 'foret'], true)) {
            $hx = mt_rand((int) ($w * 0.4), (int) ($w * 0.7));
            $hy = $heights[$hx];
            imagefilledrectangle($img, $hx, $hy - 7, $hx + 10, $hy - 1, self::color($img, [180, 140, 90]));
            imagefilledrectangle($img, $hx + 4, $hy - 4, $hx + 6, $hy - 1, self::color($img, [90, 60, 35]));
            imagefilledpolygon($img, [$hx - 1, $hy - 7, $hx + 5, $hy - 12, $hx + 11, $hy - 7], self::color($img, [150, 60, 50]));
        }

        self::save($img, $file, 4);
    }

    private static function save(GdImage $img, string $file, int $scale): void
    {
        $out = imagecreatetruecolor(imagesx($img) * $scale, imagesy($img) * $scale);
        imagecopyresized($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), imagesx($img), imagesy($img));
        imagepng($out, $file);
        imagedestroy($out);
        imagedestroy($img);
    }

    private static function color(GdImage $img, array $rgb): int
    {
        return imagecolorallocate($img, max(0, min(255, (int) $rgb[0])), max(0, min(255, (int) $rgb[1])), max(0, min(255, (int) $rgb[2])));
    }

    private static function shade(array $rgb, float $factor): array
    {
        return [$rgb[0] * $factor, $rgb[1] * $factor, $rgb[2] * $factor];
    }

    private static function mix(array $a, array $b, float $t): array
    {
        return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t];
    }
}

// ---------------------------------------------------------------------------------------------
// Aides
// ---------------------------------------------------------------------------------------------
$userId = static function (string $pseudo) use ($db): ?int {
    $stmt = $db->prepare('SELECT id_utilisateur FROM UTILISATEUR WHERE pseudo = :p');
    $stmt->execute([':p' => $pseudo]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : null;
};

$isPremium = static function (int $id) use ($db): bool {
    $stmt = $db->prepare('SELECT type_compte FROM UTILISATEUR WHERE id_utilisateur = :id');
    $stmt->execute([':id' => $id]);

    return strtoupper((string) $stmt->fetchColumn()) === 'PREMIUM';
};

/** Importe une image générée si la fiche n'en a pas encore pour ce rôle (galerie : si elle est vide). */
$attach = static function (string $type, int $targetId, int $ownerId, string $role, callable $draw, ?string $caption = null, bool $force = false) use ($media, $tmp, $isPremium): void {
    if (!$force && $media->countFor($type, $targetId, $role) > 0) {
        return;
    }
    $file = $tmp . '/' . bin2hex(random_bytes(6)) . '.png';
    $draw($file);
    $result = $media->storeFile($file, $ownerId, $type, $targetId, $role, $caption, MediaManager::galleryLimit($isPremium($ownerId)));
    if (!$result['success']) {
        fwrite(STDERR, sprintf("  ! %s #%d %s : %s\n", $type, $targetId, $role, $result['message']));
    }
};

$gallery = static function (string $type, int $targetId, int $ownerId, array $shots, string $seed) use ($media, $attach): void {
    if ($media->countFor($type, $targetId, MediaManager::ROLE_GALLERY) > 0) {
        return;
    }
    foreach ($shots as $i => [$mood, $caption]) {
        $attach($type, $targetId, $ownerId, MediaManager::ROLE_GALLERY, static fn (string $f) => DemoArt::landscape($f, $seed . $i, $mood, 1280, 800), $caption, true);
    }
};

$saveLayout = static function (string $type, int $targetId, int $ownerId, string $theme, array $blocks) use ($db): void {
    $exists = $db->prepare('SELECT 1 FROM PAGE_PERSONNALISEE WHERE type_cible = :t AND id_cible = :id');
    $exists->execute([':t' => $type, ':id' => $targetId]);
    if ($exists->fetchColumn() === false) {
        (new PageLayout($db))->save($type, $targetId, $theme, $blocks, $ownerId);
    }
};

// ---------------------------------------------------------------------------------------------
// 1. Photos de profil (Lyra garde ses initiales : affichage par défaut)
// ---------------------------------------------------------------------------------------------
foreach (['Takariou', 'Arcturus', 'Vega_Builds', 'Nova', 'Orion_MC'] as $pseudo) {
    $id = $userId($pseudo);
    if ($id !== null) {
        $attach(MediaManager::TARGET_USER, $id, $id, MediaManager::ROLE_AVATAR, static fn (string $f) => DemoArt::face($f, $pseudo));
    }
}

// ---------------------------------------------------------------------------------------------
// 2. Serveurs externes de l'annuaire
// ---------------------------------------------------------------------------------------------
$externalServers = [
    [
        'owner' => 'Vega_Builds', 'nom' => 'Royaume de Pixelia', 'host' => 'play.pixelia-mc.fr', 'port' => 25565,
        'version' => '1.21.4', 'mode' => 'SURVIE', 'statut' => 'EN_LIGNE', 'joueurs' => 87, 'slots' => 150, 'checked' => 2,
        'tags' => ',survie,rp,economie,communautaire,', 'site' => 'https://pixelia-mc.fr', 'discord' => 'https://discord.gg/pixelia',
        'resume' => 'Survie semi-RP médiévale : royaumes, métiers, marché entre joueurs et événements chaque semaine.',
        'description' => "Pixelia est un royaume persistant où chaque joueur choisit un métier : forgeron, marchand, architecte ou explorateur.\n\nLes villes sont gérées par leurs habitants : élections du bourgmestre, impôts votés en conseil et grands chantiers collectifs.\n\n- Économie 100 % joueurs, sans boutique d'objets\n- Monde protégé, sans téléportation instantanée\n- Équipe de modération présente tous les soirs",
        'icon' => [[150, 90, 210], [190, 140, 240], 'cube'], 'banner' => 'couchant', 'premium' => true,
    ],
    [
        'owner' => 'Nova', 'nom' => 'Les Terres d\'Eldoria', 'host' => 'eldoria-mc.fr', 'port' => 25565,
        'version' => '1.20.1', 'mode' => 'AVENTURE', 'statut' => 'EN_LIGNE', 'joueurs' => 23, 'slots' => 60, 'checked' => 4,
        'tags' => ',aventure,rp,', 'site' => null, 'discord' => 'https://discord.gg/eldoria',
        'resume' => 'Quêtes, donjons et cartes d\'aventure faites main par la communauté.',
        'description' => "Eldoria propose des aventures scénarisées à vivre seul ou en groupe : donjons, énigmes et boss de fin de chapitre.\n\nUne nouvelle région ouvre chaque mois, conçue par nos bâtisseurs bénévoles.",
        'icon' => [[70, 150, 80], [110, 190, 110], 'carte'], 'banner' => null, 'premium' => false,
    ],
    [
        'owner' => 'Arcturus', 'nom' => 'SkyForge Network', 'host' => 'play.skyforge-network.net', 'port' => 25565,
        'version' => '1.21.1', 'mode' => 'AVENTURE', 'statut' => 'EN_LIGNE', 'joueurs' => 412, 'slots' => 1000, 'checked' => 1,
        'tags' => ',skyblock,mini-jeux,bedwars,pvp,', 'site' => 'https://skyforge-network.net', 'discord' => 'https://discord.gg/skyforge',
        'resume' => 'Réseau mini-jeux : Skyblock, BedWars et PvP classé, saisons de trois mois.',
        'description' => "SkyForge réunit plusieurs modes de jeu sur un même réseau : Skyblock coopératif, BedWars à 4 équipes et arènes PvP classées.\n\nChaque saison remet les classements à zéro et apporte de nouvelles îles à conquérir.",
        'icon' => [[50, 120, 210], [110, 190, 250], 'eclair'], 'banner' => 'ocean', 'premium' => true,
    ],
    [
        'owner' => 'Orion_MC', 'nom' => 'Faction Nocturne', 'host' => 'faction-nocturne.fr', 'port' => 25570,
        'version' => '1.8.9', 'mode' => 'SURVIE', 'statut' => 'HORS_LIGNE', 'joueurs' => 0, 'slots' => 200, 'checked' => 12,
        'tags' => ',faction,pvp,', 'site' => null, 'discord' => null,
        'resume' => 'Faction PvP old-school, assauts le samedi soir.',
        'description' => "Construisez votre base, alliez-vous ou trahissez : la carte est réinitialisée à chaque saison.",
        'icon' => [[170, 40, 50], [220, 80, 80], 'epee'], 'banner' => null, 'premium' => false,
    ],
    [
        'owner' => 'Lyra', 'nom' => 'Atelier Créatif Ludus', 'host' => 'ludus-creatif.fr', 'port' => 25565,
        'version' => '1.21.4', 'mode' => 'CREATIF', 'statut' => 'HORS_LIGNE', 'joueurs' => 0, 'slots' => 0, 'checked' => null,
        'tags' => ',creatif,communautaire,', 'site' => null, 'discord' => null,
        'resume' => 'Parcelles créatives et concours de construction entre amis.',
        'description' => "Un petit serveur créatif ouvert à tous les bâtisseurs, avec un thème de construction chaque semaine.",
        'icon' => null, 'banner' => null, 'premium' => false,
    ],
];

$insertServer = $db->prepare('
    INSERT INTO SERVEUR (id_proprietaire, origine, nom_serveur, adresse_ip, port, version_mc, type_loader, mode_jeu, statut,
                         joueurs_connectes, slots_max, ram_allouee, description, resume, etiquettes, site_web, discord,
                         date_verification, est_public, date_creation)
    VALUES (:owner, \'EXTERNE\', :nom, :host, :port, :version, \'VANILLA\', :mode, :statut, :joueurs, :slots, 0, :description,
            :resume, :tags, :site, :discord, :checked, 1, :created)
');
$serverIds = [];
foreach ($externalServers as $index => $srv) {
    $owner = $userId($srv['owner']);
    if ($owner === null) {
        continue;
    }
    $find = $db->prepare('SELECT id_serveur FROM SERVEUR WHERE adresse_ip = :h AND port = :p');
    $find->execute([':h' => $srv['host'], ':p' => $srv['port']]);
    $id = $find->fetchColumn();
    if ($id === false) {
        $insertServer->execute([
            ':owner'       => $owner,
            ':nom'         => $srv['nom'],
            ':host'        => $srv['host'],
            ':port'        => $srv['port'],
            ':version'     => $srv['version'],
            ':mode'        => $srv['mode'],
            ':statut'      => $srv['statut'],
            ':joueurs'     => $srv['joueurs'],
            ':slots'       => $srv['slots'],
            ':description' => $srv['description'],
            ':resume'      => $srv['resume'],
            ':tags'        => $srv['tags'],
            ':site'        => $srv['site'],
            ':discord'     => $srv['discord'],
            ':checked'     => $srv['checked'] === null ? null : date('Y-m-d H:i:s', time() - $srv['checked'] * 60),
            ':created'     => date('Y-m-d H:i:s', time() - (20 - $index * 3) * 86400),
        ]);
        $id = $db->lastInsertId();
        echo "Serveur externe ajouté : {$srv['nom']}\n";
    }
    $id = (int) $id;
    $serverIds[$srv['nom']] = [$id, $owner];

    if ($srv['icon'] !== null) {
        [$base, $top, $glyph] = $srv['icon'];
        $attach(MediaManager::TARGET_SERVER, $id, $owner, MediaManager::ROLE_ICON, static fn (string $f) => DemoArt::icon($f, $srv['nom'], $base, $top, $glyph));
    }
    if ($srv['banner'] !== null) {
        $attach(MediaManager::TARGET_SERVER, $id, $owner, MediaManager::ROLE_BANNER, static fn (string $f) => DemoArt::landscape($f, $srv['nom'], $srv['banner'], 1600, 400));
    }
}

// Galeries : Pixelia (Premium, 6 images) et Eldoria (Standard, 4 images = limite de l'offre)
if (isset($serverIds['Royaume de Pixelia'])) {
    [$id, $owner] = $serverIds['Royaume de Pixelia'];
    $gallery(MediaManager::TARGET_SERVER, $id, $owner, [
        ['couchant', 'La capitale au coucher du soleil'],
        ['foret', 'Le bois des Murmures, réserve de chasse royale'],
        ['neige', 'Le col du Nord et son relais de marchands'],
        ['nuit', 'Fête des lanternes sur la place du marché'],
        ['ocean', 'Le port franc et ses comptoirs'],
        ['jour', 'Les champs communaux au printemps'],
    ], 'pixelia');
    $saveLayout(MediaManager::TARGET_SERVER, $id, $owner, 'amethyste', [
        ['type' => 'annonce', 'ton' => 'evenement', 'texte' => "**Tournoi des bâtisseurs** samedi à 20 h sur la place du marché : 3 000 écus et un titre de noblesse à gagner !"],
        ['type' => 'presentation', 'colonnes' => 2],
        ['type' => 'points_forts', 'titre' => 'Pourquoi rejoindre Pixelia', 'points' => [
            'Économie 100 % entre joueurs', 'Villes gérées par leurs habitants', 'Métiers avec progression', 'Monde protégé contre le vol',
            'Événements chaque week-end', 'Équipe présente tous les soirs',
        ]],
        ['type' => 'galerie', 'titre' => 'Le royaume en images', 'style' => 'grille'],
        ['type' => 'texte', 'titre' => 'Règles du royaume', 'colonnes' => 1, 'encadre' => true, 'contenu' => "- Respect de chaque joueur, en jeu comme sur Discord\n- Pas de triche, de client modifié ni d'exploitation de bug\n- Les constructions des autres ne se touchent pas\n- Le commerce se fait au marché ou par contrat signé"],
        ['type' => 'liens', 'titre' => 'Liens utiles', 'liens' => [
            ['libelle' => 'Wiki du royaume', 'url' => 'https://pixelia-mc.fr/wiki'],
            ['libelle' => 'Règlement complet', 'url' => 'https://pixelia-mc.fr/reglement'],
            ['libelle' => 'Voter pour Pixelia', 'url' => 'https://pixelia-mc.fr/vote'],
        ]],
    ]);
}
if (isset($serverIds['Les Terres d\'Eldoria'])) {
    [$id, $owner] = $serverIds['Les Terres d\'Eldoria'];
    $gallery(MediaManager::TARGET_SERVER, $id, $owner, [
        ['foret', 'L\'entrée du premier donjon'],
        ['nuit', 'Le camp des aventuriers'],
        ['neige', 'Les cimes gelées, chapitre 3'],
        ['jour', 'Le village de départ'],
    ], 'eldoria');
}
if (isset($serverIds['SkyForge Network'])) {
    [$id, $owner] = $serverIds['SkyForge Network'];
    $gallery(MediaManager::TARGET_SERVER, $id, $owner, [
        ['ocean', 'Les îles de départ du Skyblock'],
        ['nether', 'L\'arène PvP de la saison 4'],
        ['couchant', 'Le hall des BedWars'],
    ], 'skyforge');
    $saveLayout(MediaManager::TARGET_SERVER, $id, $owner, 'ocean', [
        ['type' => 'presentation', 'colonnes' => 1],
        ['type' => 'galerie', 'titre' => 'Nos modes de jeu', 'style' => 'carrousel'],
        ['type' => 'points_forts', 'titre' => 'Le réseau en bref', 'points' => ['Skyblock coopératif jusqu\'à 4', 'BedWars 4 × 4 classé', 'Saisons de 3 mois', 'Anti-triche maison']],
        ['type' => 'texte', 'titre' => 'Rejoindre l\'équipe', 'colonnes' => 1, 'encadre' => true, 'contenu' => "Nous recrutons des **modérateurs** et des **bâtisseurs**. Candidatures ouvertes sur notre Discord, salon #recrutement."],
    ]);
}

// Avis sur les serveurs externes
$reviews = [
    ['Royaume de Pixelia', 'Nova', 5, 'Ambiance incroyable, la fête des lanternes valait le détour !'],
    ['Royaume de Pixelia', 'Orion_MC', 4, 'Économie bien pensée, il faut un peu de temps pour se lancer.'],
    ['Royaume de Pixelia', 'Lyra', 5, 'Le plus beau serveur RP que j\'ai testé.'],
    ['Les Terres d\'Eldoria', 'Lyra', 4, 'Donjons très bien construits, vivement le chapitre 4.'],
    ['SkyForge Network', 'Nova', 4, 'BedWars nerveux et sans lag.'],
    ['SkyForge Network', 'Takariou', 5, 'Réseau solide, équipe réactive.'],
];
$insertReview = $db->prepare('INSERT INTO AVIS_SERVEUR (id_serveur, id_utilisateur, note, commentaire, date_avis) VALUES (:s, :u, :n, :c, :d)');
foreach ($reviews as $i => [$serverName, $pseudo, $note, $comment]) {
    $author = $userId($pseudo);
    if ($author === null || !isset($serverIds[$serverName])) {
        continue;
    }
    $exists = $db->prepare('SELECT 1 FROM AVIS_SERVEUR WHERE id_serveur = :s AND id_utilisateur = :u');
    $exists->execute([':s' => $serverIds[$serverName][0], ':u' => $author]);
    if ($exists->fetchColumn() === false) {
        $insertReview->execute([':s' => $serverIds[$serverName][0], ':u' => $author, ':n' => $note, ':c' => $comment, ':d' => date('Y-m-d H:i:s', time() - ($i + 1) * 7200)]);
    }
}

// ---------------------------------------------------------------------------------------------
// 3. Serveurs hébergés : présentation d'annuaire, icônes, une page Premium
// ---------------------------------------------------------------------------------------------
$hosted = [
    'Nébuleuse Survie'  => [',survie,economie,communautaire,', 'Survie calme et entraide : villes, économie entre joueurs, grands chantiers.', [[96, 160, 70], [130, 200, 90], 'pioche']],
    'Arcturus Créatif'  => [',creatif,communautaire,', 'Parcelles illimitées et concours de construction mensuels.', [[210, 90, 150], [240, 140, 190], 'etoile']],
    'Bouvier Hardcore'  => [',hardcore,survie,vanilla,', 'Une seule vie par saison : jusqu\'où irez-vous ?', [[90, 90, 100], [140, 140, 150], 'coeur']],
    'Station Orbitale'  => [',modde,aventure,', 'Modpack technique : fusées, usines et exploration spatiale.', [[100, 110, 130], [150, 160, 180], 'engrenage']],
];
foreach ($hosted as $name => [$tags, $resume, [$base, $top, $glyph]]) {
    $stmt = $db->prepare("SELECT id_serveur, id_proprietaire FROM SERVEUR WHERE nom_serveur = :n AND origine = 'HEBERGE'");
    $stmt->execute([':n' => $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        continue;
    }
    [$id, $owner] = [(int) $row['id_serveur'], (int) $row['id_proprietaire']];
    $db->prepare("UPDATE SERVEUR SET etiquettes = :t, resume = :r WHERE id_serveur = :id AND etiquettes = ''")->execute([':t' => $tags, ':r' => $resume, ':id' => $id]);
    $attach(MediaManager::TARGET_SERVER, $id, $owner, MediaManager::ROLE_ICON, static fn (string $f) => DemoArt::icon($f, $name, $base, $top, $glyph));

    if ($name === 'Arcturus Créatif') {
        $attach(MediaManager::TARGET_SERVER, $id, $owner, MediaManager::ROLE_BANNER, static fn (string $f) => DemoArt::landscape($f, $name, 'jour', 1600, 400));
        $gallery(MediaManager::TARGET_SERVER, $id, $owner, [['jour', 'La parcelle gagnante d\'octobre'], ['foret', 'Le parc botanique collectif'], ['neige', 'Village d\'hiver']], 'arcturus');
        $saveLayout(MediaManager::TARGET_SERVER, $id, $owner, 'emeraude', [
            ['type' => 'annonce', 'ton' => 'succes', 'texte' => 'Le thème du concours de novembre est **« Cité suspendue »** : inscriptions ouvertes !'],
            ['type' => 'presentation', 'colonnes' => 1],
            ['type' => 'galerie', 'titre' => 'Constructions des joueurs', 'style' => 'carrousel'],
        ]);
    }
    if ($name === 'Nébuleuse Survie') {
        $gallery(MediaManager::TARGET_SERVER, $id, $owner, [['jour', 'Le spawn'], ['couchant', 'La grande muraille']], 'nebuleuse');
    }
}

// ---------------------------------------------------------------------------------------------
// 4. Ressources : catégories, chargeurs, icônes, galeries, une page Premium
// ---------------------------------------------------------------------------------------------
$resources = [
    'Stellar Tools'         => [',construction,utilitaire,', 'FABRIC',   [[230, 160, 40], [250, 200, 90], 'pioche']],
    'Orbital Minimap'       => [',carte,utilitaire,', 'FABRIC',          [[40, 150, 150], [90, 200, 190], 'carte']],
    'BootesEssentials'      => [',administration,utilitaire,', 'PAPER',  [[80, 90, 200], [130, 140, 240], 'engrenage']],
    'Nebula Economy'        => [',economie,administration,', 'PAPER',    [[200, 160, 40], [240, 210, 90], 'piece']],
    'Constellation Pack'    => [',technologie,aventure,magie,', 'FORGE', [[120, 70, 180], [170, 120, 220], 'cube']],
    'Vide Spatial Skyblock' => [',aventure,mini-jeux,', 'FABRIC',        [[60, 140, 220], [120, 190, 250], 'etoile']],
];
foreach ($resources as $name => [$categories, $loader, [$base, $top, $glyph]]) {
    $stmt = $db->prepare('SELECT id_ressource, id_auteur FROM RESSOURCE WHERE nom_ressource = :n');
    $stmt->execute([':n' => $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        continue;
    }
    [$id, $author] = [(int) $row['id_ressource'], (int) $row['id_auteur']];
    $db->prepare("UPDATE RESSOURCE SET categories = :c, chargeur = :l WHERE id_ressource = :id AND categories = ''")->execute([':c' => $categories, ':l' => $loader, ':id' => $id]);
    $attach(MediaManager::TARGET_RESOURCE, $id, $author, MediaManager::ROLE_ICON, static fn (string $f) => DemoArt::icon($f, $name, $base, $top, $glyph));

    if ($name === 'Stellar Tools') {
        $attach(MediaManager::TARGET_RESOURCE, $id, $author, MediaManager::ROLE_BANNER, static fn (string $f) => DemoArt::landscape($f, $name, 'nuit', 1600, 400));
        $gallery(MediaManager::TARGET_RESOURCE, $id, $author, [
            ['jour', 'Mode miroir : une cathédrale symétrique en dix minutes'],
            ['couchant', 'La règle affiche les distances bloc par bloc'],
            ['foret', 'Remplissage de zone pour les grands terrassements'],
            ['neige', 'Aperçu des formes avant validation'],
            ['ocean', 'Construction d\'un port en mode miroir'],
        ], 'stellar');
        $saveLayout(MediaManager::TARGET_RESOURCE, $id, $author, 'ambre', [
            ['type' => 'presentation', 'colonnes' => 2],
            ['type' => 'points_forts', 'titre' => 'Fonctionnalités', 'points' => ['Baguette de construction', 'Règle de mesure', 'Mode miroir sur 1 ou 2 axes', 'Remplissage et vidage de zones', 'Annulation sur 50 actions', 'Compatible serveur et solo']],
            ['type' => 'galerie', 'titre' => 'En images', 'style' => 'grille'],
            ['type' => 'texte', 'titre' => 'Installation', 'colonnes' => 1, 'encadre' => false, 'contenu' => "- Installez Fabric Loader 0.16 ou plus récent\n- Ajoutez Fabric API dans le dossier mods\n- Déposez le fichier Stellar Tools téléchargé\n- Lancez le jeu : les outils sont dans l'onglet Outils"],
            ['type' => 'liens', 'titre' => 'Liens', 'liens' => [['libelle' => 'Code source', 'url' => 'https://git.example-forge.fr/vega/stellar-tools'], ['libelle' => 'Signaler un bug', 'url' => 'https://git.example-forge.fr/vega/stellar-tools/issues']]],
        ]);
    }
    if ($name === 'Nebula Economy') {
        $gallery(MediaManager::TARGET_RESOURCE, $id, $author, [['jour', 'Le marché d\'une ville'], ['couchant', 'Interface de la banque'], ['foret', 'Boutiques de joueurs']], 'nebula');
    }
    if ($name === 'Orbital Minimap') {
        $gallery(MediaManager::TARGET_RESOURCE, $id, $author, [['nuit', 'Mode nuit de la mini-carte'], ['jour', 'Points de passage partagés']], 'orbital');
    }
}

// ---------------------------------------------------------------------------------------------
// 5. Archives de démonstration (le bouton « Télécharger » des listes fonctionne)
// ---------------------------------------------------------------------------------------------
$archiveDir = AppConfig::uploadsPath() . '/ressources';
AppConfig::ensureDirectory($archiveDir);
$files = $db->query('SELECT f.nom_fichier, f.chemin_fichier, r.nom_ressource, r.type_ressource FROM FICHIER_RESSOURCE f INNER JOIN RESSOURCE r ON r.id_ressource = f.id_ressource')->fetchAll(PDO::FETCH_ASSOC);
foreach ($files as $file) {
    $path = $archiveDir . '/' . basename((string) $file['chemin_fichier']);
    if (basename((string) $file['chemin_fichier']) === '' || is_file($path)) {
        continue;
    }
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('LISEZMOI.txt', sprintf("%s — archive de démonstration Boötes.\n", $file['nom_ressource']));
    $zip->addFromString(strtoupper((string) $file['type_ressource']) === 'PLUGIN' ? 'plugin.yml' : 'fabric.mod.json', '{"demo": true}');
    $zip->close();
}

array_map('unlink', glob($tmp . '/*.png') ?: []);
rmdir($tmp);
echo "Données de démonstration de l'annuaire et des galeries prêtes.\n";
