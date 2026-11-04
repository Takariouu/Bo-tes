<?php

declare(strict_types=1);

use Bootes\Src\Auth;
use Bootes\Src\Security;
use Bootes\Src\ServerDirectory;
use Bootes\Src\View;

/**
 * Ligne compacte de l'annuaire : rang, logo, nom et badges, slogan, étiquettes, adresse avec bouton
 * « Copier l'IP », statut et joueurs, version et note.
 *
 * @var array<string, mixed> $row     ligne de ServerDirectory::search()
 * @var ?int                 $rowRank rang affiché (null : pas de rang)
 * @var ?string              $rowIcon jeton du logo
 */
$rowUrl      = '/serveur-public?id=' . (int) $row['id_serveur'];
$rowAddress  = ServerDirectory::formatAddress((string) $row['adresse_ip'], (int) $row['port']);
$rowExternal = (string) $row['origine'] === ServerDirectory::ORIGIN_EXTERNAL;
$rowPremium  = strtoupper((string) ($row['proprietaire_offre'] ?? '')) === Auth::ACCOUNT_PREMIUM;
$rowUnknown  = $rowExternal && empty($row['date_verification']);
$rowOnline   = !$rowUnknown && strtoupper((string) $row['statut']) === 'EN_LIGNE';
$rowSlots    = (int) $row['slots_max'];
$rowPlayers  = max(0, (int) $row['joueurs_connectes']);
$rowRating   = round((float) $row['note'], 1);
$rowTags     = array_map(static fn (string $t): string => ServerDirectory::TAGS[$t], ServerDirectory::decodeTags($row['etiquettes'] ?? ''));
$rowSummary  = (string) ($row['resume'] ?? '') !== '' ? (string) $row['resume'] : mb_strimwidth((string) ($row['description'] ?? ''), 0, 160, '…');
$statusClass = $rowUnknown ? 'unknown' : View::tone('statut_serveur', $row['statut']);
?>
<article class="server-row<?= $rowPremium ? ' server-row--premium' : '' ?>">
    <?php if (isset($rowRank)): ?>
        <span class="server-row__rank" aria-label="Rang <?= (int) $rowRank ?>">#<?= (int) $rowRank ?></span>
    <?php else: ?>
        <span class="server-row__rank" aria-hidden="true"></span>
    <?php endif; ?>
    <a href="<?= Security::e($rowUrl) ?>" class="server-row__visual" tabindex="-1" aria-hidden="true">
        <?= View::tile($rowIcon ?? null, (string) $row['nom_serveur'], 'tile--md') ?>
    </a>

    <div class="server-row__main">
        <h3 class="server-row__name">
            <a href="<?= Security::e($rowUrl) ?>" class="server-row__link"><?= Security::e((string) $row['nom_serveur']) ?></a>
            <?php if (!$rowExternal): ?>
                <span class="badge badge--accent server-row__badge" title="Instance hébergée sur Boötes"><?= View::icon('orbit') ?> Boötes</span>
            <?php endif; ?>
            <?php if ($rowPremium): ?>
                <span class="badge badge--premium server-row__badge"><?= View::icon('gem') ?> Premium</span>
            <?php endif; ?>
        </h3>
        <?php if ($rowSummary !== ''): ?>
            <p class="server-row__summary"><?= Security::e($rowSummary) ?></p>
        <?php endif; ?>
        <?php if ($rowTags !== []): ?>
            <ul class="server-row__tags" aria-label="Étiquettes">
                <?php foreach (array_slice($rowTags, 0, 3) as $tag): ?>
                    <li class="server-row__tag"><?= Security::e($tag) ?></li>
                <?php endforeach; ?>
                <?php if (count($rowTags) > 3): ?>
                    <li class="server-row__tag server-row__tag--more">+<?= count($rowTags) - 3 ?></li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="server-row__address">
        <code class="server-row__ip" title="Adresse à saisir dans Minecraft"><?= Security::e($rowAddress) ?></code>
        <button type="button" class="server-row__copy" data-copy="<?= Security::e($rowAddress) ?>" aria-label="Copier l'adresse <?= Security::e($rowAddress) ?>">
            <?= View::icon('copy') ?><span class="server-row__copy-label">Copier l'IP</span>
        </button>
    </div>

    <div class="server-row__live server-row__live--<?= Security::e($statusClass) ?>">
        <p class="server-row__state">
            <span class="server-row__dot" aria-hidden="true"></span>
            <?= $rowUnknown ? 'Statut inconnu' : Security::e(View::label('statut_serveur', $row['statut'])) ?>
        </p>
        <p class="server-row__players">
            <?= View::icon('users') ?>
            <?php if ($rowOnline): ?>
                <strong><?= View::number($rowPlayers) ?></strong><?= $rowSlots > 0 ? ' / ' . View::number($rowSlots) : '' ?>
            <?php else: ?>
                —
            <?php endif; ?>
        </p>
        <?php if ($rowOnline && $rowSlots > 0): ?>
            <meter class="server-row__meter" min="0" max="<?= $rowSlots ?>" value="<?= min($rowPlayers, $rowSlots) ?>"
                   low="<?= (int) ceil($rowSlots * 0.75) ?>" high="<?= (int) ceil($rowSlots * 0.9) ?>" optimum="0"
                   aria-label="Remplissage du serveur"></meter>
        <?php endif; ?>
    </div>

    <dl class="server-row__stats">
        <div class="server-row__stat">
            <dt class="visually-hidden">Version</dt>
            <dd><?= View::icon('box') ?> <?= Security::e((string) $row['version_mc']) ?></dd>
        </div>
        <div class="server-row__stat">
            <dt class="visually-hidden">Note moyenne</dt>
            <dd class="server-row__rating" title="<?= (int) $row['nb_avis'] ?> avis"><?= View::icon('star') ?> <?= $rowRating > 0 ? View::number($rowRating, 1) : '—' ?></dd>
        </div>
    </dl>
</article>
