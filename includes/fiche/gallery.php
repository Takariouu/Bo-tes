<?php

declare(strict_types=1);

use Bootes\Src\MediaManager;
use Bootes\Src\Security;
use Bootes\Src\View;

/**
 * Galerie d'images d'une fiche : carrousel (défilement horizontal aimanté) ou grille. Un clic ouvre
 * la visionneuse plein écran (includes/fiche/lightbox.php) ; sans JavaScript, l'image s'ouvre en grand.
 *
 * @var array<int, array<string, mixed>> $galleryMedia images (MediaManager::forTarget)
 * @var string                           $galleryStyle « carrousel » ou « grille »
 * @var string                           $galleryName  nom de la fiche, pour les textes alternatifs
 */
$galleryCount = count($galleryMedia);
?>
<div class="gallery gallery--<?= $galleryStyle === 'grille' ? 'grille' : 'carrousel' ?>" data-gallery>
    <div class="gallery__track" tabindex="0" role="region" aria-label="Galerie de <?= Security::e($galleryName) ?> : <?= $galleryCount ?> image<?= $galleryCount > 1 ? 's' : '' ?>">
        <?php foreach ($galleryMedia as $index => $image): ?>
            <?php
            $imageCaption = (string) ($image['legende'] ?? '');
            $imageAlt     = $imageCaption !== '' ? $imageCaption : sprintf('%s, image %d sur %d', $galleryName, $index + 1, $galleryCount);
            $imageFull    = MediaManager::url((string) $image['jeton']);
            ?>
            <figure class="gallery__item">
                <a href="<?= Security::e($imageFull) ?>" class="gallery__open" data-gallery-item data-caption="<?= Security::e($imageCaption) ?>">
                    <img src="<?= Security::e(MediaManager::url((string) $image['jeton'], true)) ?>" alt="<?= Security::e($imageAlt) ?>"
                         class="gallery__thumb" width="480" height="300" loading="lazy" decoding="async">
                    <span class="gallery__zoom" aria-hidden="true"><?= View::icon('maximize-2') ?></span>
                </a>
                <?php if ($imageCaption !== ''): ?>
                    <figcaption class="gallery__caption"><?= Security::e($imageCaption) ?></figcaption>
                <?php endif; ?>
            </figure>
        <?php endforeach; ?>
    </div>
    <?php if ($galleryStyle !== 'grille' && $galleryCount > 1): ?>
        <div class="gallery__controls">
            <button type="button" class="gallery__nav" data-gallery-prev aria-label="Images précédentes"><?= View::icon('chevron-left') ?></button>
            <button type="button" class="gallery__nav" data-gallery-next aria-label="Images suivantes"><?= View::icon('chevron-right') ?></button>
        </div>
    <?php endif; ?>
</div>
