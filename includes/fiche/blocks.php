<?php

declare(strict_types=1);

use Bootes\Src\PageLayout;
use Bootes\Src\Security;
use Bootes\Src\View;

/**
 * Zone modulable d'une fiche : blocs dans l'ordre choisi par le propriétaire (PageLayout). Seuls ces blocs
 * peuvent être déplacés ; la barre de connexion, la colonne d'informations et les avis restent fixes.
 *
 * @var array{theme: string, blocs: array<int, array<string, mixed>>, personnalisee: bool} $layout
 * @var string                           $presentationText   texte de présentation (description)
 * @var string                           $presentationTitle  titre du bloc Présentation
 * @var array<int, array<string, mixed>> $galleryMedia
 * @var string                           $galleryName
 * @var bool                             $canEditPage        affiche les invitations à compléter la fiche
 * @var string                           $editUrl
 */
$toneIcons = ['info' => 'info', 'succes' => 'circle-check', 'attention' => 'triangle-alert', 'evenement' => 'calendar'];
?>
<div class="page-blocks">
    <?php foreach ($layout['blocs'] as $blockIndex => $block): ?>
        <?php switch ($block['type']):
            case 'presentation': ?>
                <section class="page-block page-block--presentation" aria-labelledby="bloc-<?= $blockIndex ?>">
                    <h2 id="bloc-<?= $blockIndex ?>" class="page-block__title"><?= View::icon('align-left') ?> <?= Security::e($presentationTitle) ?></h2>
                    <?php if (trim($presentationText) !== ''): ?>
                        <div class="page-block__text<?= (int) $block['colonnes'] === 2 ? ' page-block__text--columns' : '' ?>">
                            <?= PageLayout::richText($presentationText) ?>
                        </div>
                    <?php else: ?>
                        <p class="page-block__empty">Aucune présentation n'a encore été rédigée.</p>
                    <?php endif; ?>
                </section>
                <?php break;

            case 'galerie': ?>
                <?php if ($galleryMedia !== [] || $canEditPage): ?>
                    <section class="page-block page-block--gallery" aria-labelledby="bloc-<?= $blockIndex ?>">
                        <h2 id="bloc-<?= $blockIndex ?>" class="page-block__title"><?= View::icon('images') ?> <?= Security::e((string) ($block['titre'] ?? '') !== '' ? (string) $block['titre'] : 'Galerie') ?></h2>
                        <?php if ($galleryMedia !== []): ?>
                            <?php
                            $galleryStyle = (string) $block['style'];
                            require __DIR__ . '/gallery.php';
                            ?>
                        <?php else: ?>
                            <p class="page-block__empty">
                                Aucune image pour l'instant. <a href="<?= Security::e($editUrl) ?>#galerie">Ajoutez des captures d'écran</a> pour donner envie aux visiteurs.
                            </p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
                <?php break;

            case 'texte': ?>
                <?php if (trim((string) $block['contenu']) !== '' || (string) $block['titre'] !== ''): ?>
                    <section class="page-block page-block--text<?= !empty($block['encadre']) ? ' page-block--boxed' : '' ?>"<?= (string) $block['titre'] !== '' ? ' aria-labelledby="bloc-' . $blockIndex . '"' : '' ?>>
                        <?php if ((string) $block['titre'] !== ''): ?>
                            <h2 id="bloc-<?= $blockIndex ?>" class="page-block__title"><?= Security::e((string) $block['titre']) ?></h2>
                        <?php endif; ?>
                        <div class="page-block__text<?= (int) $block['colonnes'] === 2 ? ' page-block__text--columns' : '' ?>">
                            <?= PageLayout::richText((string) $block['contenu']) ?>
                        </div>
                    </section>
                <?php endif; ?>
                <?php break;

            case 'points_forts': ?>
                <?php if ($block['points'] !== []): ?>
                    <section class="page-block page-block--highlights" aria-labelledby="bloc-<?= $blockIndex ?>">
                        <h2 id="bloc-<?= $blockIndex ?>" class="page-block__title"><?= View::icon('sparkles') ?> <?= Security::e((string) $block['titre'] !== '' ? (string) $block['titre'] : 'Points forts') ?></h2>
                        <ul class="highlights">
                            <?php foreach ($block['points'] as $point): ?>
                                <li class="highlights__item"><?= View::icon('check', 'highlights__icon') ?> <span><?= Security::e((string) $point) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>
                <?php break;

            case 'annonce': ?>
                <?php if (trim((string) $block['texte']) !== ''): ?>
                    <aside class="announce announce--<?= Security::e((string) $block['ton']) ?>" aria-label="<?= Security::e(PageLayout::ANNOUNCE_TONES[$block['ton']] ?? 'Annonce') ?>">
                        <?= View::icon($toneIcons[$block['ton']] ?? 'megaphone', 'announce__icon') ?>
                        <div class="announce__body">
                            <p class="announce__label"><?= Security::e(PageLayout::ANNOUNCE_TONES[$block['ton']] ?? 'Annonce') ?></p>
                            <div class="announce__text"><?= PageLayout::richText((string) $block['texte']) ?></div>
                        </div>
                    </aside>
                <?php endif; ?>
                <?php break;

            case 'liens': ?>
                <?php if ($block['liens'] !== []): ?>
                    <section class="page-block page-block--links" aria-labelledby="bloc-<?= $blockIndex ?>">
                        <h2 id="bloc-<?= $blockIndex ?>" class="page-block__title"><?= View::icon('link') ?> <?= Security::e((string) $block['titre'] !== '' ? (string) $block['titre'] : 'Liens utiles') ?></h2>
                        <ul class="link-list">
                            <?php foreach ($block['liens'] as $link): ?>
                                <li class="link-list__item">
                                    <a href="<?= Security::e((string) $link['url']) ?>" class="link-list__link" rel="nofollow noopener ugc" target="_blank">
                                        <?= View::icon('external-link') ?> <?= Security::e((string) $link['libelle']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>
                <?php break;
        endswitch; ?>
    <?php endforeach; ?>
</div>
