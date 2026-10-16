/**
 * Système de notifications orienté objet pour Boötes.
 * Respecte le principe Ouvert/Fermé (SOLID) via une classe de base abstraite.
 */

class BaseNotification {
    /**
     * @param {string} message Contenu textuel de la notification.
     * @param {number} duration Durée d'affichage en millisecondes (défaut : 4000ms).
     */
    constructor(message, duration = 4000) {
        if (new.target === BaseNotification) {
            throw new TypeError('BaseNotification est une classe abstraite et ne peut pas être instanciée directement.');
        }
        this.message = message;
        this.duration = duration;
    }

    /**
     * Retourne la classe CSS de variante (doit être surchargée).
     * @returns {string}
     */
    getVariantClass() {
        throw new Error('La méthode getVariantClass() doit être implémentée par la classe fille.');
    }

    /**
     * Retourne le symbole ou préfixe visuel du type de notification.
     * @returns {string}
     */
    getIcon() {
        throw new Error('La méthode getIcon() doit être implémentée par la classe fille.');
    }

    /**
     * Construit et retourne l'élément DOM de la bannière flottante.
     * @returns {HTMLElement}
     */
    createElement() {
        const toast = document.createElement('div');
        toast.className = `toast-notification ${this.getVariantClass()}`;
        toast.setAttribute('role', 'status');

        const iconSpan = document.createElement('span');
        iconSpan.className = 'mono';
        iconSpan.style.fontWeight = '700';
        iconSpan.textContent = this.getIcon();

        const textDiv = document.createElement('div');
        textDiv.style.flex = '1';
        textDiv.textContent = this.message;

        toast.appendChild(iconSpan);
        toast.appendChild(textDiv);

        return toast;
    }
}

class SuccessNotification extends BaseNotification {
    getVariantClass() {
        return 'toast-success';
    }

    getIcon() {
        return '[OK]';
    }
}

class ErrorNotification extends BaseNotification {
    getVariantClass() {
        return 'toast-error';
    }

    getIcon() {
        return '[!]';
    }
}

class InfoNotification extends BaseNotification {
    getVariantClass() {
        return 'toast-info';
    }

    getIcon() {
        return '[i]';
    }
}

class NotificationManager {
    /**
     * @param {string} containerId ID du conteneur HTML des notifications.
     */
    constructor(containerId = 'bootes-notifications') {
        this.container = document.getElementById(containerId);
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = containerId;
            this.container.className = 'notification-container';
            document.body.appendChild(this.container);
        }
    }

    /**
     * Affiche une instance de BaseNotification et programme sa disparition au bout de 4 secondes.
     * @param {BaseNotification} notificationInstance
     */
    dispatch(notificationInstance) {
        if (!(notificationInstance instanceof BaseNotification)) {
            throw new TypeError('L\'objet fourni doit hériter de BaseNotification.');
        }

        const element = notificationInstance.createElement();
        this.container.appendChild(element);

        window.setTimeout(() => {
            element.classList.add('toast-hiding');
            element.addEventListener('transitionend', () => {
                if (element.parentNode) {
                    element.parentNode.removeChild(element);
                }
            }, { once: true });
        }, notificationInstance.duration);
    }

    success(message, duration = 4000) {
        this.dispatch(new SuccessNotification(message, duration));
    }

    error(message, duration = 4000) {
        this.dispatch(new ErrorNotification(message, duration));
    }

    info(message, duration = 4000) {
        this.dispatch(new InfoNotification(message, duration));
    }
}

// Initialisation globale et lecture automatique des messages flash PHP éventuels
document.addEventListener('DOMContentLoaded', () => {
    window.BootesNotify = new NotificationManager();

    if (Array.isArray(window.BOOTES_FLASH_QUEUE)) {
        window.BOOTES_FLASH_QUEUE.forEach((item) => {
            if (item && item.message) {
                const type = item.type || 'info';
                if (type === 'success') window.BootesNotify.success(item.message);
                else if (type === 'error') window.BootesNotify.error(item.message);
                else window.BootesNotify.info(item.message);
            }
        });
    }
});