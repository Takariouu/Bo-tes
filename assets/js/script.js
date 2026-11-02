/*
 * Retours visuels essentiels, compatibles avec la politique CSP (aucun script en ligne) :
 * - confirmation des actions destructrices (attribut data-confirm sur le bouton d'envoi) ;
 * - état de chargement du bouton pendant l'envoi pour éviter les doubles soumissions.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var submitter = event.submitter || form.querySelector('[type="submit"]');
        var message = submitter && submitter.getAttribute('data-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }

        if (!submitter || form.method.toLowerCase() !== 'post') {
            return;
        }

        // Désactivation différée : le formulaire part avant que le bouton ne soit grisé.
        window.setTimeout(function () {
            submitter.disabled = true;
            submitter.setAttribute('aria-busy', 'true');
        }, 0);

        // Les téléchargements ne changent pas de page : le bouton est réactivé après quelques secondes.
        window.setTimeout(function () {
            submitter.disabled = false;
            submitter.removeAttribute('aria-busy');
        }, 8000);
    });
}());
