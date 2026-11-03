-- =============================================================================
--  Schéma MariaDB de la base HEBERGEUR_MC, tel qu'utilisé par le code (src/, pages/).
--  Transposition du schéma de test SQLite (tests/support/schema.sql) : mêmes tables,
--  mêmes colonnes et mêmes clés étrangères, types adaptés à MariaDB 10.6+.
--
--  Import :  mariadb HEBERGEUR_MC < dev/schema.sql
--  (dev/start.sh crée la base et l'importe automatiquement en local)
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS FAVORI_SERVEUR, LOG_SYSTEME, MESSAGE_TICKET, TICKET_SUPPORT, RELATION_UTILISATEUR, SIGNALEMENT,
    INSTALLER_RESSOURCE, AVIS_RESSOURCE, FICHIER_RESSOURCE, RESSOURCE, AVIS_SERVEUR, CONNEXION_JOUEUR,
    CONFIGURATION_MAP, HISTORIQUE_RESSOURCE, BACKUP, ETRE_LISTER, ADMINISTRER_SERVEUR, SERVEUR, UTILISATEUR;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- Comptes : rôle (VISITEUR, UTILISATEUR, CREATEUR, ADMIN, SUPER_ADMIN),
-- offre (STANDARD, PREMIUM) et quota de RAM en Mo.
-- -----------------------------------------------------------------------------
CREATE TABLE UTILISATEUR (
    id_utilisateur     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    pseudo             VARCHAR(32)   NOT NULL,
    email              VARCHAR(190)  NOT NULL,
    mot_de_passe       VARCHAR(255)  NOT NULL,
    role               VARCHAR(20)   NOT NULL DEFAULT 'UTILISATEUR',
    type_compte        VARCHAR(20)   NOT NULL DEFAULT 'STANDARD',
    max_ram_allouable  INT UNSIGNED  NOT NULL DEFAULT 2048,
    est_banni          TINYINT(1)    NOT NULL DEFAULT 0,
    date_inscription   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_utilisateur),
    UNIQUE KEY uq_utilisateur_pseudo (pseudo),
    UNIQUE KEY uq_utilisateur_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Serveurs Minecraft : statut (EN_LIGNE, HORS_LIGNE, DEMARRAGE, MAINTENANCE)
-- -----------------------------------------------------------------------------
CREATE TABLE SERVEUR (
    id_serveur         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    id_proprietaire    INT UNSIGNED      NOT NULL,
    nom_serveur        VARCHAR(64)       NOT NULL,
    adresse_ip         VARCHAR(255)      NOT NULL,
    port               SMALLINT UNSIGNED NOT NULL,
    version_mc         VARCHAR(20)       NOT NULL,
    type_loader        VARCHAR(20)       DEFAULT 'VANILLA',
    mode_jeu           VARCHAR(30)       NOT NULL,
    statut             VARCHAR(20)       NOT NULL DEFAULT 'HORS_LIGNE',
    joueurs_connectes  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    slots_max          SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    ram_allouee        INT UNSIGNED      NOT NULL DEFAULT 2048,
    description        TEXT              NULL,
    est_public         TINYINT(1)        NOT NULL DEFAULT 1,
    date_creation      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_serveur),
    UNIQUE KEY uq_serveur_port (port),
    KEY i_serveur_proprietaire (id_proprietaire),
    KEY i_serveur_public_statut (est_public, statut),
    CONSTRAINT fk_serveur_proprietaire FOREIGN KEY (id_proprietaire) REFERENCES UTILISATEUR (id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Co-administrateurs : niveau LECTURE, CONSOLE ou COMPLET
CREATE TABLE ADMINISTRER_SERVEUR (
    id_serveur         INT UNSIGNED NOT NULL,
    id_utilisateur     INT UNSIGNED NOT NULL,
    niveau_permission  VARCHAR(20)  DEFAULT 'COMPLET',
    date_attribution   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_serveur, id_utilisateur),
    KEY i_administrer_utilisateur (id_utilisateur),
    CONSTRAINT fk_administrer_serveur FOREIGN KEY (id_serveur) REFERENCES SERVEUR (id_serveur),
    CONSTRAINT fk_administrer_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES UTILISATEUR (id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Listes de joueurs : WHITELIST, BLACKLIST ou OP
CREATE TABLE ETRE_LISTER (
    id_serveur  INT UNSIGNED NOT NULL,
    pseudo_mc   VARCHAR(16)  NOT NULL,
    type_liste  VARCHAR(16)  NOT NULL,
    raison      VARCHAR(255) NULL,
    date_ajout  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_serveur, pseudo_mc),
    CONSTRAINT fk_lister_serveur FOREIGN KEY (id_serveur) REFERENCES SERVEUR (id_serveur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE BACKUP (
    id_backup       INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_serveur      INT UNSIGNED    NOT NULL,
    nom_backup      VARCHAR(100)    NOT NULL,
    nom_fichier     VARCHAR(255)    NOT NULL,
    chemin_fichier  VARCHAR(512)    NOT NULL,
    taille_octets   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    date_creation   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_backup),
    KEY i_backup_serveur (id_serveur),
    CONSTRAINT fk_backup_serveur FOREIGN KEY (id_serveur) REFERENCES SERVEUR (id_serveur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mesures de consommation (RAM, CPU, joueurs) d'un serveur
CREATE TABLE HISTORIQUE_RESSOURCE (
    id_mesure         BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    id_serveur        INT UNSIGNED      NOT NULL,
    ram_utilisee_mo   INT UNSIGNED      NOT NULL,
    cpu_pourcentage   DECIMAL(5,2)      NOT NULL,
    joueurs_en_ligne  SMALLINT UNSIGNED NOT NULL,
    date_mesure       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_mesure),
    KEY i_historique_serveur_date (id_serveur, date_mesure),
    CONSTRAINT fk_historique_serveur FOREIGN KEY (id_serveur) REFERENCES SERVEUR (id_serveur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuration de la carte web (mode de rendu 2D ou 3D)
CREATE TABLE CONFIGURATION_MAP (
    id_serveur          INT UNSIGNED      NOT NULL,
    est_active          TINYINT(1)        NOT NULL DEFAULT 1,
    mode_rendu          VARCHAR(8)        NOT NULL DEFAULT '3D',
    afficher_joueurs    TINYINT(1)        NOT NULL DEFAULT 1,
    afficher_marqueurs  TINYINT(1)        NOT NULL DEFAULT 1,
    port_map            SMALLINT UNSIGNED NOT NULL,
    date_maj            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_serveur),
    CONSTRAINT fk_map_serveur FOREIGN KEY (id_serveur) REFERENCES SERVEUR (id_serveur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE CONNEXION_JOUEUR (
    id_connexion       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_serveur         INT UNSIGNED    NOT NULL,
    pseudo_mc          VARCHAR(16)     NOT NULL,
    uuid_mc            CHAR(36)        NULL,
    adresse_ip         VARCHAR(45)     NULL,
    date_connexion     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_deconnexion   DATETIME        NULL,
    motif_deconnexion  VARCHAR(100)    NULL,
    PRIMARY KEY (id_connexion),
    KEY i_connexion_serveur_date (id_serveur, date_connexion),
    CONSTRAINT fk_connexion_serveur FOREIGN KEY (id_serveur) REFERENCES SERVEUR (id_serveur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE AVIS_SERVEUR (
    id_serveur      INT UNSIGNED     NOT NULL,
    id_utilisateur  INT UNSIGNED     NOT NULL,
    note            TINYINT UNSIGNED NOT NULL,
    commentaire     TEXT             NULL,
    date_avis       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_serveur, id_utilisateur),
    KEY i_avis_serveur_utilisateur (id_utilisateur),
    CONSTRAINT fk_avis_serveur_serveur FOREIGN KEY (id_serveur) REFERENCES SERVEUR (id_serveur),
    CONSTRAINT fk_avis_serveur_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES UTILISATEUR (id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- -----------------------------------------------------------------------------
-- Ressources communautaires : type MOD, PLUGIN ou MOD_PACK,
-- modération EN_ATTENTE, VALIDE ou SUSPENDU.
-- -----------------------------------------------------------------------------
CREATE TABLE RESSOURCE (
    id_ressource        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_auteur           INT UNSIGNED NOT NULL,
    nom_ressource       VARCHAR(100) NOT NULL,
    type_ressource      VARCHAR(16)  NOT NULL,
    version_compatible  VARCHAR(20)  NOT NULL,
    resume              VARCHAR(255) NOT NULL,
    description         TEXT         NOT NULL,
    nb_telechargements  INT UNSIGNED NOT NULL DEFAULT 0,
    chemin_fichier      VARCHAR(512) NULL,
    statut_moderation   VARCHAR(20)  NOT NULL DEFAULT 'EN_ATTENTE',
    date_publication    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_ressource),
    KEY i_ressource_auteur (id_auteur),
    KEY i_ressource_catalogue (statut_moderation, type_ressource, date_publication),
    CONSTRAINT fk_ressource_auteur FOREIGN KEY (id_auteur) REFERENCES UTILISATEUR (id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versions publiées d'une ressource (archive .jar / .zip)
CREATE TABLE FICHIER_RESSOURCE (
    id_fichier                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_ressource                INT UNSIGNED    NOT NULL,
    numero_version              VARCHAR(32)     NOT NULL,
    version_mc                  VARCHAR(20)     NOT NULL,
    nom_fichier                 VARCHAR(255)    NOT NULL,
    chemin_fichier              VARCHAR(512)    NOT NULL,
    taille_octets               BIGINT UNSIGNED NOT NULL,
    changelog                   TEXT            NULL,
    nb_telechargements_version  INT UNSIGNED    NOT NULL DEFAULT 0,
    date_ajout                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_fichier),
    KEY i_fichier_ressource (id_ressource, date_ajout),
    CONSTRAINT fk_fichier_ressource FOREIGN KEY (id_ressource) REFERENCES RESSOURCE (id_ressource)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE AVIS_RESSOURCE (
    id_ressource    INT UNSIGNED     NOT NULL,
    id_utilisateur  INT UNSIGNED     NOT NULL,
    note            TINYINT UNSIGNED NOT NULL,
    commentaire     TEXT             NULL,
    date_avis       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_ressource, id_utilisateur),
    KEY i_avis_ressource_utilisateur (id_utilisateur),
    CONSTRAINT fk_avis_ressource_ressource FOREIGN KEY (id_ressource) REFERENCES RESSOURCE (id_ressource),
    CONSTRAINT fk_avis_ressource_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES UTILISATEUR (id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE INSTALLER_RESSOURCE (
    id_serveur         INT UNSIGNED NOT NULL,
    id_ressource       INT UNSIGNED NOT NULL,
    date_installation  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_serveur, id_ressource),
    KEY i_installer_ressource (id_ressource),
    CONSTRAINT fk_installer_serveur FOREIGN KEY (id_serveur) REFERENCES SERVEUR (id_serveur),
    CONSTRAINT fk_installer_ressource FOREIGN KEY (id_ressource) REFERENCES RESSOURCE (id_ressource)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Modération, relations, support et audit
-- -----------------------------------------------------------------------------

-- Cible polymorphe : type_cible SERVEUR, RESSOURCE ou UTILISATEUR (pas de clé étrangère sur id_cible)
CREATE TABLE SIGNALEMENT (
    id_signalement         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_auteur_signalement  INT UNSIGNED NOT NULL,
    type_cible             VARCHAR(16)  NOT NULL,
    id_cible               INT UNSIGNED NOT NULL,
    motif                  VARCHAR(100) NOT NULL,
    description            TEXT         NULL,
    statut                 VARCHAR(20)  NOT NULL DEFAULT 'EN_ATTENTE',
    date_signalement       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_signalement),
    KEY i_signalement_auteur (id_auteur_signalement),
    KEY i_signalement_cible (type_cible, id_cible),
    KEY i_signalement_statut (statut, date_signalement),
    CONSTRAINT fk_signalement_auteur FOREIGN KEY (id_auteur_signalement) REFERENCES UTILISATEUR (id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Amis : statut EN_ATTENTE ou ACCEPTE
CREATE TABLE RELATION_UTILISATEUR (
    id_demandeur     INT UNSIGNED NOT NULL,
    id_destinataire  INT UNSIGNED NOT NULL,
    statut           VARCHAR(20)  NOT NULL DEFAULT 'EN_ATTENTE',
    date_relation    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_demandeur, id_destinataire),
    KEY i_relation_destinataire (id_destinataire),
    CONSTRAINT fk_relation_demandeur FOREIGN KEY (id_demandeur) REFERENCES UTILISATEUR (id_utilisateur),
    CONSTRAINT fk_relation_destinataire FOREIGN KEY (id_destinataire) REFERENCES UTILISATEUR (id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE TICKET_SUPPORT (
    id_ticket       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_utilisateur  INT UNSIGNED NOT NULL,
    sujet           VARCHAR(150) NOT NULL,
    categorie       VARCHAR(40)  NOT NULL,
    statut          VARCHAR(20)  NOT NULL DEFAULT 'OUVERT',
    date_creation   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_ticket),
    KEY i_ticket_utilisateur (id_utilisateur),
    CONSTRAINT fk_ticket_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES UTILISATEUR (id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE MESSAGE_TICKET (
    id_message  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_ticket   INT UNSIGNED NOT NULL,
    id_auteur   INT UNSIGNED NOT NULL,
    contenu     TEXT         NOT NULL,
    date_envoi  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_message),
    KEY i_message_ticket (id_ticket, date_envoi),
    KEY i_message_auteur (id_auteur),
    CONSTRAINT fk_message_ticket FOREIGN KEY (id_ticket) REFERENCES TICKET_SUPPORT (id_ticket),
    CONSTRAINT fk_message_auteur FOREIGN KEY (id_auteur) REFERENCES UTILISATEUR (id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal d'audit : niveau INFO, WARNING ou CRITICAL ; l'auteur peut être supprimé (RGPD)
CREATE TABLE LOG_SYSTEME (
    id_log          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_utilisateur  INT UNSIGNED    NULL,
    niveau          VARCHAR(16)     NOT NULL,
    action          VARCHAR(64)     NOT NULL,
    details         TEXT            NULL,
    adresse_ip      VARCHAR(45)     NULL,
    date_log        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_log),
    KEY i_log_date (date_log),
    KEY i_log_utilisateur (id_utilisateur),
    CONSTRAINT fk_log_utilisateur FOREIGN KEY (id_utilisateur) REFERENCES UTILISATEUR (id_utilisateur) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
