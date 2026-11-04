<?php

declare(strict_types=1);

namespace Bootes\Src;

use PDO;
use PDOException;

/**
 * Actions communes aux éditeurs de fiche (serveur de l'annuaire, mod, plugin, modpack) : icône, bannière,
 * galerie et mise en page par blocs.
 *
 * Les droits Premium sont vérifiés ICI, côté serveur, à partir de l'offre du propriétaire de la fiche :
 * un formulaire envoyé à la main par un compte Standard est refusé, même si l'interface verrouillée a
 * été contournée.
 */
final class PageEditor
{
    public const ACTIONS = [
        'upload_icon', 'delete_icon', 'upload_banner', 'delete_banner',
        'upload_gallery', 'gallery_caption', 'gallery_move', 'gallery_delete',
        'save_layout',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly string $targetType,
        private readonly int $targetId,
        private readonly int $ownerId,
        private readonly bool $ownerIsPremium
    ) {
    }

    /**
     * Traite une action de l'éditeur.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array{success: bool, message: string, anchor: string}|null null si l'action ne relève pas de l'éditeur
     */
    public function handle(string $action, array $post, array $files): ?array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return null;
        }

        $media = new MediaManager($this->db);

        try {
            switch ($action) {
                case 'upload_icon':
                    return self::result($media->storeUpload($files['image'] ?? null, $this->ownerId, $this->targetType, $this->targetId, MediaManager::ROLE_ICON), 'visuels');

                case 'upload_banner':
                    if (!$this->ownerIsPremium) {
                        return self::fail('La bannière personnalisée est réservée aux comptes Premium.', 'visuels');
                    }
                    return self::result($media->storeUpload($files['image'] ?? null, $this->ownerId, $this->targetType, $this->targetId, MediaManager::ROLE_BANNER), 'visuels');

                case 'delete_icon':
                case 'delete_banner':
                    $role = $action === 'delete_icon' ? MediaManager::ROLE_ICON : MediaManager::ROLE_BANNER;
                    foreach ($media->forTarget($this->targetType, $this->targetId, $role) as $image) {
                        $media->delete((int) $image['id_media']);
                    }
                    return self::ok($role === MediaManager::ROLE_ICON ? 'Icône retirée.' : 'Bannière retirée.', 'visuels');

                case 'upload_gallery':
                    $caption = is_string($post['legende'] ?? null) ? $post['legende'] : null;
                    return self::result($media->storeUpload(
                        $files['image'] ?? null,
                        $this->ownerId,
                        $this->targetType,
                        $this->targetId,
                        MediaManager::ROLE_GALLERY,
                        $caption,
                        MediaManager::galleryLimit($this->ownerIsPremium)
                    ), 'galerie');

                case 'gallery_caption':
                case 'gallery_move':
                case 'gallery_delete':
                    $image = $media->find(Security::validatePositiveInt($post['media_id'] ?? null) ?? 0);
                    if ($image === null || (string) $image['type_cible'] !== $this->targetType || (int) $image['id_cible'] !== $this->targetId
                        || (string) $image['role'] !== MediaManager::ROLE_GALLERY) {
                        return self::fail('Image introuvable dans cette galerie.', 'galerie');
                    }
                    if ($action === 'gallery_delete') {
                        $media->delete((int) $image['id_media']);
                        return self::ok('Image retirée de la galerie.', 'galerie');
                    }
                    if ($action === 'gallery_move') {
                        $media->move((int) $image['id_media'], ($post['direction'] ?? '') === 'up' ? -1 : 1);
                        return self::ok('Ordre de la galerie mis à jour.', 'galerie');
                    }
                    $media->updateCaption((int) $image['id_media'], is_string($post['legende'] ?? null) ? $post['legende'] : null);
                    return self::ok('Légende enregistrée.', 'galerie');

                case 'save_layout':
                    return $this->saveLayout($post);
            }
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][Éditeur de fiche] Action %s impossible : %s', $action, $e->getMessage()));
            return self::fail('L\'opération n\'a pas pu être enregistrée, veuillez réessayer.', 'visuels');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{success: bool, message: string, anchor: string}
     */
    private function saveLayout(array $post): array
    {
        if (!$this->ownerIsPremium) {
            return self::fail('La mise en page personnalisée est réservée aux comptes Premium.', 'mise-en-page');
        }

        $layout    = new PageLayout($this->db);
        $operation = is_string($post['op'] ?? null) ? $post['op'] : 'save';
        if ($operation === 'reset') {
            $layout->reset($this->targetType, $this->targetId);
            return self::ok('Mise en page standard rétablie.', 'mise-en-page');
        }

        [$theme, $blocks] = PageLayout::normalize($post['theme'] ?? 'defaut', is_array($post['blocs'] ?? null) ? $post['blocs'] : []);
        $error            = null;
        if ($operation !== 'save') {
            [$blocks, $error] = PageLayout::applyOperation($blocks, $operation);
        }

        if (!$layout->save($this->targetType, $this->targetId, $theme, $blocks, $this->ownerId)) {
            return self::fail('La mise en page n\'a pas pu être enregistrée.', 'mise-en-page');
        }

        return $error !== null ? self::fail($error, 'mise-en-page') : self::ok('Mise en page enregistrée.', 'mise-en-page');
    }

    /**
     * @param array{success: bool, message: string} $result
     * @return array{success: bool, message: string, anchor: string}
     */
    private static function result(array $result, string $anchor): array
    {
        return ['success' => $result['success'], 'message' => $result['message'], 'anchor' => $anchor];
    }

    /** @return array{success: true, message: string, anchor: string} */
    private static function ok(string $message, string $anchor): array
    {
        return ['success' => true, 'message' => $message, 'anchor' => $anchor];
    }

    /** @return array{success: false, message: string, anchor: string} */
    private static function fail(string $message, string $anchor): array
    {
        return ['success' => false, 'message' => $message, 'anchor' => $anchor];
    }
}
