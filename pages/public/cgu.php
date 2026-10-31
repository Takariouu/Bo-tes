<?php

declare(strict_types=1);

use Bootes\Src\Security;

$lastUpdateDate = '2026-03-01';
?>
<article class="legal-doc" aria-labelledby="legal-main-title">
    <header class="legal-doc__header">
        <p class="legal-doc__eyebrow">Cadre contractuel, Mentions Légales &amp; Conformité RGPD</p>
        <h1 id="legal-main-title" class="legal-doc__title">
            Conditions Générales d'Utilisation et Politique de Confidentialité de Boötes
        </h1>
        <p class="legal-doc__meta">
            Dernière mise à jour : <time datetime="<?= Security::e($lastUpdateDate) ?>">1er mars 2026</time>
        </p>
    </header>

    <nav class="legal-doc__toc" aria-label="Sommaire des conditions générales">
        <h2 class="legal-doc__toc-title">Sommaire</h2>
        <ol class="legal-doc__toc-list">
            <li class="legal-doc__toc-item"><a href="#mentions-legales" class="legal-doc__toc-link">1. Mentions légales de l'hébergeur</a></li>
            <li class="legal-doc__toc-item"><a href="#objet-service" class="legal-doc__toc-link">2. Objet du service et limites matérielles</a></li>
            <li class="legal-doc__toc-item"><a href="#obligations-utilisateur" class="legal-doc__toc-link">3. Obligations des utilisateurs et des créateurs</a></li>
            <li class="legal-doc__toc-item"><a href="#politique-rgpd" class="legal-doc__toc-link">4. Protection des données personnelles (RGPD) et gestion des logs IP</a></li>
            <li class="legal-doc__toc-item"><a href="#droit-oubli" class="legal-doc__toc-link">5. Exercice du droit d'accès, de rectification et droit à l'oubli</a></li>
        </ol>
    </nav>

    <section id="mentions-legales" class="legal-doc__section" aria-labelledby="heading-mentions">
        <h2 id="heading-mentions" class="legal-doc__section-title">1. Mentions légales de l'hébergeur</h2>
        <p class="legal-doc__paragraph">
            La plateforme web <strong>Boötes</strong> est un projet d'hébergement communautaire auto-hébergé opérant sur une infrastructure dédiée sous système d'exploitation <strong>Linux Mint</strong> et moteur de base de données relationnelle <strong>MariaDB</strong> (<code>HEBERGEUR_MC</code>).
        </p>
        <dl class="legal-doc__definitions">
            <div class="legal-doc__def-row">
                <dt class="legal-doc__def-term">Dénomination du service</dt>
                <dd class="legal-doc__def-desc">Boötes — Panel Web d'Hébergement Minecraft</dd>
            </div>
            <div class="legal-doc__def-row">
                <dt class="legal-doc__def-term">Nature de l'infrastructure</dt>
                <dd class="legal-doc__def-desc">Serveur physique auto-hébergé (8 Go de RAM totale, 6 Go réservés aux processus JVM Minecraft)</dd>
            </div>
            <div class="legal-doc__def-row">
                <dt class="legal-doc__def-term">Affiliation</dt>
                <dd class="legal-doc__def-desc">Boötes n'est pas un service officiel de Minecraft et n'est ni approuvé par, ni associé à Mojang Studios ou Microsoft.</dd>
            </div>
        </dl>
    </section>

    <section id="objet-service" class="legal-doc__section" aria-labelledby="heading-objet">
        <h2 id="heading-objet" class="legal-doc__section-title">2. Objet du service et limites matérielles</h2>
        <p class="legal-doc__paragraph">
            Boötes met à disposition des utilisateurs inscrits une interface permettant de créer, configurer et administrer des serveurs de jeu Minecraft, ainsi qu'un catalogue communautaire de Mods, Plugins et Modpacks.
        </p>
        <ul class="legal-doc__list">
            <li class="legal-doc__list-item">
                <strong>Plafond mémoire global :</strong> L'hôte Linux Mint réserve un plafond strict de 6 Go (6144 Mo) pour l'ensemble des serveurs Minecraft simultanément actifs afin de garantir la stabilité du système d'exploitation et du serveur MariaDB.
            </li>
            <li class="legal-doc__list-item">
                <strong>Comptes Standards :</strong> Limitée à 2 Go de RAM maximum par instance.
            </li>
            <li class="legal-doc__list-item">
                <strong>Comptes Premium :</strong> Allocation étendue pouvant dépasser 2 Go dans la limite des 6 Go disponibles sur l'hôte, avec accès à la visualisation Map 3D et aux sauvegardes étendues.
            </li>
        </ul>
    </section>

    <section id="obligations-utilisateur" class="legal-doc__section" aria-labelledby="heading-obligations">
        <h2 id="heading-obligations" class="legal-doc__section-title">3. Obligations des utilisateurs et des créateurs</h2>
        <p class="legal-doc__paragraph">
            En cochant la case d'acceptation lors de l'inscription, chaque utilisateur s'engage formellement à :
        </p>
        <ol class="legal-doc__list legal-doc__list--ordered">
            <li class="legal-doc__list-item">
                Respecter le contrat de licence utilisateur final (EULA) de Minecraft édité par Mojang Studios.
            </li>
            <li class="legal-doc__list-item">
                Ne téléverser aucune archive (Mod, Plugin, Modpack) contenant du code malveillant, porte dérobée (backdoor), mineur de cryptomonnaie ou script visant à contourner l'isolation des ports et processus du serveur hôte.
            </li>
            <li class="legal-doc__list-item">
                Ne pas concevoir volontairement de systèmes provoquant une saturation du processeur ou de la mémoire vive (« lag machines ») au détriment des autres instances hébergées sur la machine.
            </li>
        </ol>
        <p class="legal-doc__paragraph">
            Tout manquement constaté par un Administrateur ou Super-Administrateur via le module de signalement entraînera la suspension immédiate du serveur ou du compte concerné.
        </p>
    </section>

    <section id="politique-rgpd" class="legal-doc__section" aria-labelledby="heading-rgpd">
        <h2 id="heading-rgpd" class="legal-doc__section-title">4. Protection des données personnelles (RGPD) et gestion des logs IP</h2>
        <p class="legal-doc__paragraph">
            Conformément au Règlement Général sur la Protection des Données (RGPD - UE 2016/679) et à la loi Informatique et Libertés, Boötes applique le principe de minimisation des données :
        </p>
        <ul class="legal-doc__list">
            <li class="legal-doc__list-item">
                <strong>Données d'authentification :</strong> Seuls votre pseudonyme, votre adresse e-mail et l'empreinte cryptographique de votre mot de passe (hachée de manière irréversible via <code>PASSWORD_ARGON2ID</code>) sont stockés dans la base <code>HEBERGEUR_MC</code>.
            </li>
            <li class="legal-doc__list-item">
                <strong>Journalisation des adresses IP (Logs système et serveurs) :</strong> Conformément aux obligations légales des hébergeurs et pour assurer la sécurité de l'infrastructure (prévention des attaques par force brute, audit Super-Admin et journaux de connexion Minecraft), les adresses IP et horodatages de connexion sont conservés pour une durée strictement limitée à 12 mois glissants avant purge automatique.
            </li>
            <li class="legal-doc__list-item">
                <strong>Cookies :</strong> Boötes utilise exclusivement un cookie technique de session sécurisé (<code>BOOTES_SESSID</code> en mode <code>HttpOnly</code> et <code>SameSite=Lax</code>) indispensable au maintien de votre connexion et à la protection contre les attaques CSRF. Aucun traceur publicitaire ou tiers n'est exploité.
            </li>
        </ul>
    </section>

    <section id="droit-oubli" class="legal-doc__section" aria-labelledby="heading-oubli">
        <h2 id="heading-oubli" class="legal-doc__section-title">5. Exercice du droit d'accès, de rectification et droit à l'oubli</h2>
        <p class="legal-doc__paragraph">
            Vous disposez à tout moment d'un droit d'accès, de rectification, de portabilité et d'effacement (« droit à l'oubli », article 17 du RGPD) sur l'ensemble de vos données personnelles :
        </p>
        <p class="legal-doc__paragraph">
            Depuis votre espace <a href="/user/compte" class="legal-doc__link">Mon Compte</a>, vous pouvez modifier vos informations personnelles ou demander la suppression intégrale et définitive de votre compte utilisateur, ce qui entraîne la destruction en cascade de vos instances de serveurs, sauvegardes (<code>/backups</code>) et données associées.
        </p>
    </section>
</article>