<?php

declare(strict_types=1);

use Bootes\Src\MediaManager;
use Bootes\Src\PageLayout;
use Bootes\Src\Security;
use Bootes\Src\View;

/**
 * Éditeur commun d'une fiche (serveur de l'annuaire, mod, plugin, modpack) : visuels, galerie et mise en
 * page par blocs. Les actions sont traitées par Bootes\Src\PageEditor, qui revérifie les droits Premium.
 *
 * Un propriétaire Standard voit l'éditeur de mise en page complet mais verrouillé, avec une invitation à
 * passer Premium ; tous les visiteurs voient la page telle que son propriétaire l'a composée.
 *
 * @var string                           $editorActionUrl  URL de la page d'édition
 * @var array<string, int|string>        $editorHidden     champs cachés à renvoyer (identifiant de la fiche)
 * @var bool                             $editorPremium    le propriétaire de la fiche est Premium
 * @var string                           $editorName       nom de la fiche
 * @var string                           $editorPublicUrl  URL de la fiche publique
 * @var string                           $editorPresentationNote rappel sur le texte de présentation
 * @var ?string                          $iconToken
 * @var ?string                          $bannerToken
 * @var array<int, array<string, mixed>> $galleryMedia
 * @var array{theme: string, blocs: array<int, array<string, mixed>>, personnalisee: bool} $layout
 * @var string                           $csrfToken
 */
$galleryLimit = MediaManager::galleryLimit($editorPremium);
$galleryFull  = count($galleryMedia) >= $galleryLimit;
$upsellUrl    = '/user/compte?demande=premium#section-tickets';
$hiddenFields = static function () use ($csrfToken, $editorHidden): string {
    $html = '<input type="hidden" name="csrf_token" value="' . Security::e($csrfToken) . '">';
    foreach ($editorHidden as $name => $value) {
        $html .= '<input type="hidden" name="' . Security::e((string) $name) . '" value="' . Security::e((string) $value) . '">';
    }

    return $html;
};
$blockCounts = array_count_values(array_column($layout['blocs'], 'type'));
?>
<section id="visuels" class="editor-section" aria-labelledby="visuels-title">
    <header class="editor-section__header">
        <h2 id="visuels-title" class="editor-section__title"><?= View::icon('image') ?> Icône et bannière</h2>
        <p class="editor-section__subtitle">L'icône apparaît dans les listes et en tête de la fiche. Formats PNG, JPEG, WebP ou GIF, 5 Mo maximum.</p>
    </header>

    <div class="media-slots">
        <div class="media-slot">
            <div class="media-slot__preview">
                <?= View::tile($iconToken, $editorName, 'tile--lg') ?>
            </div>
            <div class="media-slot__body">
                <h3 class="media-slot__title">Icône <span class="media-slot__hint">recadrée en carré, 256 × 256 px</span></h3>
                <form action="<?= Security::e($editorActionUrl) ?>#visuels" method="post" enctype="multipart/form-data" class="media-slot__form">
                    <?= $hiddenFields() ?>
                    <input type="hidden" name="action" value="upload_icon">
                    <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif" required class="media-slot__file" aria-label="Choisir l'icône">
                    <button type="submit" class="media-slot__btn"><?= View::icon('upload') ?> Envoyer</button>
                </form>
                <?php if ($iconToken !== null): ?>
                    <form action="<?= Security::e($editorActionUrl) ?>#visuels" method="post" class="media-slot__form">
                        <?= $hiddenFields() ?>
                        <input type="hidden" name="action" value="delete_icon">
                        <button type="submit" class="media-slot__btn btn--danger" data-confirm="Retirer l'icône de la fiche ?"><?= View::icon('trash-2') ?> Retirer</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="media-slot media-slot--banner<?= !$editorPremium ? ' is-locked' : '' ?>">
            <div class="media-slot__preview media-slot__preview--banner">
                <?php if ($bannerToken !== null): ?>
                    <img src="<?= Security::e(MediaManager::url($bannerToken)) ?>" alt="Bannière actuelle" class="media-slot__banner" width="1600" height="400">
                <?php else: ?>
                    <span class="media-slot__placeholder"><?= View::icon('panel-top') ?> Bannière 1600 × 400</span>
                <?php endif; ?>
            </div>
            <div class="media-slot__body">
                <h3 class="media-slot__title">Bannière <span class="badge badge--premium"><?= View::icon('gem') ?> Premium</span></h3>
                <?php if ($editorPremium): ?>
                    <form action="<?= Security::e($editorActionUrl) ?>#visuels" method="post" enctype="multipart/form-data" class="media-slot__form">
                        <?= $hiddenFields() ?>
                        <input type="hidden" name="action" value="upload_banner">
                        <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif" required class="media-slot__file" aria-label="Choisir la bannière">
                        <button type="submit" class="media-slot__btn"><?= View::icon('upload') ?> Envoyer</button>
                    </form>
                    <?php if ($bannerToken !== null): ?>
                        <form action="<?= Security::e($editorActionUrl) ?>#visuels" method="post" class="media-slot__form">
                            <?= $hiddenFields() ?>
                            <input type="hidden" name="action" value="delete_banner">
                            <button type="submit" class="media-slot__btn btn--danger" data-confirm="Retirer la bannière de la fiche ?"><?= View::icon('trash-2') ?> Retirer</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="media-slot__locked"><?= View::icon('lock') ?> Une grande image d'en-tête aux couleurs de votre projet. <a href="<?= Security::e($upsellUrl) ?>">Débloquer avec Premium</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<section id="galerie" class="editor-section" aria-labelledby="galerie-title">
    <header class="editor-section__header">
        <h2 id="galerie-title" class="editor-section__title"><?= View::icon('images') ?> Galerie</h2>
        <p class="editor-section__subtitle">
            <?= count($galleryMedia) ?> / <?= $galleryLimit ?> images.
            <?php if (!$editorPremium): ?>
                Passez Premium pour exposer jusqu'à <?= MediaManager::GALLERY_LIMIT_PREMIUM ?> captures d'écran.
            <?php endif; ?>
        </p>
    </header>

    <?php if ($galleryMedia !== []): ?>
        <ol class="gallery-admin">
            <?php foreach ($galleryMedia as $index => $image): ?>
                <li class="gallery-admin__item">
                    <img src="<?= Security::e(MediaManager::url((string) $image['jeton'], true)) ?>" alt="Image <?= $index + 1 ?> de la galerie" class="gallery-admin__thumb" width="480" height="300" loading="lazy">
                    <form action="<?= Security::e($editorActionUrl) ?>#galerie" method="post" class="gallery-admin__caption">
                        <?= $hiddenFields() ?>
                        <input type="hidden" name="action" value="gallery_caption">
                        <input type="hidden" name="media_id" value="<?= (int) $image['id_media'] ?>">
                        <label class="visually-hidden" for="legende-<?= (int) $image['id_media'] ?>">Légende de l'image <?= $index + 1 ?></label>
                        <input type="text" id="legende-<?= (int) $image['id_media'] ?>" name="legende" value="<?= Security::e((string) ($image['legende'] ?? '')) ?>" maxlength="<?= MediaManager::CAPTION_MAX_LENGTH ?>" placeholder="Légende (facultative)" class="gallery-admin__input">
                        <button type="submit" class="gallery-admin__btn" title="Enregistrer la légende"><?= View::icon('save') ?><span class="visually-hidden">Enregistrer la légende</span></button>
                    </form>
                    <div class="gallery-admin__tools">
                        <?php foreach (['up' => ['arrow-up', 'Avancer'], 'down' => ['arrow-down', 'Reculer']] as $direction => [$icon, $label]): ?>
                            <form action="<?= Security::e($editorActionUrl) ?>#galerie" method="post" class="gallery-admin__inline">
                                <?= $hiddenFields() ?>
                                <input type="hidden" name="action" value="gallery_move">
                                <input type="hidden" name="media_id" value="<?= (int) $image['id_media'] ?>">
                                <input type="hidden" name="direction" value="<?= $direction ?>">
                                <button type="submit" class="gallery-admin__btn" title="<?= $label ?>"<?= ($direction === 'up' && $index === 0) || ($direction === 'down' && $index === count($galleryMedia) - 1) ? ' disabled' : '' ?>><?= View::icon($icon) ?><span class="visually-hidden"><?= $label ?></span></button>
                            </form>
                        <?php endforeach; ?>
                        <form action="<?= Security::e($editorActionUrl) ?>#galerie" method="post" class="gallery-admin__inline">
                            <?= $hiddenFields() ?>
                            <input type="hidden" name="action" value="gallery_delete">
                            <input type="hidden" name="media_id" value="<?= (int) $image['id_media'] ?>">
                            <button type="submit" class="gallery-admin__btn btn--danger" title="Retirer" data-confirm="Retirer cette image de la galerie ?"><?= View::icon('trash-2') ?><span class="visually-hidden">Retirer</span></button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($galleryFull): ?>
        <p class="editor-section__note"><?= View::icon('info') ?> La galerie est complète pour votre offre. Retirez une image pour en ajouter une autre.</p>
    <?php else: ?>
        <form action="<?= Security::e($editorActionUrl) ?>#galerie" method="post" enctype="multipart/form-data" class="gallery-upload">
            <?= $hiddenFields() ?>
            <input type="hidden" name="action" value="upload_gallery">
            <div class="dropzone gallery-upload__zone" data-dropzone>
                <?= View::icon('image-plus', 'dropzone__icon') ?>
                <p class="dropzone__text"><strong>Glissez une capture d'écran ici</strong> ou cliquez pour la choisir</p>
                <p class="dropzone__file" data-dropzone-file>Aucun fichier sélectionné</p>
                <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif" required class="dropzone__input" aria-label="Choisir une image pour la galerie">
            </div>
            <div class="gallery-upload__row">
                <label class="visually-hidden" for="gallery-caption-new">Légende</label>
                <input type="text" id="gallery-caption-new" name="legende" maxlength="<?= MediaManager::CAPTION_MAX_LENGTH ?>" placeholder="Légende (facultative)" class="gallery-upload__input">
                <button type="submit" class="gallery-upload__submit"><?= View::icon('upload') ?> Ajouter à la galerie</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<section id="mise-en-page" class="editor-section layout-editor<?= !$editorPremium ? ' layout-editor--locked' : '' ?>" aria-labelledby="layout-title">
    <header class="editor-section__header">
        <h2 id="layout-title" class="editor-section__title"><?= View::icon('wand-sparkles') ?> Mise en page de la fiche <span class="badge badge--premium"><?= View::icon('gem') ?> Premium</span></h2>
        <p class="editor-section__subtitle">
            Ordre des sections, texte en colonnes, blocs supplémentaires et couleurs. La barre de connexion (adresse IP),
            la colonne d'informations et les avis restent toujours à leur place.
        </p>
        <a href="<?= Security::e($editorPublicUrl) ?>" class="editor-section__link" target="_blank" rel="noopener"><?= View::icon('eye') ?> Voir la fiche publique</a>
    </header>

    <?php if (!$editorPremium): ?>
        <aside class="upsell" aria-labelledby="upsell-title">
            <div class="upsell__mock" aria-hidden="true">
                <span class="upsell__mock-banner"></span>
                <span class="upsell__mock-bar"></span>
                <span class="upsell__mock-col"></span>
                <span class="upsell__mock-col"></span>
                <span class="upsell__mock-wide"></span>
            </div>
            <div class="upsell__body">
                <p class="upsell__eyebrow"><?= View::icon('lock') ?> Réservé aux membres Premium</p>
                <h3 id="upsell-title" class="upsell__title">Composez une page qui vous ressemble</h3>
                <ul class="upsell__list">
                    <li><?= View::icon('check') ?> Sections dans l'ordre de votre choix, texte sur deux colonnes</li>
                    <li><?= View::icon('check') ?> Points forts, annonces d'événements, liens utiles</li>
                    <li><?= View::icon('check') ?> 8 thèmes de couleur et une bannière personnalisée</li>
                    <li><?= View::icon('check') ?> Galerie de <?= MediaManager::GALLERY_LIMIT_PREMIUM ?> images et badge Premium dans l'annuaire</li>
                </ul>
                <p class="upsell__note">Vos visiteurs verront la page telle que vous l'aurez composée, qu'ils aient un compte ou non.</p>
                <div class="upsell__actions">
                    <a href="<?= Security::e($upsellUrl) ?>" class="upsell__btn upsell__btn--primary"><?= View::icon('gem') ?> Passer Premium</a>
                    <a href="/offres" class="upsell__btn btn--ghost">Comparer les offres</a>
                </div>
            </div>
        </aside>
    <?php endif; ?>

    <form action="<?= Security::e($editorActionUrl) ?>#mise-en-page" method="post" class="layout-editor__form">
        <?= $hiddenFields() ?>
        <input type="hidden" name="action" value="save_layout">
        <fieldset class="layout-editor__fieldset"<?= !$editorPremium ? ' disabled' : '' ?>>
            <legend class="visually-hidden">Outils de mise en page</legend>
            <!-- Bouton par défaut : la touche Entrée enregistre au lieu de déclencher le premier « Monter / Descendre » -->
            <button type="submit" name="op" value="save" class="visually-hidden" tabindex="-1" aria-hidden="true">Enregistrer</button>

            <div class="theme-picker" role="radiogroup" aria-label="Thème de couleur">
                <p class="theme-picker__label"><?= View::icon('palette') ?> Thème</p>
                <?php foreach (PageLayout::THEMES as $themeKey => $themeLabel): ?>
                    <label class="theme-picker__option">
                        <input type="radio" name="theme" value="<?= Security::e($themeKey) ?>"<?= $layout['theme'] === $themeKey ? ' checked' : '' ?> class="theme-picker__input">
                        <span class="theme-picker__swatch theme-picker__swatch--<?= Security::e($themeKey) ?>" aria-hidden="true"></span>
                        <span class="theme-picker__name"><?= Security::e($themeLabel) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <ol class="layout-blocks">
                <?php foreach ($layout['blocs'] as $i => $block): ?>
                    <?php $meta = PageLayout::BLOCKS[$block['type']]; ?>
                    <li class="layout-block layout-block--<?= Security::e((string) $block['type']) ?>">
                        <div class="layout-block__head">
                            <span class="layout-block__type"><?= View::icon($meta['icon']) ?> <?= Security::e($meta['label']) ?></span>
                            <span class="layout-block__position"><?= $i + 1 ?> / <?= count($layout['blocs']) ?></span>
                            <div class="layout-block__tools">
                                <button type="submit" name="op" value="up:<?= $i ?>" class="layout-block__btn" title="Monter"<?= $i === 0 ? ' disabled' : '' ?>><?= View::icon('arrow-up') ?><span class="visually-hidden">Monter le bloc</span></button>
                                <button type="submit" name="op" value="down:<?= $i ?>" class="layout-block__btn" title="Descendre"<?= $i === count($layout['blocs']) - 1 ? ' disabled' : '' ?>><?= View::icon('arrow-down') ?><span class="visually-hidden">Descendre le bloc</span></button>
                                <?php if ($block['type'] !== 'presentation'): ?>
                                    <button type="submit" name="op" value="remove:<?= $i ?>" class="layout-block__btn btn--danger" title="Supprimer"><?= View::icon('trash-2') ?><span class="visually-hidden">Supprimer le bloc</span></button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <input type="hidden" name="blocs[<?= $i ?>][type]" value="<?= Security::e((string) $block['type']) ?>">

                        <div class="layout-block__fields">
                            <?php if (in_array($block['type'], ['galerie', 'texte', 'points_forts', 'liens'], true)): ?>
                                <label class="layout-block__field layout-block__field--wide">
                                    <span class="layout-block__label">Titre de la section</span>
                                    <input type="text" name="blocs[<?= $i ?>][titre]" value="<?= Security::e((string) ($block['titre'] ?? '')) ?>" maxlength="80" class="layout-block__input">
                                </label>
                            <?php endif; ?>

                            <?php if ($block['type'] === 'presentation'): ?>
                                <p class="layout-block__note"><?= View::icon('info') ?> <?= Security::e($editorPresentationNote) ?></p>
                            <?php endif; ?>

                            <?php if ($block['type'] === 'texte'): ?>
                                <label class="layout-block__field layout-block__field--wide">
                                    <span class="layout-block__label">Contenu (ligne vide = nouveau paragraphe, « - » = liste, **gras**)</span>
                                    <textarea name="blocs[<?= $i ?>][contenu]" rows="5" maxlength="4000" class="layout-block__textarea"><?= Security::e((string) $block['contenu']) ?></textarea>
                                </label>
                                <label class="layout-block__check">
                                    <input type="checkbox" name="blocs[<?= $i ?>][encadre]" value="1"<?= !empty($block['encadre']) ? ' checked' : '' ?>>
                                    Mettre en valeur (fond coloré)
                                </label>
                            <?php endif; ?>

                            <?php if (in_array($block['type'], ['presentation', 'texte'], true)): ?>
                                <label class="layout-block__field">
                                    <span class="layout-block__label"><?= View::icon('columns-2') ?> Disposition</span>
                                    <select name="blocs[<?= $i ?>][colonnes]" class="layout-block__select">
                                        <option value="1"<?= (int) $block['colonnes'] === 1 ? ' selected' : '' ?>>Une colonne</option>
                                        <option value="2"<?= (int) $block['colonnes'] === 2 ? ' selected' : '' ?>>Deux colonnes</option>
                                    </select>
                                </label>
                            <?php endif; ?>

                            <?php if ($block['type'] === 'galerie'): ?>
                                <label class="layout-block__field">
                                    <span class="layout-block__label">Affichage</span>
                                    <select name="blocs[<?= $i ?>][style]" class="layout-block__select">
                                        <option value="carrousel"<?= $block['style'] === 'carrousel' ? ' selected' : '' ?>>Carrousel</option>
                                        <option value="grille"<?= $block['style'] === 'grille' ? ' selected' : '' ?>>Grille</option>
                                    </select>
                                </label>
                            <?php endif; ?>

                            <?php if ($block['type'] === 'points_forts'): ?>
                                <label class="layout-block__field layout-block__field--wide">
                                    <span class="layout-block__label">Un point fort par ligne (<?= PageLayout::MAX_LIST_ITEMS ?> au maximum)</span>
                                    <textarea name="blocs[<?= $i ?>][points]" rows="4" class="layout-block__textarea"><?= Security::e(implode("\n", $block['points'])) ?></textarea>
                                </label>
                            <?php endif; ?>

                            <?php if ($block['type'] === 'annonce'): ?>
                                <label class="layout-block__field layout-block__field--wide">
                                    <span class="layout-block__label">Message (280 caractères)</span>
                                    <textarea name="blocs[<?= $i ?>][texte]" rows="2" maxlength="280" class="layout-block__textarea"><?= Security::e((string) $block['texte']) ?></textarea>
                                </label>
                                <label class="layout-block__field">
                                    <span class="layout-block__label">Ton</span>
                                    <select name="blocs[<?= $i ?>][ton]" class="layout-block__select">
                                        <?php foreach (PageLayout::ANNOUNCE_TONES as $toneKey => $toneLabel): ?>
                                            <option value="<?= Security::e($toneKey) ?>"<?= $block['ton'] === $toneKey ? ' selected' : '' ?>><?= Security::e($toneLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endif; ?>

                            <?php if ($block['type'] === 'liens'): ?>
                                <label class="layout-block__field layout-block__field--wide">
                                    <span class="layout-block__label">Un lien par ligne : « Libellé | https://… » (<?= PageLayout::MAX_LINKS ?> au maximum)</span>
                                    <textarea name="blocs[<?= $i ?>][liens]" rows="3" class="layout-block__textarea"><?= Security::e(implode("\n", array_map(static fn (array $l): string => $l['libelle'] . ' | ' . $l['url'], $block['liens']))) ?></textarea>
                                </label>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="layout-editor__add">
                <p class="layout-editor__add-label"><?= View::icon('plus') ?> Ajouter un bloc</p>
                <?php foreach (PageLayout::BLOCKS as $type => $meta): ?>
                    <?php if ($type !== 'presentation'): ?>
                        <button type="submit" name="op" value="add:<?= Security::e($type) ?>" class="layout-editor__add-btn"<?= ($blockCounts[$type] ?? 0) >= $meta['max'] || count($layout['blocs']) >= PageLayout::MAX_BLOCKS ? ' disabled' : '' ?>>
                            <?= View::icon($meta['icon']) ?> <?= Security::e($meta['label']) ?>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="layout-editor__actions">
                <button type="submit" name="op" value="save" class="layout-editor__submit"><?= View::icon('save') ?> Enregistrer la mise en page</button>
                <?php if ($layout['personnalisee']): ?>
                    <button type="submit" name="op" value="reset" class="layout-editor__btn btn--ghost" data-confirm="Revenir à la mise en page standard ? Les blocs supplémentaires seront perdus.">Mise en page standard</button>
                <?php endif; ?>
            </div>
        </fieldset>
    </form>
</section>
