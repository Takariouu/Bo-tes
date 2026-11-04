<?php

declare(strict_types=1);

use Bootes\Src\Security;
use Bootes\Src\View;

/**
 * Sélecteur de vue d'une liste (lignes compactes ou grille de cartes), mémorisé par cookie.
 *
 * @var string                $listMode     vue active (View::listMode())
 * @var callable(string): string $viewSwitchUrl URL de la page pour une vue donnée
 */
$viewOptions = [
    'liste'  => ['icon' => 'list',        'label' => 'Lignes compactes'],
    'grille' => ['icon' => 'layout-grid', 'label' => 'Grille de cartes'],
];
?>
<div class="view-switch" role="group" aria-label="Affichage des résultats">
    <?php foreach ($viewOptions as $mode => $option): ?>
        <a href="<?= Security::e($viewSwitchUrl($mode)) ?>"
           class="view-switch__option<?= $listMode === $mode ? ' is-active' : '' ?>"
           <?= $listMode === $mode ? 'aria-current="true"' : '' ?>
           title="<?= Security::e($option['label']) ?>">
            <?= View::icon($option['icon']) ?>
            <span class="visually-hidden"><?= Security::e($option['label']) ?></span>
        </a>
    <?php endforeach; ?>
</div>
