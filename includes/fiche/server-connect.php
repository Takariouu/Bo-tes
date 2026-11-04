<?php

declare(strict_types=1);

use Bootes\Src\Security;
use Bootes\Src\ServerDirectory;
use Bootes\Src\View;

/**
 * Barre de connexion d'une fiche serveur : statut, adresse IP, bouton « Copier l'IP », joueurs et version.
 *
 * Elle est rendue HORS de la zone de blocs personnalisables : PageLayout ne la connaît pas, aucun thème
 * ne la recolore et elle occupe la même place (collée sous l'en-tête du site) sur TOUTES les fiches,
 * qu'elles soient Standard ou personnalisées par un membre Premium.
 *
 * @var array<string, mixed> $server
 */
$connectAddress  = ServerDirectory::formatAddress((string) $server['adresse_ip'], (int) $server['port']);
$connectExternal = (string) $server['origine'] === ServerDirectory::ORIGIN_EXTERNAL;
$connectUnknown  = $connectExternal && empty($server['date_verification']);
$connectOnline   = !$connectUnknown && strtoupper((string) $server['statut']) === 'EN_LIGNE';
$connectSlots    = (int) $server['slots_max'];
$connectTone     = $connectUnknown ? 'unknown' : View::tone('statut_serveur', $server['statut']);
?>
<section class="connect-bar connect-bar__live--<?= Security::e($connectTone) ?>" aria-label="Rejoindre le serveur">
    <p class="connect-bar__state">
        <span class="connect-bar__dot" aria-hidden="true"></span>
        <strong><?= $connectUnknown ? 'Statut inconnu' : Security::e(View::label('statut_serveur', $server['statut'])) ?></strong>
        <?php if ($connectOnline): ?>
            <span class="connect-bar__players"><?= View::number((int) $server['joueurs_connectes']) ?><?= $connectSlots > 0 ? ' / ' . View::number($connectSlots) : '' ?> joueurs</span>
        <?php endif; ?>
    </p>
    <div class="connect-bar__address">
        <span class="connect-bar__label">Adresse IP</span>
        <code class="connect-bar__ip"><?= Security::e($connectAddress) ?></code>
    </div>
    <button type="button" class="connect-bar__copy connect-bar__copy--primary" data-copy="<?= Security::e($connectAddress) ?>">
        <?= View::icon('copy') ?> Copier l'IP
    </button>
    <p class="connect-bar__meta">
        <?= View::icon('box') ?> Java <?= Security::e((string) $server['version_mc']) ?>
        <?php if ($connectExternal && !$connectUnknown): ?>
            <span class="connect-bar__checked">· vérifié <?= Security::e(View::ago((string) $server['date_verification'])) ?></span>
        <?php endif; ?>
    </p>
</section>
