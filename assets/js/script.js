/*
 * Comportements de l'interface, compatibles avec la politique CSP (aucun script en ligne) :
 * - bascule du thème sombre / clair ;
 * - menus déroulants (compte, navigation mobile) : un seul ouvert à la fois, fermeture au clic
 *   extérieur et avec Échap ;
 * - messages éphémères (toasts) et copie de l'adresse d'un serveur ;
 * - fenêtre de confirmation des actions destructrices (attribut data-confirm sur le bouton d'envoi) ;
 * - état de chargement du bouton pendant l'envoi pour éviter les doubles soumissions.
 * Sans JavaScript, tout reste utilisable : menus natifs <details>, envoi direct des formulaires.
 */
(function () {
    'use strict';

    var spriteUse = document.querySelector('use[href*="icons.svg"]');
    var spriteUrl = spriteUse ? spriteUse.getAttribute('href').split('#')[0] : '';

    function icon(name, className) {
        var svgNs = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(svgNs, 'svg');
        var use = document.createElementNS(svgNs, 'use');

        svg.setAttribute('class', 'icon' + (className ? ' ' + className : ''));
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        use.setAttribute('href', spriteUrl + '#' + name);
        svg.appendChild(use);

        return svg;
    }

    // ---------- Thème ----------
    // Le thème choisi est mémorisé dans un cookie lu par le serveur : la page suivante
    // est directement rendue dans le bon thème, sans flash au chargement.
    var themeToggle = document.querySelector('[data-theme-toggle]');
    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            var root = document.documentElement;
            var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            var label = next === 'light' ? 'Passer au thème sombre' : 'Passer au thème clair';
            var themeColor = document.querySelector('meta[name="theme-color"]');

            root.setAttribute('data-theme', next);
            themeToggle.setAttribute('aria-label', label);
            themeToggle.setAttribute('title', label);
            if (themeColor) {
                themeColor.setAttribute('content', next === 'light' ? '#eef2f8' : '#060a14');
            }

            document.cookie = 'bootes_theme=' + next + '; Path=/; Max-Age=31536000; SameSite=Lax'
                + (window.location.protocol === 'https:' ? '; Secure' : '');
        });
    }

    // ---------- Menus déroulants ----------
    var menus = Array.prototype.slice.call(document.querySelectorAll('details[data-menu]'));

    menus.forEach(function (menu) {
        menu.addEventListener('toggle', function () {
            if (menu.open) {
                menus.forEach(function (other) {
                    if (other !== menu) {
                        other.open = false;
                    }
                });
            }
        });

        // Un lien vers une ancre de la page courante ne recharge rien : on referme le menu.
        menu.addEventListener('click', function (event) {
            if (event.target.closest('a')) {
                menu.open = false;
            }
        });
    });

    document.addEventListener('click', function (event) {
        menus.forEach(function (menu) {
            if (menu.open && !menu.contains(event.target)) {
                menu.open = false;
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        menus.forEach(function (menu) {
            if (menu.open) {
                menu.open = false;
                menu.querySelector('summary').focus();
            }
        });
    });

    // ---------- Messages éphémères ----------
    function toastRegion() {
        var region = document.querySelector('.toast-region');
        if (!region) {
            region = document.createElement('div');
            region.className = 'toast-region';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            document.body.appendChild(region);
        }

        return region;
    }

    function showToast(message, tone) {
        var icons = { success: 'circle-check', info: 'info', error: 'circle-alert' };
        var toast = document.createElement('div');
        var text = document.createElement('p');
        var close = document.createElement('button');

        tone = icons[tone] ? tone : 'success';
        toast.className = 'toast toast--' + tone;
        toast.setAttribute('data-toast', '');
        text.className = 'toast__message';
        text.textContent = message;
        close.type = 'button';
        close.className = 'toast__close';
        close.setAttribute('data-toast-close', '');
        close.setAttribute('aria-label', 'Fermer le message');
        close.appendChild(icon('x'));

        toast.appendChild(icon(icons[tone], 'toast__icon'));
        toast.appendChild(text);
        toast.appendChild(close);
        toastRegion().appendChild(toast);
    }

    document.addEventListener('click', function (event) {
        var closeButton = event.target.closest('[data-toast-close]');
        if (closeButton) {
            closeButton.closest('[data-toast]').remove();
        }
    });

    document.addEventListener('animationend', function (event) {
        if (event.animationName === 'bootes-toast-out' && event.target.matches('[data-toast]')) {
            event.target.remove();
        }
    });

    // ---------- Copie dans le presse-papiers (adresse d'un serveur) ----------
    // Méthode de secours pour les navigateurs qui refusent l'API Clipboard (contexte non sécurisé, permissions).
    function legacyCopy(text) {
        var field = document.createElement('textarea');
        var copied = false;

        field.value = text;
        field.setAttribute('readonly', '');
        field.className = 'visually-hidden';
        document.body.appendChild(field);
        field.select();
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }
        field.remove();

        return copied;
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');
        var text = button ? button.getAttribute('data-copy') : '';

        if (!button) {
            return;
        }

        function done(copied) {
            if (!copied) {
                showToast('Copie impossible : sélectionnez l\'adresse et copiez-la manuellement.', 'error');
                return;
            }
            button.setAttribute('data-copied', '');
            showToast('Adresse copiée : ' + text);
            window.setTimeout(function () {
                button.removeAttribute('data-copied');
            }, 2000);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                done(true);
            }, function () {
                done(legacyCopy(text));
            });
        } else {
            done(legacyCopy(text));
        }
    });

    // ---------- Fenêtre de confirmation ----------
    var confirmDialog = null;

    function buildConfirmDialog() {
        var dialog = document.createElement('dialog');
        var form = document.createElement('form');
        var badge = document.createElement('div');
        var title = document.createElement('h2');
        var message = document.createElement('p');
        var actions = document.createElement('div');
        var cancel = document.createElement('button');
        var confirm = document.createElement('button');

        dialog.className = 'confirm-dialog';
        dialog.setAttribute('aria-labelledby', 'confirm-dialog-title');
        dialog.setAttribute('aria-describedby', 'confirm-dialog-message');
        form.method = 'dialog';
        form.className = 'confirm-dialog__form';
        badge.className = 'confirm-dialog__icon';
        badge.appendChild(icon('triangle-alert'));
        title.id = 'confirm-dialog-title';
        title.className = 'confirm-dialog__title';
        title.textContent = 'Confirmer l\'action';
        message.id = 'confirm-dialog-message';
        message.className = 'confirm-dialog__message';
        actions.className = 'confirm-dialog__actions';
        cancel.value = 'cancel';
        cancel.className = 'btn btn--ghost';
        cancel.textContent = 'Annuler';
        confirm.value = 'confirm';
        confirm.className = 'btn btn--danger';

        actions.appendChild(cancel);
        actions.appendChild(confirm);
        form.appendChild(badge);
        form.appendChild(title);
        form.appendChild(message);
        form.appendChild(actions);
        dialog.appendChild(form);

        // Un clic sur le fond assombri annule
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close('cancel');
            }
        });

        document.body.appendChild(dialog);

        return dialog;
    }

    function askConfirmation(message, actionLabel, onConfirm) {
        if (typeof HTMLDialogElement !== 'function') {
            if (window.confirm(message)) {
                onConfirm();
            }
            return;
        }

        confirmDialog = confirmDialog || buildConfirmDialog();
        confirmDialog.querySelector('.confirm-dialog__message').textContent = message;
        confirmDialog.querySelector('[value="confirm"]').textContent = actionLabel;
        confirmDialog.returnValue = '';
        confirmDialog.onclose = function () {
            if (confirmDialog.returnValue === 'confirm') {
                onConfirm();
            }
        };
        confirmDialog.showModal();
        confirmDialog.querySelector('[value="cancel"]').focus();
    }

    // ---------- Mots de passe : afficher / masquer, robustesse ----------
    Array.prototype.forEach.call(document.querySelectorAll('[data-password-toggle]'), function (button) {
        var input = button.parentNode.querySelector('input');
        if (!input) {
            return;
        }
        button.hidden = false;
        button.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            button.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
            input.focus();
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-password-strength]'), function (meter) {
        var input = document.getElementById(meter.getAttribute('data-password-strength'));
        var fill = meter.querySelector('.password-strength__fill');
        var label = meter.querySelector('.password-strength__label');
        var labels = ['10 caractères minimum', 'Faible', 'Correct', 'Solide', 'Excellent'];

        if (!input || !fill || !label) {
            return;
        }

        // Indication visuelle seulement : la règle appliquée par le serveur reste « 10 caractères minimum ».
        input.addEventListener('input', function () {
            var value = input.value;
            var level = 0;

            if (value.length >= 10) {
                level = 1;
                if (/[a-z]/.test(value) && /[A-Z]/.test(value)) {
                    level++;
                }
                if (/\d/.test(value)) {
                    level++;
                }
                if (/[^A-Za-z0-9]/.test(value) || value.length >= 16) {
                    level++;
                }
                level = Math.min(level, 4);
            }

            fill.setAttribute('data-level', String(level));
            label.textContent = value.length > 0 && value.length < 10
                ? 'Trop court (' + value.length + ' / 10 caractères)'
                : labels[level];
        });
    });

    // ---------- Console et journaux : affichage des lignes les plus récentes ----------
    Array.prototype.forEach.call(document.querySelectorAll('.terminal__output, .log-viewer__pre'), function (output) {
        output.scrollTop = output.scrollHeight;
    });

    // ---------- Dépôt de fichier par glisser-déposer ([data-dropzone]) ----------
    Array.prototype.forEach.call(document.querySelectorAll('[data-dropzone]'), function (zone) {
        var input = zone.querySelector('input[type="file"]');
        var label = zone.querySelector('[data-dropzone-file]');

        if (!input || !label) {
            return;
        }

        ['dragenter', 'dragover'].forEach(function (type) {
            zone.addEventListener(type, function () {
                zone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            zone.addEventListener(type, function () {
                zone.classList.remove('is-dragover');
            });
        });

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            label.textContent = file
                ? file.name + ' (' + (file.size / 1048576).toFixed(1).replace('.', ',') + ' Mo)'
                : 'Aucun fichier sélectionné';
            zone.classList.toggle('has-file', Boolean(file));
        });
    });

    // ---------- Valeur affichée d'un curseur ([data-range-output]) ----------
    Array.prototype.forEach.call(document.querySelectorAll('[data-range-output]'), function (output) {
        var input = document.getElementById(output.getAttribute('data-range-output'));
        if (input) {
            input.addEventListener('input', function () {
                output.textContent = input.value;
            });
        }
    });

    // ---------- Onglets ([data-tabs]) ----------
    // Sans JavaScript, tous les panneaux restent affichés les uns sous les autres et les onglets
    // servent d'ancres. Avec, un seul panneau est visible ; l'ancre de l'adresse choisit le panneau.
    Array.prototype.forEach.call(document.querySelectorAll('[data-tabs]'), function (container) {
        var tabs = Array.prototype.slice.call(container.querySelectorAll('[data-tab]'));
        var panels = tabs.map(function (tab) {
            return document.getElementById((tab.getAttribute('href') || '').slice(1));
        });
        var list = container.querySelector('[data-tablist]');

        if (!tabs.length || panels.indexOf(null) !== -1) {
            return;
        }

        function activate(index, focus) {
            tabs.forEach(function (tab, i) {
                var selected = i === index;
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.setAttribute('tabindex', selected ? '0' : '-1');
                panels[i].hidden = !selected;
            });
            if (focus) {
                tabs[index].focus();
            }
        }

        function indexFromHash() {
            for (var i = 0; i < panels.length; i++) {
                if (window.location.hash === '#' + panels[i].id) {
                    return i;
                }
            }
            return -1;
        }

        if (list) {
            list.setAttribute('role', 'tablist');
        }

        tabs.forEach(function (tab, i) {
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panels[i].id);
            tab.id = tab.id || panels[i].id + '-onglet';
            panels[i].setAttribute('role', 'tabpanel');
            panels[i].setAttribute('aria-labelledby', tab.id);
            panels[i].setAttribute('tabindex', '0');

            tab.addEventListener('click', function (event) {
                event.preventDefault();
                activate(i, false);
                if (window.history.replaceState) {
                    window.history.replaceState(null, '', '#' + panels[i].id);
                }
            });

            tab.addEventListener('keydown', function (event) {
                var target = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[event.key];
                if (target === undefined) {
                    return;
                }
                event.preventDefault();
                target = (target + tabs.length) % tabs.length;
                activate(target, true);
                if (window.history.replaceState) {
                    window.history.replaceState(null, '', '#' + panels[target].id);
                }
            });
        });

        // Un lien vers l'un des panneaux (menu, colonne latérale) ouvre l'onglet correspondant.
        window.addEventListener('hashchange', function () {
            var index = indexFromHash();
            if (index !== -1) {
                activate(index, false);
            }
        });

        activate(Math.max(0, indexFromHash()), false);
    });

    // ---------- Envoi automatique d'un choix (tri du catalogue...) ----------
    // Sans JavaScript, le bouton « Appliquer » reste affiché et fait le même travail.
    Array.prototype.forEach.call(document.querySelectorAll('[data-autosubmit-fallback]'), function (button) {
        button.hidden = true;
    });

    document.addEventListener('change', function (event) {
        var field = event.target;
        if (!field.matches || !field.matches('[data-autosubmit]') || !field.form) {
            return;
        }
        if (typeof field.form.requestSubmit === 'function') {
            field.form.requestSubmit();
        } else {
            field.form.submit();
        }
    });

    // ---------- Envoi des formulaires ----------
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method === 'dialog') {
            return;
        }

        var submitter = event.submitter || form.querySelector('[type="submit"]');
        var message = submitter && submitter.getAttribute('data-confirm');

        if (message && form.getAttribute('data-confirmed') !== '1') {
            var label = submitter.textContent.replace(/\s+/g, ' ').trim();

            event.preventDefault();
            askConfirmation(message, label && label.length <= 32 ? label : 'Confirmer', function () {
                form.setAttribute('data-confirmed', '1');
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitter);
                } else {
                    submitter.click();
                }
            });
            return;
        }
        form.removeAttribute('data-confirmed');

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
