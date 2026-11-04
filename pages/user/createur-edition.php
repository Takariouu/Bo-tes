<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/ResourceManager.php';

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Http;
use Bootes\Src\MediaManager;
use Bootes\Src\PageEditor;
use Bootes\Src\PageLayout;
use Bootes\Src\ResourceManager;
use Bootes\Src\Security;
use Bootes\Src\View;

$db              = $db ?? Database::getInstance();
$resourceManager = new ResourceManager($db);

$currentUserId = Auth::getUserId();
if ($currentUserId === null) {
    Http::redirect('/auth?mode=login');
}

$resourceId = Security::validatePositiveInt($_GET['id'] ?? $_POST['resource_id'] ?? null);
$isEditMode = $resourceId !== null;

// Vérification stricte des permissions si édition d'une ressource existante
if ($isEditMode && !$resourceManager->canManageResource($currentUserId, $resourceId)) {
    http_response_code(403);
    ?>
    <section class="creator-editor creator-editor--forbidden">
        <h1 class="creator-editor__title">Accès refusé</h1>
        <p class="creator-editor__message">Vous n'êtes ni l'auteur de cette ressource, ni un administrateur Boötes.</p>
        <a href="/user/compte" class="creator-editor__btn">Retour à mon compte</a>
    </section>
    <?php
    return;
}

$csrfToken       = Security::generateCsrfToken();
$feedbackMessage = null;
$feedbackError   = null;

// Traitement des formulaires POST (Création, Mise à jour, Ajout de version, Suppression de version, Suppression ressource)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    $action   = Security::validateString($_POST['action'] ?? '', 1, 35) ?? '';

    if (!Security::verifyCsrfToken($postCsrf)) {
        $feedbackError = 'Jeton CSRF invalide. Veuillez soumettre à nouveau le formulaire.';
    } else {
        try {
            if ($action === 'save_resource') {
                $nom        = Security::validateString($_POST['nom_ressource'] ?? '', 3, 100) ?? '';
                $rawType    = Security::validateString($_POST['type_ressource'] ?? 'MOD', 3, 15) ?? 'MOD';
                $type       = ResourceManager::normalizeType($rawType);
                $versionMc  = Security::validateString($_POST['version_compatible'] ?? '1.21.4', 3, 20) ?? '1.21.4';
                $resume     = Security::validateString($_POST['resume'] ?? '', 10, 255) ?? '';
                $desc       = Security::validateMultiline($_POST['description'] ?? '', 20, 10000) ?? '';
                $categories = ResourceManager::encodeCategories(is_array($_POST['categories'] ?? null) ? array_filter($_POST['categories'], 'is_string') : []);
                $loader     = is_string($_POST['chargeur'] ?? null) && $_POST['chargeur'] !== '' ? $_POST['chargeur'] : null;
                $modStatus  = Auth::isAdmin() ? (Security::validateString($_POST['statut_moderation'] ?? '', 3, 20)) : null;

                if ($isEditMode && $resourceId !== null) {
                    if ($resourceManager->updateResource($resourceId, $nom, $type, $versionMc, $resume, $desc, $modStatus)) {
                        $resourceManager->updateClassification($resourceId, $categories, $loader);
                        $feedbackMessage = 'Les informations de la ressource ont été mises à jour avec succès.';
                    } else {
                        $feedbackError = 'Veuillez vérifier que tous les champs respectent les longueurs requises.';
                    }
                } else {
                    $newId = $resourceManager->createResource($currentUserId, $nom, $type, $versionMc, $resume, $desc);
                    if ($newId !== null) {
                        $resourceManager->updateClassification($newId, $categories, $loader);
                        $resourceId = $newId;
                        $isEditMode = true;
                        $feedbackMessage = sprintf(
                            '%s créé avec succès (#%d). Vous pouvez maintenant publier vos fichiers versionnés ci-dessous.',
                            ResourceManager::getTypeLabel($type),
                            $newId
                        );
                    } else {
                        $feedbackError = 'Impossible de créer la ressource. Vérifiez les champs obligatoires.';
                    }
                }
            } elseif ($action === 'upload_version' && $isEditMode && $resourceId !== null) {
                $versionNumber = Security::validateString($_POST['numero_version'] ?? '', 1, 32);
                $changelog     = Security::validateString($_POST['changelog'] ?? '', 0, 2000) ?? '';
                $mcVersions    = isset($_POST['versions_mc']) && is_array($_POST['versions_mc'])
                    ? array_map('strval', $_POST['versions_mc'])
                    : [];

                if ($versionNumber === null || empty($mcVersions)) {
                    $feedbackError = 'Veuillez indiquer un numéro de version (ex: 1.0.0) et cocher au moins une version de Minecraft.';
                } else {
                    $stored = $resourceManager->storeUploadedArchive($_FILES['archive_file'] ?? null, $resourceId);

                    if (!$stored['success']) {
                        $feedbackError = $stored['message'];
                    } elseif ($resourceManager->addVersionFile(
                        $resourceId,
                        $versionNumber,
                        $mcVersions,
                        (string) $stored['original_name'],
                        (string) $stored['stored_name'],
                        (int) $stored['size'],
                        $changelog
                    )) {
                        $feedbackMessage = sprintf('La version %s (%s) a été publiée avec succès.', $versionNumber, (string) $stored['original_name']);
                    } else {
                        $resourceManager->purgeStoredFiles([(string) $stored['stored_name']]);
                        $feedbackError = 'Erreur lors de l\'enregistrement de la version : vérifiez les versions de Minecraft cochées.';
                    }
                }
            } elseif ($action === 'delete_version' && $isEditMode && $resourceId !== null) {
                $fileId = Security::validatePositiveInt($_POST['file_id'] ?? null);
                if ($fileId !== null && $resourceManager->deleteVersionFile($fileId, $resourceId)) {
                    $feedbackMessage = 'Le fichier versionné a été supprimé.';
                } else {
                    $feedbackError = 'Impossible de supprimer cette version de fichier.';
                }
            } elseif ($isEditMode && $resourceId !== null && in_array($action, PageEditor::ACTIONS, true)) {
                // Visuels, galerie et mise en page : droits Premium évalués sur l'offre de l'auteur
                $owner  = $resourceManager->getResourceById($resourceId);
                $result = (new PageEditor(
                    $db,
                    MediaManager::TARGET_RESOURCE,
                    $resourceId,
                    (int) ($owner['id_auteur'] ?? $currentUserId),
                    strtoupper((string) ($owner['auteur_offre'] ?? '')) === Auth::ACCOUNT_PREMIUM
                ))->handle($action, $_POST, $_FILES);
                if ($result !== null && $result['success']) {
                    Http::redirect('/user/createur-edition?id=' . $resourceId . '#' . $result['anchor'], $result['message']);
                }
                $feedbackError = $result['message'] ?? 'Action inconnue.';
            } elseif ($action === 'delete_resource' && $isEditMode && $resourceId !== null) {
                if ($resourceManager->deleteResource($resourceId)) {
                    Http::redirect('/user/compte#section-creations', 'La ressource et ses fichiers ont été supprimés.');
                }
                $feedbackError = 'La suppression de la ressource a échoué.';
            }
        } catch (InvalidArgumentException | PDOException $e) {
            error_log(sprintf('[Boötes][CreateurEdition] Erreur : %s', $e->getMessage()));
            $feedbackError = 'Une erreur est survenue lors du traitement.';
        }
    }
}

$resource     = $isEditMode && $resourceId !== null ? $resourceManager->getResourceById($resourceId) : null;
$versionFiles = $isEditMode && $resourceId !== null ? $resourceManager->getVersionFiles($resourceId) : [];

$defaultType = 'MOD';
if ($resource !== null) {
    $defaultType = (string) $resource['type_ressource'];
} elseif (isset($_GET['type']) && is_string($_GET['type'])) {
    try {
        $defaultType = ResourceManager::normalizeType($_GET['type']);
    } catch (InvalidArgumentException) {
        $defaultType = 'MOD';
    }
}

$isModeratingOther = $resource !== null && (int) $resource['id_auteur'] !== $currentUserId && Auth::isAdmin();
$actionUrl         = $isEditMode && $resourceId !== null
    ? '/user/createur-edition?id=' . $resourceId
    : '/user/createur-edition';
?>
<div class="creator-editor">
    <header class="creator-editor__header">
        <div class="creator-editor__heading">
            <p class="creator-editor__eyebrow">Atelier Créateur &amp; Gestionnaire de Versions</p>
            <h1 class="creator-editor__title">
                <?php if ($resource !== null): ?>
                    Édition de <?= Security::e(ResourceManager::getTypeLabel((string) $resource['type_ressource'])) ?> :
                    <?= Security::e((string) $resource['nom_ressource']) ?>
                <?php else: ?>
                    Publier une nouvelle ressource (Mod, Plugin ou Modpack)
                <?php endif; ?>
            </h1>
        </div>

        <div class="creator-editor__header-actions">
            <?php if ($resource !== null): ?>
                <a href="/ressource-detail?id=<?= (int) $resource['id_ressource'] ?>" class="creator-editor__btn">Voir la fiche publique</a>
                <a href="/user/createur-statistiques?id=<?= (int) $resource['id_ressource'] ?>" class="creator-editor__btn">Statistiques</a>
            <?php endif; ?>
            <a href="/user/compte#section-creations" class="creator-editor__btn creator-editor__btn--ghost">Retour à mes créations</a>
        </div>
    </header>

    <?php if ($isModeratingOther && $resource !== null): ?>
        <aside class="creator-editor__moderation-banner" aria-label="Bannière de modération administrateur">
            <p class="creator-editor__moderation-text">
                <strong>Mode Modération Administrateur :</strong> Vous éditez la ressource de l'auteur
                <strong><?= Security::e((string) $resource['auteur_pseudo']) ?></strong>.
            </p>
        </aside>
    <?php endif; ?>

    <?php if ($feedbackMessage !== null): ?>
        <div class="creator-editor__alert creator-editor__alert--success" role="status">
            <p><?= Security::e($feedbackMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($feedbackError !== null): ?>
        <div class="creator-editor__alert creator-editor__alert--error" role="alert">
            <p><?= Security::e($feedbackError) ?></p>
        </div>
    <?php endif; ?>

    <section class="creator-editor__section creator-editor__section--metadata" aria-labelledby="meta-form-title">
        <h2 id="meta-form-title" class="creator-editor__section-title">
            1. Informations générales de la ressource
        </h2>

        <form action="<?= Security::e($actionUrl) ?>" method="post" class="creator-form">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_resource">
            <?php if ($resource !== null): ?>
                <input type="hidden" name="resource_id" value="<?= (int) $resource['id_ressource'] ?>">
            <?php endif; ?>

            <div class="creator-form__field">
                <label for="res-nom" class="creator-form__label">Nom de la ressource</label>
                <input
                    type="text"
                    id="res-nom"
                    name="nom_ressource"
                    value="<?= Security::e((string) ($resource['nom_ressource'] ?? ($_POST['nom_ressource'] ?? ''))) ?>"
                    minlength="3"
                    maxlength="100"
                    required
                    class="creator-form__input"
                >
            </div>

            <div class="creator-form__field">
                <label for="res-type" class="creator-form__label">Catégorie de ressource</label>
                <select id="res-type" name="type_ressource" required class="creator-form__select">
                    <?php foreach (ResourceManager::ALLOWED_TYPES as $typeKey => $typeLabel): ?>
                        <option value="<?= Security::e($typeKey) ?>" <?= $defaultType === $typeKey ? 'selected' : '' ?>>
                            <?= Security::e($typeLabel) ?> (<?= Security::e($typeKey) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="creator-form__field">
                <label for="res-mc-ver" class="creator-form__label">Version Minecraft principale recommandée</label>
                <select id="res-mc-ver" name="version_compatible" required class="creator-form__select">
                    <?php
                    $selectedMc = (string) ($resource['version_compatible'] ?? '1.21.4');
                    foreach (ResourceManager::SUPPORTED_MC_VERSIONS as $mcVer):
                    ?>
                        <option value="<?= Security::e($mcVer) ?>" <?= $selectedMc === $mcVer ? 'selected' : '' ?>>
                            Minecraft <?= Security::e($mcVer) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (Auth::isAdmin() && $resource !== null): ?>
                <div class="creator-form__field">
                    <label for="res-statut-mod" class="creator-form__label">Statut de modération (Admin uniquement)</label>
                    <select id="res-statut-mod" name="statut_moderation" class="creator-form__select">
                        <?php foreach (ResourceManager::ALLOWED_STATUSES as $stKey => $stLabel): ?>
                            <option value="<?= Security::e($stKey) ?>" <?= ($resource['statut_moderation'] ?? '') === $stKey ? 'selected' : '' ?>>
                                <?= Security::e($stLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="creator-form__field">
                <label for="res-resume" class="creator-form__label">Résumé court (accroche affichée dans le catalogue, 10 à 255 caractères)</label>
                <input
                    type="text"
                    id="res-resume"
                    name="resume"
                    value="<?= Security::e((string) ($resource['resume'] ?? ($_POST['resume'] ?? ''))) ?>"
                    minlength="10"
                    maxlength="255"
                    required
                    class="creator-form__input"
                >
            </div>

            <?php
            $selectedCategories = ResourceManager::decodeCategories((string) ($resource['categories'] ?? ''));
            $selectedLoader     = (string) ($resource['chargeur'] ?? '');
            ?>
            <fieldset class="creator-form__field tag-picker">
                <legend class="creator-form__label">Catégories (<?= ResourceManager::MAX_CATEGORIES ?> au maximum, utilisées par les filtres du catalogue)</legend>
                <div class="tag-picker__options">
                    <?php foreach (ResourceManager::CATEGORIES as $slug => $label): ?>
                        <label class="tag-picker__option">
                            <input type="checkbox" name="categories[]" value="<?= Security::e($slug) ?>"<?= in_array($slug, $selectedCategories, true) ? ' checked' : '' ?> class="tag-picker__input">
                            <span class="tag-picker__chip"><?= Security::e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="creator-form__field">
                <label for="res-loader" class="creator-form__label">Chargeur ou plateforme</label>
                <select id="res-loader" name="chargeur" class="creator-form__select">
                    <option value="">Non précisé</option>
                    <?php foreach (ResourceManager::LOADERS as $code => $label): ?>
                        <option value="<?= Security::e($code) ?>"<?= $selectedLoader === $code ? ' selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="creator-form__field">
                <label for="res-description" class="creator-form__label">Description complète, prérequis et instructions d'installation</label>
                <textarea
                    id="res-description"
                    name="description"
                    rows="8"
                    minlength="20"
                    maxlength="10000"
                    required
                    class="creator-form__textarea"
                ><?= Security::e((string) ($resource['description'] ?? ($_POST['description'] ?? ''))) ?></textarea>
            </div>

            <div class="creator-form__actions">
                <button type="submit" class="creator-form__submit">
                    <?= $resource !== null ? 'Enregistrer les modifications' : 'Créer la ressource et passer aux fichiers' ?>
                </button>
            </div>
        </form>
    </section>

    <?php if ($resource !== null): ?>
        <section class="creator-editor__section creator-editor__section--versioning" aria-labelledby="version-upload-title">
            <h2 id="version-upload-title" class="creator-editor__section-title">
                2. Publier une nouvelle version de fichier (.jar / archive)
            </h2>

            <form action="<?= Security::e($actionUrl) ?>" method="post" enctype="multipart/form-data" class="version-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="action" value="upload_version">
                <input type="hidden" name="resource_id" value="<?= (int) $resource['id_ressource'] ?>">

                <div class="version-form__field">
                    <label for="ver-number" class="version-form__label">Numéro de version (ex: 1.0.0, 2.4.1-beta)</label>
                    <input
                        type="text"
                        id="ver-number"
                        name="numero_version"
                        placeholder="1.0.0"
                        maxlength="32"
                        required
                        class="version-form__input"
                    >
                </div>

                <fieldset class="version-form__fieldset">
                    <legend class="version-form__legend">Versions de Minecraft compatibles avec cette archive</legend>
                    <div class="version-form__checkbox-grid">
                        <?php foreach (ResourceManager::SUPPORTED_MC_VERSIONS as $mcVer): ?>
                            <div class="version-form__checkbox-item">
                                <input
                                    type="checkbox"
                                    id="compat-mc-<?= Security::e($mcVer) ?>"
                                    name="versions_mc[]"
                                    value="<?= Security::e($mcVer) ?>"
                                    class="version-form__checkbox"
                                    <?= $mcVer === $resource['version_compatible'] ? 'checked' : '' ?>
                                >
                                <label for="compat-mc-<?= Security::e($mcVer) ?>" class="version-form__checkbox-label">
                                    MC <?= Security::e($mcVer) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <div class="version-form__field">
                    <label for="ver-file" class="version-form__label">Fichier binaire ou archive (.jar, .zip, .mrpack — 100 Mo max)</label>
                    <div class="dropzone" data-dropzone>
                        <?= View::icon('upload', 'dropzone__icon') ?>
                        <p class="dropzone__text"><strong>Glissez votre archive ici</strong> ou cliquez pour la choisir</p>
                        <p class="dropzone__file" data-dropzone-file>Aucun fichier sélectionné</p>
                        <input type="file" id="ver-file" name="archive_file" accept=".jar,.zip,.mrpack" required class="version-form__file-input dropzone__input">
                    </div>
                </div>

                <div class="version-form__field">
                    <label for="ver-changelog" class="version-form__label">Notes de version (Changelog)</label>
                    <textarea
                        id="ver-changelog"
                        name="changelog"
                        rows="3"
                        maxlength="2000"
                        placeholder="Corrections de bugs, ajouts de fonctionnalités..."
                        class="version-form__textarea"
                    ></textarea>
                </div>

                <button type="submit" class="version-form__submit">Publier cette version</button>
            </form>

            <div class="creator-editor__versions-history">
                <h3 class="creator-editor__subtitle">Historique des fichiers publiés (<?= count($versionFiles) ?>)</h3>
                <?php if (empty($versionFiles)): ?>
                    <p class="creator-editor__empty">Aucun fichier versionné n'a encore été téléversé pour cette ressource.</p>
                <?php else: ?>
                    <table class="versions-admin-table">
                        <caption class="versions-admin-table__caption">Versions associées à <?= Security::e((string) $resource['nom_ressource']) ?></caption>
                        <thead class="versions-admin-table__head">
                            <tr class="versions-admin-table__row">
                                <th scope="col" class="versions-admin-table__cell">Version</th>
                                <th scope="col" class="versions-admin-table__cell">Compatibilité MC</th>
                                <th scope="col" class="versions-admin-table__cell">Nom du fichier</th>
                                <th scope="col" class="versions-admin-table__cell">Taille</th>
                                <th scope="col" class="versions-admin-table__cell">Changelog</th>
                                <th scope="col" class="versions-admin-table__cell">Date d'ajout</th>
                                <th scope="col" class="versions-admin-table__cell">Action</th>
                            </tr>
                        </thead>
                        <tbody class="versions-admin-table__body">
                            <?php foreach ($versionFiles as $vf): ?>
                                <tr class="versions-admin-table__row">
                                    <th scope="row" class="versions-admin-table__cell"><?= Security::e((string) $vf['numero_version']) ?></th>
                                    <td class="versions-admin-table__cell"><?= Security::e((string) $vf['version_mc']) ?></td>
                                    <td class="versions-admin-table__cell"><code><?= Security::e((string) $vf['nom_fichier']) ?></code></td>
                                    <td class="versions-admin-table__cell"><?= round(((int) $vf['taille_octets']) / 1024, 1) ?> Ko</td>
                                    <td class="versions-admin-table__cell"><?= Security::e((string) ($vf['changelog'] ?: '—')) ?></td>
                                    <td class="versions-admin-table__cell"><?= Security::e((string) $vf['date_ajout']) ?></td>
                                    <td class="versions-admin-table__cell">
                                        <form action="<?= Security::e($actionUrl) ?>" method="post" class="versions-admin-table__form">
                                            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete_version">
                                            <input type="hidden" name="resource_id" value="<?= (int) $resource['id_ressource'] ?>">
                                            <input type="hidden" name="file_id" value="<?= (int) $vf['id_fichier'] ?>">
                                            <button type="submit" class="versions-admin-table__delete-btn" data-confirm="Supprimer définitivement ce fichier de version ?">Supprimer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>

        <?php
        $media                  = new MediaManager($db);
        $iconToken              = $media->firstToken(MediaManager::TARGET_RESOURCE, (int) $resource['id_ressource'], MediaManager::ROLE_ICON);
        $bannerToken            = $media->firstToken(MediaManager::TARGET_RESOURCE, (int) $resource['id_ressource'], MediaManager::ROLE_BANNER);
        $galleryMedia           = $media->forTarget(MediaManager::TARGET_RESOURCE, (int) $resource['id_ressource'], MediaManager::ROLE_GALLERY);
        $layout                 = (new PageLayout($db))->load(MediaManager::TARGET_RESOURCE, (int) $resource['id_ressource']);
        $editorActionUrl        = $actionUrl;
        $editorHidden           = ['resource_id' => (int) $resource['id_ressource']];
        $editorPremium          = strtoupper((string) ($resource['auteur_offre'] ?? '')) === Auth::ACCOUNT_PREMIUM;
        $editorName             = (string) $resource['nom_ressource'];
        $editorPublicUrl        = '/ressource-detail?id=' . (int) $resource['id_ressource'];
        $editorPresentationNote = 'Le texte de présentation est la description complète saisie dans la section 1.';
        require __DIR__ . '/../../includes/fiche/editor.php';
        ?>

        <section class="creator-editor__section creator-editor__section--danger" aria-labelledby="danger-zone-title">
            <h2 id="danger-zone-title" class="creator-editor__section-title">3. Zone de suppression définitive</h2>
            <p class="creator-editor__danger-text">
                La suppression de cette ressource entraînera le retrait immédiat de toutes ses versions et de ses avis associés du catalogue Boötes.
            </p>
            <form action="<?= Security::e($actionUrl) ?>" method="post" class="creator-editor__delete-form">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="action" value="delete_resource">
                <input type="hidden" name="resource_id" value="<?= (int) $resource['id_ressource'] ?>">
                <button type="submit" class="creator-editor__btn creator-editor__btn--danger" data-confirm="Supprimer définitivement cette ressource, toutes ses versions et ses avis ?">
                    Supprimer définitivement cette ressource
                </button>
            </form>
        </section>
    <?php endif; ?>
</div>