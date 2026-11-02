-- Schéma SQLite de test reconstitué à partir des requêtes de l'application.
-- Il émule la base MariaDB HEBERGEUR_MC (collation insensible à la casse sur les identifiants)
-- et active les clés étrangères pour détecter les suppressions incomplètes.

PRAGMA foreign_keys = ON;

CREATE TABLE UTILISATEUR (
    id_utilisateur     INTEGER PRIMARY KEY AUTOINCREMENT,
    pseudo             TEXT NOT NULL UNIQUE COLLATE NOCASE,
    email              TEXT NOT NULL UNIQUE COLLATE NOCASE,
    mot_de_passe       TEXT NOT NULL,
    role               TEXT NOT NULL DEFAULT 'UTILISATEUR',
    type_compte        TEXT NOT NULL DEFAULT 'STANDARD',
    max_ram_allouable  INTEGER NOT NULL DEFAULT 2048,
    est_banni          INTEGER NOT NULL DEFAULT 0,
    date_inscription   TEXT NOT NULL
);

CREATE TABLE SERVEUR (
    id_serveur         INTEGER PRIMARY KEY AUTOINCREMENT,
    id_proprietaire    INTEGER NOT NULL REFERENCES UTILISATEUR (id_utilisateur),
    nom_serveur        TEXT NOT NULL,
    adresse_ip         TEXT NOT NULL,
    port               INTEGER NOT NULL UNIQUE,
    version_mc         TEXT NOT NULL,
    type_loader        TEXT DEFAULT 'VANILLA',
    mode_jeu           TEXT NOT NULL,
    statut             TEXT NOT NULL DEFAULT 'HORS_LIGNE',
    joueurs_connectes  INTEGER NOT NULL DEFAULT 0,
    slots_max          INTEGER NOT NULL DEFAULT 20,
    ram_allouee        INTEGER NOT NULL DEFAULT 2048,
    description        TEXT,
    est_public         INTEGER NOT NULL DEFAULT 1,
    date_creation      TEXT NOT NULL
);

CREATE TABLE ADMINISTRER_SERVEUR (
    id_serveur         INTEGER NOT NULL REFERENCES SERVEUR (id_serveur),
    id_utilisateur     INTEGER NOT NULL REFERENCES UTILISATEUR (id_utilisateur),
    niveau_permission  TEXT DEFAULT 'COMPLET',
    date_attribution   TEXT NOT NULL,
    PRIMARY KEY (id_serveur, id_utilisateur)
);

CREATE TABLE ETRE_LISTER (
    id_serveur  INTEGER NOT NULL REFERENCES SERVEUR (id_serveur),
    pseudo_mc   TEXT NOT NULL COLLATE NOCASE,
    type_liste  TEXT NOT NULL,
    raison      TEXT,
    date_ajout  TEXT NOT NULL,
    PRIMARY KEY (id_serveur, pseudo_mc)
);

CREATE TABLE BACKUP (
    id_backup       INTEGER PRIMARY KEY AUTOINCREMENT,
    id_serveur      INTEGER NOT NULL REFERENCES SERVEUR (id_serveur),
    nom_backup      TEXT NOT NULL,
    nom_fichier     TEXT NOT NULL,
    chemin_fichier  TEXT NOT NULL,
    taille_octets   INTEGER NOT NULL DEFAULT 0,
    date_creation   TEXT NOT NULL
);

CREATE TABLE HISTORIQUE_RESSOURCE (
    id_mesure         INTEGER PRIMARY KEY AUTOINCREMENT,
    id_serveur        INTEGER NOT NULL REFERENCES SERVEUR (id_serveur),
    ram_utilisee_mo   INTEGER NOT NULL,
    cpu_pourcentage   REAL NOT NULL,
    joueurs_en_ligne  INTEGER NOT NULL,
    date_mesure       TEXT NOT NULL
);

CREATE TABLE CONFIGURATION_MAP (
    id_serveur          INTEGER PRIMARY KEY REFERENCES SERVEUR (id_serveur),
    est_active          INTEGER NOT NULL DEFAULT 1,
    mode_rendu          TEXT NOT NULL DEFAULT '3D',
    afficher_joueurs    INTEGER NOT NULL DEFAULT 1,
    afficher_marqueurs  INTEGER NOT NULL DEFAULT 1,
    port_map            INTEGER NOT NULL,
    date_maj            TEXT NOT NULL
);

CREATE TABLE CONNEXION_JOUEUR (
    id_connexion       INTEGER PRIMARY KEY AUTOINCREMENT,
    id_serveur         INTEGER NOT NULL REFERENCES SERVEUR (id_serveur),
    pseudo_mc          TEXT NOT NULL,
    uuid_mc            TEXT,
    adresse_ip         TEXT,
    date_connexion     TEXT NOT NULL,
    date_deconnexion   TEXT,
    motif_deconnexion  TEXT
);

CREATE TABLE AVIS_SERVEUR (
    id_serveur      INTEGER NOT NULL REFERENCES SERVEUR (id_serveur),
    id_utilisateur  INTEGER NOT NULL REFERENCES UTILISATEUR (id_utilisateur),
    note            INTEGER NOT NULL,
    commentaire     TEXT,
    date_avis       TEXT NOT NULL,
    PRIMARY KEY (id_serveur, id_utilisateur)
);

CREATE TABLE RESSOURCE (
    id_ressource        INTEGER PRIMARY KEY AUTOINCREMENT,
    id_auteur           INTEGER NOT NULL REFERENCES UTILISATEUR (id_utilisateur),
    nom_ressource       TEXT NOT NULL,
    type_ressource      TEXT NOT NULL,
    version_compatible  TEXT NOT NULL,
    resume              TEXT NOT NULL,
    description         TEXT NOT NULL,
    nb_telechargements  INTEGER NOT NULL DEFAULT 0,
    chemin_fichier      TEXT,
    statut_moderation   TEXT NOT NULL DEFAULT 'EN_ATTENTE',
    date_publication    TEXT NOT NULL
);

CREATE TABLE FICHIER_RESSOURCE (
    id_fichier                  INTEGER PRIMARY KEY AUTOINCREMENT,
    id_ressource                INTEGER NOT NULL REFERENCES RESSOURCE (id_ressource),
    numero_version              TEXT NOT NULL,
    version_mc                  TEXT NOT NULL,
    nom_fichier                 TEXT NOT NULL,
    chemin_fichier              TEXT NOT NULL,
    taille_octets               INTEGER NOT NULL,
    changelog                   TEXT,
    nb_telechargements_version  INTEGER NOT NULL DEFAULT 0,
    date_ajout                  TEXT NOT NULL
);

CREATE TABLE AVIS_RESSOURCE (
    id_ressource    INTEGER NOT NULL REFERENCES RESSOURCE (id_ressource),
    id_utilisateur  INTEGER NOT NULL REFERENCES UTILISATEUR (id_utilisateur),
    note            INTEGER NOT NULL,
    commentaire     TEXT,
    date_avis       TEXT NOT NULL,
    PRIMARY KEY (id_ressource, id_utilisateur)
);

CREATE TABLE INSTALLER_RESSOURCE (
    id_serveur         INTEGER NOT NULL REFERENCES SERVEUR (id_serveur),
    id_ressource       INTEGER NOT NULL REFERENCES RESSOURCE (id_ressource),
    date_installation  TEXT NOT NULL,
    PRIMARY KEY (id_serveur, id_ressource)
);

CREATE TABLE SIGNALEMENT (
    id_signalement         INTEGER PRIMARY KEY AUTOINCREMENT,
    id_auteur_signalement  INTEGER NOT NULL REFERENCES UTILISATEUR (id_utilisateur),
    type_cible             TEXT NOT NULL,
    id_cible               INTEGER NOT NULL,
    motif                  TEXT NOT NULL,
    description            TEXT,
    statut                 TEXT NOT NULL DEFAULT 'EN_ATTENTE',
    date_signalement       TEXT NOT NULL
);

CREATE TABLE RELATION_UTILISATEUR (
    id_demandeur     INTEGER NOT NULL REFERENCES UTILISATEUR (id_utilisateur),
    id_destinataire  INTEGER NOT NULL REFERENCES UTILISATEUR (id_utilisateur),
    statut           TEXT NOT NULL DEFAULT 'EN_ATTENTE',
    date_relation    TEXT NOT NULL,
    PRIMARY KEY (id_demandeur, id_destinataire)
);

CREATE TABLE TICKET_SUPPORT (
    id_ticket       INTEGER PRIMARY KEY AUTOINCREMENT,
    id_utilisateur  INTEGER NOT NULL REFERENCES UTILISATEUR (id_utilisateur),
    sujet           TEXT NOT NULL,
    categorie       TEXT NOT NULL,
    statut          TEXT NOT NULL DEFAULT 'OUVERT',
    date_creation   TEXT NOT NULL
);

CREATE TABLE MESSAGE_TICKET (
    id_message  INTEGER PRIMARY KEY AUTOINCREMENT,
    id_ticket   INTEGER NOT NULL REFERENCES TICKET_SUPPORT (id_ticket),
    id_auteur   INTEGER NOT NULL REFERENCES UTILISATEUR (id_utilisateur),
    contenu     TEXT NOT NULL,
    date_envoi  TEXT NOT NULL
);

CREATE TABLE LOG_SYSTEME (
    id_log          INTEGER PRIMARY KEY AUTOINCREMENT,
    id_utilisateur  INTEGER REFERENCES UTILISATEUR (id_utilisateur) ON DELETE SET NULL,
    niveau          TEXT NOT NULL,
    action          TEXT NOT NULL,
    details         TEXT,
    adresse_ip      TEXT,
    date_log        TEXT NOT NULL
);
