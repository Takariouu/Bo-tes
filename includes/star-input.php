<?php

declare(strict_types=1);

use Bootes\Src\Security;
use Bootes\Src\View;

/**
 * Saisie d'une note de 1 à 5 étoiles : cinq boutons radio stylés (utilisables au clavier).
 * Les étoiles sont écrites de 5 à 1 puis affichées dans l'ordre inverse, pour que le survol
 * d'une étoile allume aussi les précédentes en CSS seul.
 *
 * @var string $starInputName Nom du champ envoyé (« note »)
 * @var string $starInputId   Préfixe d'identifiant unique dans la page
 */
$starLabels = [5 => 'Excellent', 4 => 'Très bien', 3 => 'Correct', 2 => 'Insuffisant', 1 => 'Déconseillé'];
?>
<fieldset class="star-input">
    <legend class="star-input__legend">Votre note</legend>
    <div class="star-input__stars">
        <?php foreach ($starLabels as $value => $label): ?>
            <input type="radio" id="<?= Security::e($starInputId . '-' . $value) ?>" name="<?= Security::e($starInputName) ?>"
                   value="<?= $value ?>" class="star-input__radio" required>
            <label for="<?= Security::e($starInputId . '-' . $value) ?>" class="star-input__star" title="<?= $value ?> / 5 — <?= Security::e($label) ?>">
                <?= View::icon('star') ?>
                <span class="visually-hidden"><?= $value ?> étoile<?= $value > 1 ? 's' : '' ?> — <?= Security::e($label) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
