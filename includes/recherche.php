<?php

declare(strict_types=1);

use Bootes\Src\Security;

$searchAction   = isset($searchAction) && is_string($searchAction) ? $searchAction : '/catalogue';
$searchQuery    = Security::validateString($_GET['q'] ?? '', 0, 100) ?? '';
$selectedType   = Security::validateString($_GET['type'] ?? 'all', 1, 30) ?? 'all';
$selectedVer    = Security::validateString($_GET['version'] ?? 'all', 1, 20) ?? 'all';

$allowedTypes = [
    'all'      => 'Toutes les catégories',
    'serveurs' => 'Serveurs',
    'mods'     => 'Mods',
    'modpacks' => 'Modpacks',
    'plugins'  => 'Plugins',
];

$allowedVersions = [
    'all'    => 'Toutes les versions',
    '1.21.4' => 'Minecraft 1.21.4',
    '1.21.1' => 'Minecraft 1.21.1',
    '1.20.4' => 'Minecraft 1.20.4',
    '1.20.1' => 'Minecraft 1.20.1',
    '1.19.4' => 'Minecraft 1.19.4',
    '1.18.2' => 'Minecraft 1.18.2',
    '1.16.5' => 'Minecraft 1.16.5',
    '1.12.2' => 'Minecraft 1.12.2',
];

if (!array_key_exists($selectedType, $allowedTypes)) {
    $selectedType = 'all';
}

if (!array_key_exists($selectedVer, $allowedVersions)) {
    $selectedVer = 'all';
}
?>
<section class="search-bar" aria-label="Recherche et filtrage du catalogue">
    <form action="<?= Security::e($searchAction) ?>" method="get" class="search-bar__form" role="search">
        <div class="search-bar__field-group search-bar__field-group--query">
            <label for="bootes-search-input" class="search-bar__label">Rechercher</label>
            <input
                type="search"
                id="bootes-search-input"
                name="q"
                value="<?= Security::e($searchQuery) ?>"
                placeholder="Nom d'un serveur, mod, modpack, plugin..."
                maxlength="100"
                class="search-bar__input"
            >
        </div>

        <fieldset class="search-bar__fieldset">
            <legend class="search-bar__legend">Catégorie</legend>
            <div class="search-bar__radios">
                <?php foreach ($allowedTypes as $typeKey => $typeLabel): ?>
                    <div class="search-bar__radio-item">
                        <input
                            type="radio"
                            id="filter-type-<?= Security::e($typeKey) ?>"
                            name="type"
                            value="<?= Security::e($typeKey) ?>"
                            class="search-bar__radio"
                            <?= $selectedType === $typeKey ? 'checked' : '' ?>
                        >
                        <label for="filter-type-<?= Security::e($typeKey) ?>" class="search-bar__radio-label">
                            <?= Security::e($typeLabel) ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="search-bar__field-group search-bar__field-group--version">
            <label for="bootes-version-select" class="search-bar__label">Version Minecraft</label>
            <select id="bootes-version-select" name="version" class="search-bar__select">
                <?php foreach ($allowedVersions as $verKey => $verLabel): ?>
                    <option value="<?= Security::e($verKey) ?>" <?= $selectedVer === $verKey ? 'selected' : '' ?>>
                        <?= Security::e($verLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="search-bar__actions">
            <button type="submit" class="search-bar__submit">Filtrer</button>
            <a href="<?= Security::e($searchAction) ?>" class="search-bar__reset">Réinitialiser</a>
        </div>
    </form>
</section>