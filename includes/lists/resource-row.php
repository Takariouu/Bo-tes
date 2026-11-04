<?php

declare(strict_types=1);

use Bootes\Src\ResourceManager;
use Bootes\Src\Security;
use Bootes\Src\View;

/**
 * Ligne compacte d'une ressource (catalogue, accueil) : icône, nom, type, auteur avec sa photo,
 * résumé sur une ligne, catégories, puis colonnes alignées (version, téléchargements, note, mise à
 * jour) et téléchargement direct de la dernière version.
 *
 * @var array<string, mixed> $row       ligne de requête (id, title, subtype, version, downloads, rating, summary,
 *                                      author, categories, chargeur, versions, updated_at)
 * @var ?string              $rowIcon   jeton de l'icône
 * @var ?string              $rowAvatar jeton de la photo de l'auteur
 * @var string               $csrfToken
 */
$rowUrl      = '/ressource-detail?id=' . (int) $row['id'];
$rowType     = strtoupper((string) $row['subtype']);
$rowTone     = match ($rowType) {
    'PLUGIN'              => 'plugin',
    'MOD_PACK', 'MODPACK' => 'modpack',
    default               => 'mod',
};
$rowRating   = round((float) $row['rating'], 1);
$rowTags     = array_map(static fn (string $c): string => ResourceManager::CATEGORIES[$c], ResourceManager::decodeCategories($row['categories'] ?? ''));
if (!empty($row['chargeur'])) {
    $rowTags[] = View::label('loader', $row['chargeur']);
}
$rowUpdated  = (string) ($row['updated_at'] ?? '');
?>
<article class="item-row item-row--<?= $rowTone ?>">
    <a href="<?= Security::e($rowUrl) ?>" class="item-row__visual" tabindex="-1" aria-hidden="true">
        <?= View::tile($rowIcon ?? null, (string) $row['title'], 'tile--md') ?>
    </a>

    <div class="item-row__main">
        <h3 class="item-row__name">
            <a href="<?= Security::e($rowUrl) ?>" class="item-row__link"><?= Security::e((string) $row['title']) ?></a>
            <span class="type-chip type-chip--<?= $rowTone ?>"><?= Security::e(View::label('type_ressource', $rowType)) ?></span>
        </h3>
        <p class="item-row__byline">
            <span class="item-row__author"><?= View::avatar($rowAvatar ?? null, (string) $row['author'], 'avatar--xs') ?> <?= Security::e((string) $row['author']) ?></span>
            <span class="item-row__summary"><?= Security::e((string) ($row['summary'] ?? '')) ?></span>
        </p>
        <?php if ($rowTags !== []): ?>
            <ul class="item-row__tags" aria-label="Catégories">
                <?php foreach (array_slice($rowTags, 0, 3) as $tag): ?>
                    <li class="item-row__tag"><?= Security::e($tag) ?></li>
                <?php endforeach; ?>
                <?php if (count($rowTags) > 3): ?>
                    <li class="item-row__tag item-row__tag--more">+<?= count($rowTags) - 3 ?></li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    </div>

    <dl class="item-row__stats">
        <div class="item-row__stat item-row__stat--version">
            <dt class="visually-hidden">Version de Minecraft</dt>
            <dd><?= View::icon('box') ?> <?= Security::e((string) $row['version']) ?></dd>
        </div>
        <div class="item-row__stat">
            <dt class="visually-hidden">Téléchargements</dt>
            <dd title="<?= Security::e(View::number((int) $row['downloads'])) ?> téléchargements"><?= View::icon('download') ?> <?= Security::e(View::compactNumber((int) $row['downloads'])) ?></dd>
        </div>
        <div class="item-row__stat">
            <dt class="visually-hidden">Note moyenne</dt>
            <dd class="item-row__rating"><?= View::icon('star') ?> <?= $rowRating > 0 ? View::number($rowRating, 1) : '—' ?></dd>
        </div>
        <div class="item-row__stat item-row__stat--date">
            <dt class="visually-hidden">Mise à jour</dt>
            <dd><?= View::icon('history') ?> <?php if ($rowUpdated !== ''): ?><time datetime="<?= Security::e($rowUpdated) ?>"><?= Security::e(View::ago($rowUpdated)) ?></time><?php else: ?>—<?php endif; ?></dd>
        </div>
    </dl>

    <div class="item-row__actions">
        <?php if ((int) ($row['versions'] ?? 0) > 0): ?>
            <form action="<?= Security::e($rowUrl) ?>" method="post" class="item-row__form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="action" value="download">
                <button type="submit" class="item-row__btn" title="Télécharger la dernière version">
                    <?= View::icon('download') ?><span class="item-row__btn-label">Télécharger</span>
                </button>
            </form>
        <?php else: ?>
            <a href="<?= Security::e($rowUrl) ?>" class="item-row__btn btn--ghost"><?= View::icon('eye') ?><span class="item-row__btn-label">Voir</span></a>
        <?php endif; ?>
    </div>
</article>
