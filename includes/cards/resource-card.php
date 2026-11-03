<?php

declare(strict_types=1);

use Bootes\Src\Security;
use Bootes\Src\View;

/**
 * Carte d'une ressource du catalogue (mod, plugin ou modpack). La carte entière mène à sa fiche.
 *
 * @var array{id: int|string, name: string, type: string, version: string, summary: ?string, author: string,
 *            downloads: int|string, rating: float|string} $card
 */
$cardUrl    = '/ressource-detail?id=' . (int) $card['id'];
$cardType   = strtoupper((string) $card['type']);
$cardTone   = match ($cardType) {
    'PLUGIN'             => 'plugin',
    'MOD_PACK', 'MODPACK' => 'modpack',
    default              => 'mod',
};
$cardIcon   = ['mod' => 'puzzle', 'plugin' => 'plug', 'modpack' => 'boxes'][$cardTone];
$cardRating = round((float) $card['rating'], 1);
?>
<article class="resource-card resource-card--<?= $cardTone ?>">
    <header class="resource-card__top">
        <span class="type-chip type-chip--<?= $cardTone ?>"><?= View::icon($cardIcon) ?> <?= Security::e(View::label('type_ressource', $cardType)) ?></span>
        <span class="resource-card__version" title="Version de Minecraft">MC <?= Security::e((string) $card['version']) ?></span>
    </header>

    <h3 class="resource-card__title">
        <a href="<?= Security::e($cardUrl) ?>" class="resource-card__link"><?= Security::e((string) $card['name']) ?></a>
    </h3>
    <p class="resource-card__meta">par <?= Security::e((string) $card['author']) ?></p>
    <p class="resource-card__description">
        <?= Security::e(mb_strimwidth((string) ($card['summary'] ?? ''), 0, 140, '…')) ?>
    </p>

    <footer class="resource-card__footer">
        <span class="resource-card__downloads" title="Téléchargements">
            <?= View::icon('download') ?> <?= View::number((int) $card['downloads']) ?>
        </span>
        <span class="rating" title="Note moyenne">
            <?= View::icon('star') ?>
            <?= $cardRating > 0 ? View::number($cardRating, 1) : '—' ?>
        </span>
    </footer>
</article>
