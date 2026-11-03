<?php

declare(strict_types=1);

use Bootes\Src\Security;

$breadcrumbs = isset($breadcrumbs) && is_array($breadcrumbs) ? $breadcrumbs : [];

// Inutile pour les pages de premier niveau (Accueil › Page) : la navigation principale suffit.
if (count($breadcrumbs) < 3) {
    return;
}

$lastIndex = count($breadcrumbs) - 1;
?>
<nav class="breadcrumb" aria-label="Fil d'Ariane">
    <ol class="breadcrumb__list">
        <?php foreach ($breadcrumbs as $index => $crumb): ?>
            <li class="breadcrumb__item">
                <?php if ($index < $lastIndex && ($crumb['url'] ?? null) !== null): ?>
                    <a href="<?= Security::e((string) $crumb['url']) ?>" class="breadcrumb__link"><?= Security::e((string) $crumb['label']) ?></a>
                <?php else: ?>
                    <span class="breadcrumb__current"<?= $index === $lastIndex ? ' aria-current="page"' : '' ?>><?= Security::e((string) $crumb['label']) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
