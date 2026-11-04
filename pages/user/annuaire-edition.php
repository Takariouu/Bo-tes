<?php

declare(strict_types=1);

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Http;
use Bootes\Src\MediaManager;
use Bootes\Src\PageEditor;
use Bootes\Src\PageLayout;
use Bootes\Src\Security;
use Bootes\Src\ServerDirectory;
use Bootes\Src\ServerManager;
use Bootes\Src\View;

// Ajout d'un serveur externe à l'annuaire, ou modification de la fiche publique d'un serveur
// (externe ou hébergé). Réservé au propriétaire de la fiche et aux administrateurs.
$db            = $db ?? Database::getInstance();
$currentUserId = (int) Auth::getUserId();
$serverManager = new ServerManager($db);
$directory     = new ServerDirectory($db);
$serverId      = Security::validatePositiveInt($_GET['id'] ?? $_POST['server_id'] ?? null);
$server        = $serverId !== null ? $serverManager->getServerById($serverId) : null;

if ($serverId !== null && ($server === null || ((int) $server['id_proprietaire'] !== $currentUserId && !Auth::isAdmin()))) {
    http_response_code(404);
    ?>
    <section class="creator-editor creator-editor--forbidden">
        <h1 class="creator-editor__title">Fiche introuvable</h1>
        <p class="creator-editor__message">Ce serveur n'existe pas ou sa fiche ne vous appartient pas.</p>
        <a href="/user/compte#section-annuaire" class="creator-editor__btn">Retour à mes fiches</a>
    </section>
    <?php
    return;
}

$isNew         = $server === null;
$isExternal    = $isNew || (string) $server['origine'] === ServerDirectory::ORIGIN_EXTERNAL;
$ownerId       = $isNew ? $currentUserId : (int) $server['id_proprietaire'];
$ownerPremium  = $isNew ? Auth::isPremium() : strtoupper((string) ($server['proprietaire_offre'] ?? '')) === Auth::ACCOUNT_PREMIUM;
$actionUrl     = $isNew ? '/user/annuaire-edition' : '/user/annuaire-edition?id=' . $serverId;
$csrfToken     = Security::generateCsrfToken();
$errors        = [];
$feedbackError = null;
$listingCount  = $directory->countListings($currentUserId);
$listingLimit  = ServerDirectory::maxListings(Auth::isPremium());

$form = [
    'nom_serveur' => (string) ($server['nom_serveur'] ?? ''),
    'adresse_ip'  => (string) ($server['adresse_ip'] ?? ''),
    'port'        => (string) ($server['port'] ?? ServerDirectory::DEFAULT_PORT),
    'version_mc'  => (string) ($server['version_mc'] ?? ServerDirectory::VERSIONS[0]),
    'mode_jeu'    => (string) ($server['mode_jeu'] ?? 'SURVIE'),
    'resume'      => (string) ($server['resume'] ?? ''),
    'description' => (string) ($server['description'] ?? ''),
    'etiquettes'  => ServerDirectory::decodeTags($server['etiquettes'] ?? ''),
    'site_web'    => (string) ($server['site_web'] ?? ''),
    'discord'     => (string) ($server['discord'] ?? ''),
    'est_public'  => $isNew || (int) $server['est_public'] === 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = Security::validateString($_POST['action'] ?? '', 1, 30) ?? '';

    if (!Security::verifyCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        $feedbackError = 'Jeton de sécurité CSRF invalide. Veuillez recharger la page.';
    } elseif ($action === 'save_listing') {
        [$data, $errors] = ServerDirectory::validate($_POST, $isExternal);
        if ($errors === []) {
            if ($isNew) {
                $created = $directory->create($currentUserId, Auth::isPremium(), $data);
                if ($created['success']) {
                    $newServer = $serverManager->getServerById((int) $created['id']);
                    if ($newServer !== null) {
                        $directory->refreshStatus($newServer);
                    }
                    Http::redirect('/user/annuaire-edition?id=' . (int) $created['id'] . '#visuels', 'Serveur ajouté à l\'annuaire : ajoutez maintenant son icône et des captures d\'écran.');
                }
                $feedbackError = $created['message'];
            } else {
                $updated = $directory->update($server, $data);
                if ($updated['success']) {
                    Http::redirect($actionUrl . '#informations', $updated['message']);
                }
                $feedbackError = $updated['message'];
            }
        } else {
            $feedbackError = 'Certains champs sont à corriger.';
        }
        // Le formulaire est réaffiché avec la saisie (valeurs scalaires uniquement)
        $textFields = ['nom_serveur', 'adresse_ip', 'port', 'version_mc', 'mode_jeu', 'resume', 'description', 'site_web', 'discord'];
        $posted     = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', array_intersect_key($_POST, array_flip($textFields)));
        $form       = array_merge($form, $posted, [
            'etiquettes' => is_array($_POST['etiquettes'] ?? null) ? array_values(array_filter($_POST['etiquettes'], 'is_string')) : [],
            'est_public' => !empty($_POST['est_public']),
        ]);
    } elseif ($action === 'delete_listing' && !$isNew && $isExternal) {
        if ($serverManager->deleteServer((int) $serverId)) {
            Http::redirect('/user/compte#section-annuaire', 'Le serveur a été retiré de l\'annuaire.');
        }
        $feedbackError = 'La fiche n\'a pas pu être supprimée.';
    } elseif (!$isNew) {
        $result = (new PageEditor($db, MediaManager::TARGET_SERVER, (int) $serverId, $ownerId, $ownerPremium))->handle($action, $_POST, $_FILES);
        if ($result !== null && $result['success']) {
            Http::redirect($actionUrl . '#' . $result['anchor'], $result['message']);
        }
        $feedbackError = $result['message'] ?? 'Action inconnue.';
    }
}

// Visuels et mise en page actuels (fiche existante)
$iconToken    = null;
$bannerToken  = null;
$galleryMedia = [];
$layout       = PageLayout::defaultLayout();
if (!$isNew) {
    $media        = new MediaManager($db);
    $iconToken    = $media->firstToken(MediaManager::TARGET_SERVER, (int) $serverId, MediaManager::ROLE_ICON);
    $bannerToken  = $media->firstToken(MediaManager::TARGET_SERVER, (int) $serverId, MediaManager::ROLE_BANNER);
    $galleryMedia = $media->forTarget(MediaManager::TARGET_SERVER, (int) $serverId, MediaManager::ROLE_GALLERY);
    $layout       = (new PageLayout($db))->load(MediaManager::TARGET_SERVER, (int) $serverId);
}

$fieldError = static fn (string $field): string => isset($errors[$field])
    ? '<p class="form-error" id="err-' . Security::e($field) . '">' . Security::e($errors[$field]) . '</p>'
    : '';
$invalid = static fn (string $field): string => isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="err-' . Security::e($field) . '"' : '';

$pageTitle       = ($isNew ? 'Ajouter un serveur à l\'annuaire' : 'Fiche de ' . (string) $server['nom_serveur']) . ' — Boötes';
$pageBreadcrumbs = [
    ['label' => 'Accueil', 'url' => '/home'],
    ['label' => 'Mon compte', 'url' => '/user/compte'],
    ['label' => 'Mes fiches d\'annuaire', 'url' => '/user/compte#section-annuaire'],
    ['label' => $isNew ? 'Nouveau serveur' : (string) $server['nom_serveur'], 'url' => null],
];
?>
<div class="listing-editor">
    <header class="listing-editor__header">
        <div>
            <p class="listing-editor__eyebrow"><?= $isExternal ? 'Annuaire public' : 'Serveur hébergé sur Boötes' ?></p>
            <h1 class="listing-editor__title"><?= $isNew ? 'Ajouter un serveur à l\'annuaire' : 'Fiche de ' . Security::e((string) $server['nom_serveur']) ?></h1>
            <p class="listing-editor__subtitle">
                <?= $isNew
                    ? 'Référencez n\'importe quel serveur Minecraft Java, hébergé où vous voulez : il apparaîtra dans l\'annuaire avec son statut vérifié automatiquement.'
                    : 'Présentation, visuels, galerie et mise en page de la fiche publique.' ?>
            </p>
        </div>
        <?php if (!$isNew): ?>
            <div class="listing-editor__actions">
                <a href="/serveur-public?id=<?= (int) $serverId ?>" class="listing-editor__btn"><?= View::icon('eye') ?> Voir la fiche</a>
                <?php if (!$isExternal): ?>
                    <a href="/serveur/dashboard?id=<?= (int) $serverId ?>" class="listing-editor__btn"><?= View::icon('settings') ?> Panneau du serveur</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($feedbackError !== null): ?>
        <div class="listing-editor__alert listing-editor__alert--error" role="alert"><p><?= Security::e($feedbackError) ?></p></div>
    <?php endif; ?>

    <?php if (!$isNew): ?>
        <nav class="listing-editor__nav" aria-label="Sections de l'éditeur">
            <a href="#informations" class="listing-editor__nav-link"><?= View::icon('file-text') ?> Informations</a>
            <a href="#visuels" class="listing-editor__nav-link"><?= View::icon('image') ?> Visuels</a>
            <a href="#galerie" class="listing-editor__nav-link"><?= View::icon('images') ?> Galerie <span class="listing-editor__count"><?= count($galleryMedia) ?></span></a>
            <a href="#mise-en-page" class="listing-editor__nav-link"><?= View::icon('wand-sparkles') ?> Mise en page</a>
        </nav>
    <?php elseif ($listingCount >= $listingLimit): ?>
        <div class="listing-editor__alert listing-editor__alert--warning" role="status">
            <p>
                Vous avez déjà référencé <?= $listingCount ?> serveur<?= $listingCount > 1 ? 's' : '' ?> sur <?= $listingLimit ?> permis par votre offre.
                <?php if (!Auth::isPremium()): ?><a href="/offres">Passez Premium</a> pour en référencer jusqu'à <?= ServerDirectory::PREMIUM_MAX_LISTINGS ?>.<?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <section id="informations" class="editor-section" aria-labelledby="info-title">
        <header class="editor-section__header">
            <h2 id="info-title" class="editor-section__title"><?= View::icon('file-text') ?> Informations</h2>
            <?php if ($isNew): ?>
                <p class="editor-section__subtitle">Fiches utilisées : <?= $listingCount ?> / <?= $listingLimit ?>.</p>
            <?php endif; ?>
        </header>

        <form action="<?= Security::e($actionUrl) ?>" method="post" class="listing-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_listing">

            <?php if ($isExternal): ?>
                <div class="listing-form__grid">
                    <div class="listing-form__field listing-form__field--wide">
                        <label for="lst-nom" class="listing-form__label">Nom du serveur</label>
                        <input type="text" id="lst-nom" name="nom_serveur" value="<?= Security::e($form['nom_serveur']) ?>" required minlength="3" maxlength="64" class="listing-form__input"<?= $invalid('nom_serveur') ?>>
                        <?= $fieldError('nom_serveur') ?>
                    </div>
                    <div class="listing-form__field listing-form__field--address">
                        <label for="lst-adresse" class="listing-form__label">Adresse (nom de domaine ou IP publique)</label>
                        <input type="text" id="lst-adresse" name="adresse_ip" value="<?= Security::e($form['adresse_ip']) ?>" required maxlength="253" placeholder="play.monserveur.fr" spellcheck="false" autocapitalize="off" class="listing-form__input listing-form__input--mono"<?= $invalid('adresse_ip') ?>>
                        <?= $fieldError('adresse_ip') ?>
                    </div>
                    <div class="listing-form__field">
                        <label for="lst-port" class="listing-form__label">Port</label>
                        <input type="number" id="lst-port" name="port" value="<?= Security::e($form['port']) ?>" min="1024" max="65535" class="listing-form__input listing-form__input--mono">
                    </div>
                    <div class="listing-form__field">
                        <label for="lst-version" class="listing-form__label">Version principale</label>
                        <select id="lst-version" name="version_mc" class="listing-form__select"<?= $invalid('version_mc') ?>>
                            <?php foreach (ServerDirectory::VERSIONS as $version): ?>
                                <option value="<?= Security::e($version) ?>"<?= $form['version_mc'] === $version ? ' selected' : '' ?>><?= Security::e($version) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $fieldError('version_mc') ?>
                    </div>
                    <div class="listing-form__field">
                        <label for="lst-mode" class="listing-form__label">Mode de jeu principal</label>
                        <select id="lst-mode" name="mode_jeu" class="listing-form__select"<?= $invalid('mode_jeu') ?>>
                            <?php foreach (ServerManager::GAME_MODES as $mode => $label): ?>
                                <option value="<?= Security::e($mode) ?>"<?= $form['mode_jeu'] === $mode ? ' selected' : '' ?>><?= Security::e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $fieldError('mode_jeu') ?>
                    </div>
                </div>
            <?php else: ?>
                <p class="listing-form__readonly">
                    <?= View::icon('lock') ?>
                    Adresse <code><?= Security::e(ServerDirectory::formatAddress((string) $server['adresse_ip'], (int) $server['port'])) ?></code>,
                    version <?= Security::e((string) $server['version_mc']) ?> et mode de jeu sont gérés depuis le
                    <a href="/serveur/dashboard?id=<?= (int) $serverId ?>">panneau du serveur</a>.
                </p>
            <?php endif; ?>

            <div class="listing-form__field">
                <label for="lst-resume" class="listing-form__label">Slogan <span class="listing-form__hint">affiché dans l'annuaire, 160 caractères</span></label>
                <input type="text" id="lst-resume" name="resume" value="<?= Security::e($form['resume']) ?>" maxlength="160" placeholder="Survie semi-RP, économie joueurs, events chaque week-end" class="listing-form__input"<?= $invalid('resume') ?>>
                <?= $fieldError('resume') ?>
            </div>

            <div class="listing-form__field">
                <label for="lst-description" class="listing-form__label">Présentation <span class="listing-form__hint">ligne vide = nouveau paragraphe, « - » = liste, **gras**</span></label>
                <textarea id="lst-description" name="description" rows="7" maxlength="5000" class="listing-form__textarea"<?= $invalid('description') ?>><?= Security::e($form['description']) ?></textarea>
                <?= $fieldError('description') ?>
            </div>

            <fieldset class="listing-form__field tag-picker">
                <legend class="listing-form__label">Étiquettes <span class="listing-form__hint"><?= ServerDirectory::MAX_TAGS ?> au maximum, utilisées par les filtres de l'annuaire</span></legend>
                <div class="tag-picker__options">
                    <?php foreach (ServerDirectory::TAGS as $slug => $label): ?>
                        <label class="tag-picker__option">
                            <input type="checkbox" name="etiquettes[]" value="<?= Security::e($slug) ?>"<?= in_array($slug, $form['etiquettes'], true) ? ' checked' : '' ?> class="tag-picker__input">
                            <span class="tag-picker__chip"><?= Security::e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?= $fieldError('etiquettes') ?>
            </fieldset>

            <div class="listing-form__grid">
                <div class="listing-form__field">
                    <label for="lst-site" class="listing-form__label">Site web</label>
                    <input type="url" id="lst-site" name="site_web" value="<?= Security::e($form['site_web']) ?>" maxlength="255" placeholder="https://monserveur.fr" class="listing-form__input"<?= $invalid('site_web') ?>>
                    <?= $fieldError('site_web') ?>
                </div>
                <div class="listing-form__field">
                    <label for="lst-discord" class="listing-form__label">Invitation Discord</label>
                    <input type="url" id="lst-discord" name="discord" value="<?= Security::e($form['discord']) ?>" maxlength="255" placeholder="https://discord.gg/…" class="listing-form__input"<?= $invalid('discord') ?>>
                    <?= $fieldError('discord') ?>
                </div>
            </div>

            <?php if ($isExternal): ?>
                <label class="listing-form__check">
                    <input type="checkbox" name="est_public" value="1"<?= $form['est_public'] ? ' checked' : '' ?>>
                    Afficher ce serveur dans l'annuaire public
                </label>
            <?php endif; ?>

            <div class="listing-form__actions">
                <button type="submit" class="listing-form__submit"<?= $isNew && $listingCount >= $listingLimit ? ' disabled' : '' ?>>
                    <?= View::icon($isNew ? 'plus' : 'save') ?> <?= $isNew ? 'Ajouter à l\'annuaire' : 'Enregistrer les informations' ?>
                </button>
            </div>
        </form>
    </section>

    <?php if (!$isNew): ?>
        <?php
        $editorActionUrl        = $actionUrl;
        $editorHidden           = ['server_id' => (int) $serverId];
        $editorPremium          = $ownerPremium;
        $editorName             = (string) $server['nom_serveur'];
        $editorPublicUrl        = '/serveur-public?id=' . (int) $serverId;
        $editorPresentationNote = 'Le texte de présentation se modifie dans la section « Informations » ci-dessus.';
        require __DIR__ . '/../../includes/fiche/editor.php';
        ?>

        <?php if ($isExternal): ?>
            <section class="editor-section editor-section--danger" aria-labelledby="danger-title">
                <h2 id="danger-title" class="editor-section__title"><?= View::icon('triangle-alert') ?> Retirer de l'annuaire</h2>
                <p class="editor-section__subtitle">La fiche, ses images, ses avis et sa mise en page seront définitivement supprimés.</p>
                <form action="<?= Security::e($actionUrl) ?>" method="post" class="listing-editor__inline">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_listing">
                    <button type="submit" class="listing-editor__btn btn--danger" data-confirm="Supprimer définitivement la fiche de ce serveur ?"><?= View::icon('trash-2') ?> Supprimer la fiche</button>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
