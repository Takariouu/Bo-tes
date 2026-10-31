<?php

declare(strict_types=1);

use Bootes\Src\Auth;
use Bootes\Src\Security;

$isLogged  = Auth::isLogged();
$userTier  = $isLogged ? (string) ($_SESSION['user']['type_compte'] ?? Auth::ACCOUNT_STANDARD) : null;
?>
<section class="offers" aria-labelledby="offers-title">
    <header class="offers__header">
        <p class="offers__eyebrow">Allocation transparente des ressources matérielles</p>
        <h1 id="offers-title" class="offers__title">Offres d'hébergement Boötes</h1>
        <p class="offers__lead">
            Notre machine hôte sous Linux Mint dispose de 8 Go de mémoire vive physique, dont 6 Go sont strictement réservés aux instances Minecraft. Choisissez le palier adapté à vos besoins.
        </p>
    </header>

    <div class="offers__cards">
        <article class="offer-card offer-card--standard" aria-labelledby="offer-standard-title">
            <header class="offer-card__header">
                <span class="offer-card__badge">Découverte &amp; Survie</span>
                <h2 id="offer-standard-title" class="offer-card__title">Offre Gratuite (Standard)</h2>
                <p class="offer-card__price">
                    <strong class="offer-card__amount">0 €</strong>
                    <span class="offer-card__period">/ mois</span>
                </p>
                <p class="offer-card__summary">
                    Idéale pour jouer entre amis en Vanilla ou avec quelques plugins légers.
                </p>
            </header>

            <div class="offer-card__body">
                <ul class="offer-card__features">
                    <li class="offer-card__feature">
                        <strong>Jusqu'à 2 Go de RAM</strong> (2048 Mo max allouables)
                    </li>
                    <li class="offer-card__feature">
                        Console temps réel et gestionnaire de logs complet
                    </li>
                    <li class="offer-card__feature">
                        Installation en 1 clic de Mods, Plugins et versions Vanilla
                    </li>
                    <li class="offer-card__feature">
                        Gestion des joueurs (Whitelist, Opérateurs, Bannissements)
                    </li>
                    <li class="offer-card__feature">
                        Partage d'accès avec des co-administrateurs
                    </li>
                    <li class="offer-card__feature">
                        2 emplacements de sauvegarde (Backups manuels)
                    </li>
                </ul>
            </div>

            <footer class="offer-card__footer">
                <?php if ($isLogged && $userTier === Auth::ACCOUNT_STANDARD): ?>
                    <span class="offer-card__current">Votre offre actuelle</span>
                    <a href="/user/compte" class="offer-card__btn offer-card__btn--secondary">Gérer mon serveur</a>
                <?php elseif ($isLogged): ?>
                    <a href="/user/compte" class="offer-card__btn offer-card__btn--secondary">Accéder à mon compte</a>
                <?php else: ?>
                    <a href="/auth?mode=register" class="offer-card__btn offer-card__btn--primary">Démarrer gratuitement</a>
                <?php endif; ?>
            </footer>
        </article>

        <article class="offer-card offer-card--premium" aria-labelledby="offer-premium-title">
            <header class="offer-card__header">
                <span class="offer-card__badge offer-card__badge--highlight">Modpacks Lourds &amp; Communautés</span>
                <h2 id="offer-premium-title" class="offer-card__title">Offre Premium</h2>
                <p class="offer-card__price">
                    <strong class="offer-card__amount">Soutien Hôte</strong>
                    <span class="offer-card__period">/ Sur demande ou palier</span>
                </p>
                <p class="offer-card__summary">
                    Débloquez le plafond matériel de la machine hôte pour vos gros modpacks et l'exploration 3D.
                </p>
            </header>

            <div class="offer-card__body">
                <ul class="offer-card__features">
                    <li class="offer-card__feature">
                        <strong>Jusqu'à 6 Go de RAM</strong> (plafond matériel de 6144 Mo sur l'hôte)
                    </li>
                    <li class="offer-card__feature">
                        <strong>Visualisation Map 3D interactive</strong> en temps réel dans le navigateur
                    </li>
                    <li class="offer-card__feature">
                        <strong>Backups étendus</strong> (jusqu'à 10 archives restaurables et téléchargeables)
                    </li>
                    <li class="offer-card__feature">
                        Exécution fluide des Modpacks complexes (Forge, Fabric, NeoForge)
                    </li>
                    <li class="offer-card__feature">
                        Priorité d'allocation CPU sur le serveur Linux Mint
                    </li>
                    <li class="offer-card__feature">
                        Statistiques détaillées de performances (TPS, RAM, affluence)
                    </li>
                </ul>
            </div>

            <footer class="offer-card__footer">
                <?php if ($isLogged && $userTier === Auth::ACCOUNT_PREMIUM): ?>
                    <span class="offer-card__current">Votre offre actuelle</span>
                    <a href="/user/compte" class="offer-card__btn offer-card__btn--primary">Gérer mes instances Premium</a>
                <?php elseif ($isLogged): ?>
                    <a href="/user/compte?upgrade=premium" class="offer-card__btn offer-card__btn--primary">Passer au palier Premium</a>
                <?php else: ?>
                    <a href="/auth?mode=register" class="offer-card__btn offer-card__btn--primary">Créer un compte et activer Premium</a>
                <?php endif; ?>
            </footer>
        </article>
    </div>

    <section class="offers__comparison" aria-labelledby="comparison-heading">
        <h2 id="comparison-heading" class="offers__comparison-title">Comparatif technique détaillé</h2>
        <div class="offers__table-wrapper">
            <table class="pricing-table">
                <caption class="pricing-table__caption">
                    Spécifications comparées entre le compte Standard et le compte Premium sur Boötes
                </caption>
                <thead class="pricing-table__head">
                    <tr class="pricing-table__row">
                        <th scope="col" class="pricing-table__cell pricing-table__cell--feature">Caractéristique</th>
                        <th scope="col" class="pricing-table__cell pricing-table__cell--standard">Standard (Gratuit)</th>
                        <th scope="col" class="pricing-table__cell pricing-table__cell--premium">Premium</th>
                    </tr>
                </thead>
                <tbody class="pricing-table__body">
                    <tr class="pricing-table__row">
                        <th scope="row" class="pricing-table__cell">Mémoire RAM allouable</th>
                        <td class="pricing-table__cell">512 Mo à 2048 Mo (2 Go max)</td>
                        <td class="pricing-table__cell">Jusqu'à 6144 Mo (6 Go max selon disponibilité hôte)</td>
                    </tr>
                    <tr class="pricing-table__row">
                        <th scope="row" class="pricing-table__cell">Rendu Map 3D Web</th>
                        <td class="pricing-table__cell">Non inclus</td>
                        <td class="pricing-table__cell">Inclus (rendu isométrique et 3D temps réel)</td>
                    </tr>
                    <tr class="pricing-table__row">
                        <th scope="row" class="pricing-table__cell">Sauvegardes (Backups)</th>
                        <td class="pricing-table__cell">2 sauvegardes rotatives</td>
                        <td class="pricing-table__cell">10 sauvegardes étendues + export archive</td>
                    </tr>
                    <tr class="pricing-table__row">
                        <th scope="row" class="pricing-table__cell">Support des Modpacks</th>
                        <td class="pricing-table__cell">Modpacks légers uniquement (&le; 2 Go)</td>
                        <td class="pricing-table__cell">Tous les Modpacks du catalogue</td>
                    </tr>
                    <tr class="pricing-table__row">
                        <th scope="row" class="pricing-table__cell">Co-administration (`ADMINISTRER_SERVEUR`)</th>
                        <td class="pricing-table__cell">Jusqu'à 3 co-admins</td>
                        <td class="pricing-table__cell">Illimité</td>
                    </tr>
                    <tr class="pricing-table__row">
                        <th scope="row" class="pricing-table__cell">Visibilité annuaire public</th>
                        <td class="pricing-table__cell">Oui</td>
                        <td class="pricing-table__cell">Oui (mise en avant prioritaire)</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</section>