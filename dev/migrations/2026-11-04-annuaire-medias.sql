-- Annuaire public de serveurs (hébergés par Boötes ou externes), médias (icônes, photos de profil,
-- bannières, galeries) et pages personnalisées par blocs (Premium). Idempotent : rejoué à chaque
-- lancement de dev/start.sh.

-- Serveurs : origine HEBERGE (instance Boötes) ou EXTERNE (simple fiche d'annuaire)
ALTER TABLE SERVEUR
    ADD COLUMN IF NOT EXISTS origine           VARCHAR(10)  NOT NULL DEFAULT 'HEBERGE' AFTER id_proprietaire,
    ADD COLUMN IF NOT EXISTS resume            VARCHAR(160) NULL AFTER description,
    ADD COLUMN IF NOT EXISTS etiquettes        VARCHAR(255) NOT NULL DEFAULT '' AFTER resume,
    ADD COLUMN IF NOT EXISTS site_web          VARCHAR(255) NULL AFTER etiquettes,
    ADD COLUMN IF NOT EXISTS discord           VARCHAR(255) NULL AFTER site_web,
    ADD COLUMN IF NOT EXISTS date_verification DATETIME     NULL AFTER discord;

-- Un port n'est plus unique : plusieurs serveurs externes utilisent 25565. Le couple adresse + port
-- reste unique (un serveur ne peut être référencé qu'une fois) ; les ports des instances hébergées
-- sont toujours attribués sans collision par ServerManager.
ALTER TABLE SERVEUR DROP INDEX IF EXISTS uq_serveur_port;
ALTER TABLE SERVEUR ADD UNIQUE INDEX IF NOT EXISTS uq_serveur_adresse (adresse_ip, port);
ALTER TABLE SERVEUR ADD INDEX IF NOT EXISTS i_serveur_origine (origine, est_public);

-- Ressources : catégories (liste « ,technologie,magie, ») et chargeur (FABRIC, PAPER...)
ALTER TABLE RESSOURCE
    ADD COLUMN IF NOT EXISTS categories VARCHAR(255) NOT NULL DEFAULT '' AFTER resume,
    ADD COLUMN IF NOT EXISTS chargeur   VARCHAR(20)  NULL AFTER categories;

-- Images téléversées : type_cible SERVEUR, RESSOURCE ou UTILISATEUR ; role ICONE, AVATAR,
-- BANNIERE ou GALERIE. Le jeton aléatoire sert de nom de fichier et d'URL (non devinable).
CREATE TABLE IF NOT EXISTS MEDIA (
    id_media         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    id_proprietaire  INT UNSIGNED      NOT NULL,
    type_cible       VARCHAR(16)       NOT NULL,
    id_cible         INT UNSIGNED      NOT NULL,
    role             VARCHAR(16)       NOT NULL,
    jeton            CHAR(32)          NOT NULL,
    largeur          SMALLINT UNSIGNED NOT NULL,
    hauteur          SMALLINT UNSIGNED NOT NULL,
    taille_octets    INT UNSIGNED      NOT NULL,
    legende          VARCHAR(140)      NULL,
    position         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    date_ajout       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_media),
    UNIQUE KEY uq_media_jeton (jeton),
    KEY i_media_cible (type_cible, id_cible, role, position),
    KEY i_media_proprietaire (id_proprietaire),
    CONSTRAINT fk_media_proprietaire FOREIGN KEY (id_proprietaire) REFERENCES UTILISATEUR (id_utilisateur) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mise en page d'une fiche (serveur ou ressource) : thème et blocs ordonnés (JSON validé par PageLayout)
CREATE TABLE IF NOT EXISTS PAGE_PERSONNALISEE (
    type_cible  VARCHAR(16)  NOT NULL,
    id_cible    INT UNSIGNED NOT NULL,
    theme       VARCHAR(20)  NOT NULL DEFAULT 'defaut',
    blocs       TEXT         NOT NULL,
    id_auteur   INT UNSIGNED NULL,
    date_maj    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (type_cible, id_cible),
    KEY i_page_auteur (id_auteur),
    CONSTRAINT fk_page_auteur FOREIGN KEY (id_auteur) REFERENCES UTILISATEUR (id_utilisateur) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
