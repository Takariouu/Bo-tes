<?php

declare(strict_types=1);

use Bootes\Src\Auth;
use Bootes\Src\Security;
use Bootes\Src\View;

$isLogged  = Auth::isLogged();
$userTier  = $isLogged ? (string) ($_SESSION['user']['type_compte'] ?? Auth::ACCOUNT_STANDARD) : null;

$comparison = [
    ['Mémoire RAM par compte', '512 Mo à 2 Go', 'Jusqu\'à 6 Go (selon disponibilité de l\'hôte)'],
    ['Serveurs Minecraft', '3 serveurs', '10 serveurs'],
    ['Sauvegardes par serveur', '2 sauvegardes', '10 sauvegardes'],
    ['Modpacks', 'Modpacks légers (2 Go max)', 'Tous les modpacks du catalogue'],
    ['Carte 3D dans le navigateur', false, true],
    ['Console, journaux et statistiques', true, true],
    ['Co-administrateurs', true, true],
    ['Annuaire public', true, 'Oui, mis en avant'],
];
?>
<section class="offers" aria-labelledby="offers-title">
    <header class="offers__header">
        <p class="offers__eyebrow">Offres d'hébergement</p>
        <h1 id="offers-title" class="offers__title">Une offre pour chaque monde</h1>
        <p class="offers__lead">
            Boötes tourne sur une machine Linux Mint de 8 Go de mémoire vive. 6 Go sont strictement réservés aux serveurs
            Minecraft et répartis équitablement entre les joueurs.
        </p>
    </header>

    <div class="offers__capacity" aria-label="Répartition de la mémoire de la machine hôte">
        <div class="offers__capacity-bar" aria-hidden="true">
            <span class="offers__capacity-mc">6 Go pour Minecraft</span>
            <span class="offers__capacity-os">2 Go système</span>
        </div>
        <p class="offers__capacity-text">8 Go de RAM physique : 6 Go pour les serveurs, 2 Go pour Linux Mint et MariaDB.</p>
    </div>

    <div class="offers__cards">
        <article class="offer-card offer-card--standard" aria-labelledby="offer-standard-title">
            <header class="offer-card__header">
                <span class="offer-card__icon" aria-hidden="true"><?= View::icon('rocket') ?></span>
                <p class="offer-card__badge">Découverte et survie</p>
                <h2 id="offer-standard-title" class="offer-card__title">Standard</h2>
                <p class="offer-card__price">
                    <strong class="offer-card__amount">Gratuit</strong>
                </p>
                <p class="offer-card__summary">Idéale pour jouer entre amis en Vanilla ou avec quelques plugins légers.</p>
            </header>

            <ul class="offer-card__features">
                <li class="offer-card__feature"><?= View::icon('check') ?> <span><strong>Jusqu'à 2 Go de RAM</strong> pour vos serveurs</span></li>
                <li class="offer-card__feature"><?= View::icon('check') ?> <span>3 serveurs et 2 sauvegardes par serveur</span></li>
                <li class="offer-card__feature"><?= View::icon('check') ?> <span>Console en direct et journaux complets</span></li>
                <li class="offer-card__feature"><?= View::icon('check') ?> <span>Installation en un clic des mods et plugins</span></li>
                <li class="offer-card__feature"><?= View::icon('check') ?> <span>Listes blanche, noire et opérateurs</span></li>
                <li class="offer-card__feature"><?= View::icon('check') ?> <span>Co-administrateurs</span></li>
            </ul>

            <footer class="offer-card__footer">
                <?php if ($isLogged && $userTier === Auth::ACCOUNT_STANDARD): ?>
                    <span class="offer-card__current"><?= View::icon('badge-check') ?> Votre offre actuelle</span>
                    <a href="/user/compte#section-serveurs" class="offer-card__btn offer-card__btn--secondary">Gérer mes serveurs</a>
                <?php elseif ($isLogged): ?>
                    <a href="/user/compte" class="offer-card__btn offer-card__btn--secondary">Accéder à mon compte</a>
                <?php else: ?>
                    <a href="/auth?mode=register" class="offer-card__btn offer-card__btn--secondary">Démarrer gratuitement</a>
                <?php endif; ?>
            </footer>
        </article>

        <article class="offer-card offer-card--premium" aria-labelledby="offer-premium-title">
            <p class="offer-card__ribbon">Recommandée pour les modpacks</p>
            <header class="offer-card__header">
                <span class="offer-card__icon" aria-hidden="true"><?= View::icon('gem') ?></span>
                <p class="offer-card__badge offer-card__badge--highlight">Modpacks et communautés</p>
                <h2 id="offer-premium-title" class="offer-card__title">Premium</h2>
                <p class="offer-card__price">
                    <strong class="offer-card__amount">Soutien de l'hôte</strong>
                    <span class="offer-card__period">sur demande</span>
                </p>
                <p class="offer-card__summary">Débloquez tout le plafond matériel pour vos gros modpacks et vos communautés.</p>
            </header>

            <ul class="offer-card__features">
                <li class="offer-card__feature"><?= View::icon('check') ?> <span><strong>Jusqu'à 6 Go de RAM</strong>, tout le plafond de l'hôte</span></li>
                <li class="offer-card__feature"><?= View::icon('check') ?> <span><strong>10 serveurs</strong> et 10 sauvegardes par serveur</span></li>
                <li class="offer-card__feature"><?= View::icon('check') ?> <span>Tous les modpacks : Forge, Fabric, NeoForge</span></li>
                <li class="offer-card__feature"><?= View::icon('check') ?> <span>Carte 3D interactive dans le navigateur</span></li>
                <li class="offer-card__feature"><?= View::icon('check') ?> <span>Statistiques détaillées (RAM, CPU, affluence)</span></li>
                <li class="offer-card__feature"><?= View::icon('check') ?> <span>Mise en avant dans l'annuaire public</span></li>
            </ul>

            <footer class="offer-card__footer">
                <?php if ($isLogged && $userTier === Auth::ACCOUNT_PREMIUM): ?>
                    <span class="offer-card__current"><?= View::icon('badge-check') ?> Votre offre actuelle</span>
                    <a href="/user/compte#section-serveurs" class="offer-card__btn offer-card__btn--primary">Gérer mes serveurs</a>
                <?php elseif ($isLogged): ?>
                    <a href="/user/compte?demande=premium#section-tickets" class="offer-card__btn offer-card__btn--primary">Demander le passage à Premium</a>
                <?php else: ?>
                    <a href="/auth?mode=register" class="offer-card__btn offer-card__btn--primary">Créer un compte</a>
                <?php endif; ?>
            </footer>
        </article>
    </div>

    <section class="offers__comparison" aria-labelledby="comparison-heading">
        <h2 id="comparison-heading" class="offers__comparison-title">Comparatif détaillé</h2>
        <div class="table-wrap">
            <table class="pricing-table">
                <caption class="visually-hidden">Caractéristiques comparées des offres Standard et Premium</caption>
                <thead class="pricing-table__head">
                    <tr class="pricing-table__row">
                        <th scope="col" class="pricing-table__cell pricing-table__cell--feature">Caractéristique</th>
                        <th scope="col" class="pricing-table__cell pricing-table__cell--standard">Standard</th>
                        <th scope="col" class="pricing-table__cell pricing-table__cell--premium">Premium</th>
                    </tr>
                </thead>
                <tbody class="pricing-table__body">
                    <?php foreach ($comparison as [$feature, $standard, $premium]): ?>
                        <tr class="pricing-table__row">
                            <th scope="row" class="pricing-table__cell"><?= Security::e($feature) ?></th>
                            <?php foreach ([$standard, $premium] as $value): ?>
                                <td class="pricing-table__cell">
                                    <?php if ($value === true): ?>
                                        <?= View::icon('check', 'pricing-table__yes', 'Inclus') ?>
                                    <?php elseif ($value === false): ?>
                                        <?= View::icon('minus', 'pricing-table__no', 'Non inclus') ?>
                                    <?php else: ?>
                                        <?= Security::e($value) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="offers__faq" aria-labelledby="faq-heading">
        <h2 id="faq-heading" class="offers__comparison-title">Questions fréquentes</h2>
        <details class="offers__question">
            <summary>Comment passer à l'offre Premium ?</summary>
            <p>Ouvrez un ticket depuis votre espace (rubrique Support, catégorie « Offre et RAM »). Un administrateur met à jour votre compte et votre quota de mémoire.</p>
        </details>
        <details class="offers__question">
            <summary>Que se passe-t-il si les 6 Go de l'hôte sont déjà utilisés ?</summary>
            <p>Le démarrage d'un serveur est refusé tant que d'autres serveurs n'ont pas libéré de mémoire : le plafond global est vérifié à chaque démarrage pour garder la machine stable.</p>
        </details>
        <details class="offers__question">
            <summary>Puis-je changer la mémoire allouée à mon serveur ?</summary>
            <p>Oui, depuis son tableau de bord, une fois le serveur arrêté et dans la limite de votre offre.</p>
        </details>
    </section>
</section>
