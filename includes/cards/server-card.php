<?php

declare(strict_types=1);

use Bootes\Src\Security;
use Bootes\Src\View;

/**
 * Carte d'un serveur public (accueil, catalogue). La carte entière mène à la fiche du serveur.
 *
 * @var array{id: int|string, name: string, version: string, mode: string, status: string, players: int|string,
 *            slots: int|string, description: ?string, owner: string, rating: float|string} $card
 */
$cardUrl     = '/serveur-public?id=' . (int) $card['id'];
$cardSlots   = max(1, (int) $card['slots']);
$cardPlayers = min(max(0, (int) $card['players']), $cardSlots);
$cardRating  = round((float) $card['rating'], 1);
?>
<article class="server-card">
    <header class="server-card__top">
        <?= View::badge('statut_serveur', $card['status']) ?>
        <span class="server-card__version" title="Version de Minecraft"><?= View::icon('box') ?> <?= Security::e((string) $card['version']) ?></span>
    </header>

    <h3 class="server-card__title">
        <a href="<?= Security::e($cardUrl) ?>" class="server-card__link"><?= Security::e((string) $card['name']) ?></a>
    </h3>
    <p class="server-card__meta">
        <?= Security::e(View::label('mode_jeu', $card['mode'])) ?> &middot; par <?= Security::e((string) $card['owner']) ?>
    </p>
    <p class="server-card__description">
        <?= Security::e(mb_strimwidth((string) ($card['description'] ?? ''), 0, 140, '…')) ?>
    </p>

    <footer class="server-card__footer">
        <div class="server-card__players">
            <span class="server-card__players-count">
                <?= View::icon('users') ?>
                <strong><?= $cardPlayers ?></strong> / <?= $cardSlots ?> joueurs
            </span>
            <meter class="server-card__meter" min="0" max="<?= $cardSlots ?>" value="<?= $cardPlayers ?>"
                   low="<?= (int) ceil($cardSlots * 0.75) ?>" high="<?= (int) ceil($cardSlots * 0.9) ?>" optimum="0"
                   aria-label="Remplissage du serveur"></meter>
        </div>
        <span class="rating" title="Note moyenne des joueurs">
            <?= View::icon('star') ?>
            <?= $cardRating > 0 ? View::number($cardRating, 1) : '—' ?>
        </span>
    </footer>
</article>
