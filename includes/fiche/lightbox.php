<?php

declare(strict_types=1);

use Bootes\Src\View;

/** Visionneuse plein écran partagée par les galeries de la page (pilotée par assets/js/script.js). */
?>
<dialog class="lightbox" data-lightbox aria-label="Visionneuse d'images">
    <figure class="lightbox__figure">
        <img class="lightbox__image" data-lightbox-image alt="">
        <figcaption class="lightbox__caption" data-lightbox-caption></figcaption>
    </figure>
    <p class="lightbox__counter" data-lightbox-counter aria-live="polite"></p>
    <button type="button" class="lightbox__close" data-lightbox-close aria-label="Fermer la visionneuse"><?= View::icon('x') ?></button>
    <button type="button" class="lightbox__nav lightbox__nav--prev" data-lightbox-prev aria-label="Image précédente"><?= View::icon('chevron-left') ?></button>
    <button type="button" class="lightbox__nav lightbox__nav--next" data-lightbox-next aria-label="Image suivante"><?= View::icon('chevron-right') ?></button>
</dialog>
