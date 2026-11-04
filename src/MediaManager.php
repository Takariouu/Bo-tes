<?php

declare(strict_types=1);

namespace Bootes\Src;

use Bootes\Config\AppConfig;
use finfo;
use GdImage;
use PDO;
use PDOException;

/**
 * Images téléversées : icônes de mods, plugins, modpacks et serveurs, photos de profil, bannières et
 * galeries.
 *
 * Chaque image est décodée puis réencodée en WebP par GD : les métadonnées (EXIF, GPS) disparaissent,
 * un fichier polyglotte (image + script) est neutralisé et les dimensions sont normalisées. Les fichiers
 * vivent dans le stockage privé et sont servis par la route /media sous un jeton aléatoire de 32
 * caractères hexadécimaux : une image d'une fiche non publique ne peut pas être devinée.
 */
final class MediaManager
{
    public const TARGET_SERVER   = 'SERVEUR';
    public const TARGET_RESOURCE = 'RESSOURCE';
    public const TARGET_USER     = 'UTILISATEUR';

    public const ROLE_ICON    = 'ICONE';
    public const ROLE_AVATAR  = 'AVATAR';
    public const ROLE_BANNER  = 'BANNIERE';
    public const ROLE_GALLERY = 'GALERIE';

    public const MAX_UPLOAD_BYTES       = 5 * 1024 * 1024;
    public const GALLERY_LIMIT_STANDARD = 4;
    public const GALLERY_LIMIT_PREMIUM  = 16;
    public const CAPTION_MAX_LENGTH     = 140;

    /** Rôles autorisés par type de cible. */
    private const TARGET_ROLES = [
        self::TARGET_SERVER   => [self::ROLE_ICON, self::ROLE_BANNER, self::ROLE_GALLERY],
        self::TARGET_RESOURCE => [self::ROLE_ICON, self::ROLE_BANNER, self::ROLE_GALLERY],
        self::TARGET_USER     => [self::ROLE_AVATAR],
    ];

    /** Format de sortie : « cover » recadre au format exact, « fit » réduit sans recadrer. */
    private const ROLE_SPECS = [
        self::ROLE_ICON    => ['w' => 256,  'h' => 256,  'mode' => 'cover'],
        self::ROLE_AVATAR  => ['w' => 192,  'h' => 192,  'mode' => 'cover'],
        self::ROLE_BANNER  => ['w' => 1600, 'h' => 400,  'mode' => 'cover'],
        self::ROLE_GALLERY => ['w' => 1600, 'h' => 1000, 'mode' => 'fit'],
    ];
    private const THUMB_SPEC = ['w' => 480, 'h' => 300, 'mode' => 'cover'];

    private const ALLOWED_MIME      = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    private const MIN_SOURCE_SIDE   = 32;
    private const MAX_SOURCE_SIDE   = 8000;
    private const MAX_SOURCE_PIXELS = 40_000_000; // protège contre les « bombes » de décompression
    private const WEBP_QUALITY      = 82;
    private const TOKEN_PATTERN     = '/^[a-f0-9]{32}$/';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    public static function storageDir(): string
    {
        return AppConfig::storagePath('media');
    }

    public static function isValidToken(mixed $token): bool
    {
        return is_string($token) && preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    public static function url(string $token, bool $thumbnail = false): string
    {
        return '/media?f=' . rawurlencode($token) . ($thumbnail ? '&t=mini' : '');
    }

    /** Nombre maximal d'images de galerie selon l'offre du propriétaire de la fiche. */
    public static function galleryLimit(bool $isPremium): int
    {
        return $isPremium ? self::GALLERY_LIMIT_PREMIUM : self::GALLERY_LIMIT_STANDARD;
    }

    /**
     * Enregistre une image envoyée par formulaire (entrée de $_FILES).
     *
     * @return array{success: bool, message: string, media?: array<string, mixed>}
     */
    public function storeUpload(mixed $file, int $ownerId, string $targetType, int $targetId, string $role, ?string $caption = null, int $galleryLimit = self::GALLERY_LIMIT_STANDARD): array
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'])) {
            return self::fail('Aucune image n\'a été reçue.');
        }

        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_NO_FILE) {
            return self::fail('Choisissez une image à envoyer.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return self::fail('Image trop lourde : 5 Mo maximum.');
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return self::fail('Le téléversement de l\'image a échoué, veuillez réessayer.');
        }

        return $this->storeFile((string) $file['tmp_name'], $ownerId, $targetType, $targetId, $role, $caption, $galleryLimit);
    }

    /**
     * Enregistre une image déjà présente sur le disque (téléversement validé, données de démonstration).
     *
     * @return array{success: bool, message: string, media?: array<string, mixed>}
     */
    public function storeFile(string $path, int $ownerId, string $targetType, int $targetId, string $role, ?string $caption = null, int $galleryLimit = self::GALLERY_LIMIT_STANDARD): array
    {
        if (!in_array($role, self::TARGET_ROLES[$targetType] ?? [], true) || $targetId <= 0 || $ownerId <= 0) {
            return self::fail('Type d\'image non pris en charge pour cette fiche.');
        }

        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return self::fail('Image vide ou trop lourde : 5 Mo maximum.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return self::fail('Format non pris en charge : envoyez une image PNG, JPEG, WebP ou GIF.');
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return self::fail('Le fichier n\'est pas une image lisible.');
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < self::MIN_SOURCE_SIDE || $height < self::MIN_SOURCE_SIDE) {
            return self::fail(sprintf('Image trop petite : %d × %d px minimum.', self::MIN_SOURCE_SIDE, self::MIN_SOURCE_SIDE));
        }
        if ($width > self::MAX_SOURCE_SIDE || $height > self::MAX_SOURCE_SIDE || $width * $height > self::MAX_SOURCE_PIXELS) {
            return self::fail('Image trop grande : 8000 px de côté au maximum.');
        }

        if ($role === self::ROLE_GALLERY && $this->countFor($targetType, $targetId, $role) >= $galleryLimit) {
            return self::fail(sprintf('La galerie est complète : %d images au maximum avec votre offre.', $galleryLimit));
        }

        $source = @imagecreatefromstring((string) file_get_contents($path));
        if (!$source instanceof GdImage) {
            return self::fail('Le fichier n\'est pas une image lisible.');
        }

        $dir = self::storageDir();
        if (!AppConfig::ensureDirectory($dir)) {
            imagedestroy($source);
            error_log('[Boötes][Media] Dossier de stockage des images inaccessible : ' . $dir);
            return self::fail('Le stockage des images est indisponible, veuillez réessayer plus tard.');
        }

        $token   = bin2hex(random_bytes(16));
        $image   = self::resize($source, self::ROLE_SPECS[$role]);
        $written = imagewebp($image, $dir . '/' . $token . '.webp', self::WEBP_QUALITY);
        $outW    = imagesx($image);
        $outH    = imagesy($image);
        imagedestroy($image);

        if ($written && $role === self::ROLE_GALLERY) {
            $thumb   = self::resize($source, self::THUMB_SPEC);
            $written = imagewebp($thumb, $dir . '/' . $token . '-mini.webp', self::WEBP_QUALITY);
            imagedestroy($thumb);
        }
        imagedestroy($source);

        if (!$written) {
            self::deleteFiles([$token]);
            return self::fail('L\'image n\'a pas pu être convertie, essayez un autre fichier.');
        }

        $caption   = self::cleanCaption($caption);
        $replaced  = [];
        $ownTx     = !$this->db->inTransaction();
        try {
            if ($ownTx) {
                $this->db->beginTransaction();
            }

            // Icône, photo de profil et bannière sont uniques : la nouvelle image remplace l'ancienne
            $position = 0;
            if ($role === self::ROLE_GALLERY) {
                $posStmt = $this->db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM MEDIA WHERE type_cible = :t AND id_cible = :id AND role = :r');
                $posStmt->execute([':t' => $targetType, ':id' => $targetId, ':r' => $role]);
                $position = (int) $posStmt->fetchColumn();
            } else {
                $replaced = array_column($this->forTarget($targetType, $targetId, $role), 'jeton');
                $this->db->prepare('DELETE FROM MEDIA WHERE type_cible = :t AND id_cible = :id AND role = :r')
                    ->execute([':t' => $targetType, ':id' => $targetId, ':r' => $role]);
            }

            $insert = $this->db->prepare('
                INSERT INTO MEDIA (id_proprietaire, type_cible, id_cible, role, jeton, largeur, hauteur, taille_octets, legende, position, date_ajout)
                VALUES (:owner, :t, :id, :r, :token, :w, :h, :size, :caption, :position, NOW())
            ');
            $insert->execute([
                ':owner'    => $ownerId,
                ':t'        => $targetType,
                ':id'       => $targetId,
                ':r'        => $role,
                ':token'    => $token,
                ':w'        => $outW,
                ':h'        => $outH,
                ':size'     => (int) filesize($dir . '/' . $token . '.webp'),
                ':caption'  => $caption,
                ':position' => $position,
            ]);
            $mediaId = (int) $this->db->lastInsertId();

            if ($ownTx) {
                $this->db->commit();
            }
        } catch (PDOException $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            self::deleteFiles([$token]);
            error_log(sprintf('[Boötes][Media] Enregistrement de l\'image impossible : %s', $e->getMessage()));
            return self::fail('L\'image n\'a pas pu être enregistrée.');
        }

        self::deleteFiles($replaced);

        return [
            'success' => true,
            'message' => $role === self::ROLE_GALLERY ? 'Image ajoutée à la galerie.' : 'Image mise à jour.',
            'media'   => ['id_media' => $mediaId, 'jeton' => $token, 'largeur' => $outW, 'hauteur' => $outH],
        ];
    }

    /**
     * Images d'une fiche pour un rôle, dans l'ordre d'affichage.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forTarget(string $targetType, int $targetId, string $role): array
    {
        $stmt = $this->db->prepare('
            SELECT id_media, id_proprietaire, jeton, largeur, hauteur, taille_octets, legende, position
            FROM MEDIA
            WHERE type_cible = :t AND id_cible = :id AND role = :r
            ORDER BY position ASC, id_media ASC
        ');
        $stmt->execute([':t' => $targetType, ':id' => $targetId, ':r' => $role]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countFor(string $targetType, int $targetId, string $role): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM MEDIA WHERE type_cible = :t AND id_cible = :id AND role = :r');
        $stmt->execute([':t' => $targetType, ':id' => $targetId, ':r' => $role]);

        return (int) $stmt->fetchColumn();
    }

    public function firstToken(string $targetType, int $targetId, string $role): ?string
    {
        $media = $this->forTarget($targetType, $targetId, $role);

        return $media !== [] ? (string) $media[0]['jeton'] : null;
    }

    /**
     * Jetons d'un rôle pour plusieurs fiches en une requête (icônes d'une liste, photos des auteurs).
     *
     * @param array<int, int|string> $targetIds
     * @return array<int, string> jeton indexé par identifiant de fiche
     */
    public function tokensFor(string $targetType, array $targetIds, string $role): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $targetIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt         = $this->db->prepare("
            SELECT id_cible, jeton
            FROM MEDIA
            WHERE type_cible = ? AND role = ? AND id_cible IN ({$placeholders})
            ORDER BY position ASC, id_media ASC
        ");
        $stmt->execute(array_merge([$targetType, $role], $ids));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['id_cible']] ??= (string) $row['jeton'];
        }

        return $map;
    }

    /** @return array<string, mixed>|null */
    public function find(int $mediaId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM MEDIA WHERE id_media = :id LIMIT 1');
        $stmt->execute([':id' => $mediaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /** Supprime une image ; l'appelant vérifie au préalable qu'elle appartient à la fiche éditée. */
    public function delete(int $mediaId): bool
    {
        $media = $this->find($mediaId);
        if ($media === null) {
            return false;
        }

        $this->db->prepare('DELETE FROM MEDIA WHERE id_media = :id')->execute([':id' => $mediaId]);
        self::deleteFiles([(string) $media['jeton']]);

        return true;
    }

    /** Supprime toutes les images d'une fiche (suppression d'un serveur ou d'une ressource). */
    public function deleteForTarget(string $targetType, int $targetId): void
    {
        $stmt = $this->db->prepare('SELECT jeton FROM MEDIA WHERE type_cible = :t AND id_cible = :id');
        $stmt->execute([':t' => $targetType, ':id' => $targetId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $this->db->prepare('DELETE FROM MEDIA WHERE type_cible = :t AND id_cible = :id')->execute([':t' => $targetType, ':id' => $targetId]);
        self::deleteFiles(array_map('strval', $tokens));
    }

    /** Supprime les fichiers de toutes les images téléversées par un compte (les lignes partent en cascade). */
    public function purgeFilesOfOwner(int $ownerId): void
    {
        $stmt = $this->db->prepare('SELECT jeton FROM MEDIA WHERE id_proprietaire = :owner');
        $stmt->execute([':owner' => $ownerId]);
        self::deleteFiles(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** Déplace une image de galerie d'un rang vers le début (-1) ou vers la fin (+1). */
    public function move(int $mediaId, int $direction): bool
    {
        $media = $this->find($mediaId);
        if ($media === null || (string) $media['role'] !== self::ROLE_GALLERY) {
            return false;
        }

        $gallery = $this->forTarget((string) $media['type_cible'], (int) $media['id_cible'], self::ROLE_GALLERY);
        $ids     = array_map(static fn (array $m): int => (int) $m['id_media'], $gallery);
        $index   = array_search($mediaId, $ids, true);
        $swap    = $index === false ? false : $index + ($direction < 0 ? -1 : 1);
        if ($index === false || !isset($ids[$swap])) {
            return false;
        }

        [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];
        $update = $this->db->prepare('UPDATE MEDIA SET position = :position WHERE id_media = :id');
        foreach ($ids as $position => $id) {
            $update->execute([':position' => $position + 1, ':id' => $id]);
        }

        return true;
    }

    public function updateCaption(int $mediaId, ?string $caption): bool
    {
        $stmt = $this->db->prepare('UPDATE MEDIA SET legende = :caption WHERE id_media = :id');
        $stmt->execute([':caption' => self::cleanCaption($caption), ':id' => $mediaId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Transmet une image au navigateur (route /media) et termine la requête.
     * Les fichiers sont immuables : un remplacement crée un nouveau jeton, d'où le cache d'un an.
     */
    public static function send(mixed $token, bool $thumbnail): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $path = self::isValidToken($token)
            ? self::storageDir() . '/' . $token . ($thumbnail ? '-mini' : '') . '.webp'
            : null;
        if ($path !== null && $thumbnail && !is_file($path)) {
            $path = self::storageDir() . '/' . $token . '.webp'; // icônes et bannières n'ont pas de miniature
        }

        if ($path === null || !is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store');
            echo 'Image introuvable.';
            exit;
        }

        header_remove('Pragma');
        header_remove('Expires');
        header('Content-Type: image/webp');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    /** Redimensionne vers un format cible (sans jamais agrandir l'image source). */
    private static function resize(GdImage $source, array $spec): GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);

        if ($spec['mode'] === 'cover') {
            $scale = max($spec['w'] / $sw, $spec['h'] / $sh);
            $cropW = max(1, min($sw, (int) round($spec['w'] / $scale)));
            $cropH = max(1, min($sh, (int) round($spec['h'] / $scale)));
            $srcX  = intdiv($sw - $cropW, 2);
            $srcY  = intdiv($sh - $cropH, 2);
            [$dw, $dh] = $scale > 1 ? [$cropW, $cropH] : [(int) $spec['w'], (int) $spec['h']];
        } else {
            $scale = min(1, $spec['w'] / $sw, $spec['h'] / $sh);
            [$srcX, $srcY, $cropW, $cropH] = [0, 0, $sw, $sh];
            $dw = max(1, (int) round($sw * $scale));
            $dh = max(1, (int) round($sh * $scale));
        }

        $target = imagecreatetruecolor($dw, $dh);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, $dw, $dh, $cropW, $cropH);

        return $target;
    }

    private static function cleanCaption(?string $caption): ?string
    {
        return Security::validateString(mb_substr($caption ?? '', 0, self::CAPTION_MAX_LENGTH), 1, self::CAPTION_MAX_LENGTH);
    }

    /** @param array<int, string> $tokens */
    public static function deleteFiles(array $tokens): void
    {
        foreach ($tokens as $token) {
            if (!self::isValidToken($token)) {
                continue;
            }
            foreach (['', '-mini'] as $suffix) {
                $file = self::storageDir() . '/' . $token . $suffix . '.webp';
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /** @return array{success: false, message: string} */
    private static function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
