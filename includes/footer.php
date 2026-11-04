<?php

declare(strict_types=1);

use Bootes\Src\Security;

$currentYear = (int) date('Y');
?>
    </div><!-- Fin de .layout -->

    <footer class="footer" role="contentinfo">
        <div class="footer__container">
            <div class="footer__info">
                <a href="/home" class="footer__brand-link">
                    <span class="brand-logo brand-logo--horizontal" aria-hidden="true"></span>
                    <span class="visually-hidden">Boötes — accueil</span>
                </a>
                <p class="footer__tagline">Transformer le vide en lieu de vie</p>
                <p class="footer__brand">
                    <strong>Boötes</strong> — Infrastructure auto-hébergée de serveurs Minecraft sous Linux Mint.
                </p>
                <p class="footer__specs">
                    Capacité matérielle : 6 Go de RAM dédiés aux instances (Plafond Standard : 2 Go / Premium : jusqu'à 6 Go).
                </p>
            </div>

            <nav class="footer__nav" aria-label="Liens utiles de pied de page">
                <ul class="footer__list">
                    <li class="footer__item">
                        <a href="/home" class="footer__link">Accueil</a>
                    </li>
                    <li class="footer__item">
                        <a href="/catalogue" class="footer__link">Catalogue</a>
                    </li>
                    <li class="footer__item">
                        <a href="/serveurs" class="footer__link">Annuaire des serveurs</a>
                    </li>
                    <li class="footer__item">
                        <a href="/offres" class="footer__link">Offres</a>
                    </li>
                    <li class="footer__item">
                        <a href="/cgu" class="footer__link">CGU &amp; Mentions</a>
                    </li>
                </ul>
            </nav>

            <div class="footer__copyright">
                <small class="footer__copyright-text">
                    &copy; <?= Security::e((string) $currentYear) ?> Boötes Project. Tous droits réservés.
                </small>
            </div>
        </div>
    </footer>
</body>
</html>