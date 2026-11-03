-- Phase 4.2 : serveurs favoris. Idempotent : peut être rejoué sans risque.
SET NAMES utf8mb4;

-- Serveurs favoris d'un joueur (supprimés avec le compte ou le serveur)
CREATE TABLE IF NOT EXISTS FAVORI_SERVEUR (
    id_utilisateur  INT UNSIGNED NOT NULL,
    id_serveur      INT UNSIGNED NOT NULL,
    date_ajout      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_utilisateur, id_serveur),
    KEY i_favori_serveur (id_serveur),
    CONSTRAINT fk_favori_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES UTILISATEUR (id_utilisateur) ON DELETE CASCADE,
    CONSTRAINT fk_favori_serveur FOREIGN KEY (id_serveur) REFERENCES SERVEUR (id_serveur) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
