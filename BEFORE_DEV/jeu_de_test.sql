-- =============================================================================
-- PROJET BOÖTES - JEU DE DONNÉES DE TEST COMPLET (SEEDER PRODUCTION)
-- Base cible : HEBERGEUR_MC
-- =============================================================================

USE HEBERGEUR_MC;

SET FOREIGN_KEY_CHECKS = 0;

-- Nettoyage préalable pour permettre de relancer le script sans erreur de doublon
TRUNCATE TABLE SIGNALEMENT_UTILISATEUR;
TRUNCATE TABLE SIGNALEMENT_PLUGIN;
TRUNCATE TABLE SIGNALEMENT_MODPACK;
TRUNCATE TABLE SIGNALEMENT_MOD;
TRUNCATE TABLE SIGNALEMENT_SERVEUR;
TRUNCATE TABLE SIGNALEMENT;
TRUNCATE TABLE EVALUATION_PLUGIN;
TRUNCATE TABLE EVALUATION_MODPACK;
TRUNCATE TABLE EVALUATION_MOD;
TRUNCATE TABLE EVALUATION_SERVEUR;
TRUNCATE TABLE EVALUATION;
TRUNCATE TABLE ETRE_LISTER;
TRUNCATE TABLE CONNEXION_JOUEUR;
TRUNCATE TABLE JOUEUR;
TRUNCATE TABLE HISTORIQUE_RESSOURCE;
TRUNCATE TABLE BACKUPS;
TRUNCATE TABLE ADMINISTRER_SERVEUR;
TRUNCATE TABLE SERVEUR;
TRUNCATE TABLE CONFIGURATION_MAP;
TRUNCATE TABLE CONTENIR_PLUGIN;
TRUNCATE TABLE CONTENIR_MOD;
TRUNCATE TABLE COMPATIBLE_PLUGIN_VERSION;
TRUNCATE TABLE FICHIER_PLUGIN;
TRUNCATE TABLE PLUGIN;
TRUNCATE TABLE FICHIER_MOD;
TRUNCATE TABLE `MOD`;
TRUNCATE TABLE MOD_PACK;
TRUNCATE TABLE RELATION_UTILISATEUR;
TRUNCATE TABLE UTILISATEUR;
TRUNCATE TABLE IMAGE;
TRUNCATE TABLE TYPE_IMAGE;
TRUNCATE TABLE STATUS_AMIS;
TRUNCATE TABLE TYPE_SIGNALEMENT;
TRUNCATE TABLE VERSION;
TRUNCATE TABLE TYPE_DE_VERSION;
TRUNCATE TABLE MODE_DE_JEU;
TRUNCATE TABLE ETAT;
TRUNCATE TABLE TYPE_DE_COMPTE;

-- -----------------------------------------------------------------------------
-- 1. TABLES DE RÉFÉRENCE (TYPES, ÉTATS, VERSIONS & MÉDIAS)
-- -----------------------------------------------------------------------------

INSERT INTO TYPE_DE_COMPTE (TDC_ID, TDC_NOM_TYPE_COMPTE) VALUES
(1, 'Standard'),        -- Limité à 2048 Mo de RAM par serveur
(2, 'Premium'),         -- Autorisé jusqu'à 6144 Mo de RAM
(3, 'Administrateur');  -- SuperAdmin Boötes

INSERT INTO ETAT (ETA_ID, ETA_LIBELLE, ETA_DESCRIPTION) VALUES
(1, 'Éteint', 'Le serveur est hors ligne et ne consomme aucune RAM'),
(2, 'Allumé', 'Le serveur est en ligne et accepte les connexions'),
(3, 'Démarrage', 'Initialisation de la JVM et chargement de Fabric en cours'),
(4, 'Crash', 'Arrêt inattendu du processus Java');

INSERT INTO MODE_DE_JEU (MDJ_ID, MDJ_LIBELLE, MDJ_DESCRIPTION) VALUES
(1, 'Survie', 'Exploration, récolte de ressources et combats activés'),
(2, 'Créatif', 'Ressources illimitées et vol libre pour la construction'),
(3, 'Aventure', 'Interaction limitée avec les blocs pour les cartes scénarisées');

INSERT INTO TYPE_DE_VERSION (TDV_ID, TDV_LIBELLE, TDV_DESCRIPTION) VALUES
(1, 'Fabric (Optimisé)', 'Moteur léger préconfiguré avec Lithium, FerriteCore et Krypton'),
(2, 'Paper (Spigot/Plugins)', 'Moteur haute performance compatible avec les plugins Bukkit/Spigot'),
(3, 'Forge', 'Moteur classique pour les gros modpacks techniques');

INSERT INTO VERSION (VER_ID, TDV_ID, VER_LIBELLE) VALUES
(1, 1, '1.20.4'),
(2, 1, '1.20.1'),
(3, 1, '1.19.4'),
(4, 2, '1.20.4-Paper'),
(5, 3, '1.20.1-Forge');

INSERT INTO TYPE_SIGNALEMENT (TSGN_ID, TSGN_LIBELLE, TSGN_DESCRIPTION) VALUES
(1, 'Contenu inapproprié', 'Nom de serveur, description ou ressource enfreignant la charte'),
(2, 'Tricherie / Abus', 'Utilisation de failles, surcharge volontaire de RAM ou DDoS'),
(3, 'Crash serveur répété', 'Mod ou configuration provoquant une instabilité sur la machine hôte');

INSERT INTO STATUS_AMIS (STA_ID, STA_LIBELLE) VALUES
(1, 'En attente'),
(2, 'Accepté'),
(3, 'Bloqué');

INSERT INTO TYPE_IMAGE (TIMG_ID, TIMG_LIBELLE, TIMG_CHEMIN_DOSSIER, TIMG_DESCRIPTION) VALUES
('AVATAR', 'Avatar Utilisateur', '/assets/img/avatars', 'Photos de profil des comptes Boötes'),
('SRV_ICON', 'Icône Serveur', '/assets/img/servers', 'Icônes 64x64 affichées dans la liste des serveurs'),
('MOD_ICON', 'Logo Mod / ModPack', '/assets/img/mods', 'Illustrations du catalogue de mods et plugins');

INSERT INTO IMAGE (IMG_ID, TIMG_ID, IMG_NOM_FICHIER, IMG_DATE_PUBLICATION) VALUES
(1, 'AVATAR', 'admin_arcturus.png', '2026-09-01 10:00:00'),
(2, 'AVATAR', 'steve_premium.png', '2026-09-05 14:30:00'),
(3, 'AVATAR', 'alex_builder.png', '2026-09-10 18:15:00'),
(4, 'SRV_ICON', 'survie_officielle.png', '2026-09-12 09:00:00'),
(5, 'MOD_ICON', 'bootes_core_pack.png', '2026-09-01 12:00:00');

-- -----------------------------------------------------------------------------
-- 2. UTILISATEURS (MOT DE PASSE : "Password123!" POUR TOUS LES COMPTES)
-- -----------------------------------------------------------------------------

INSERT INTO UTILISATEUR (
    UTI_ID, IMG_ID, TDC_ID, UTI_NOM, UTI_EMAIL, UTI_DATE_CREATION, UTI_MOT_DE_PASSE, UTI_DATE_DERNIERE_CONNEXION
) VALUES
(
    '0', NULL, 1, 'Anonyme', 'anonyme@bootes.local',
    '2026-01-01 00:00:00',
    '$argon2id$v=19$m=65536,t=4,p=1$b2JqZWN0aWZib290ZXM$7K9X2pQ4vL8mN1rT5wY3zA6bC8dE0fG2hI4jK6lM8nO',
    NULL
),
(
    'usr_admin_bootes_000000000000001', 1, 3, 'AdminBootes', 'admin@bootes.local',
    '2026-09-01 08:00:00',
    '$2y$10$m9lzfH9.2J.5syUeloeMLu.MOo/gGEClAMrkCAAUOYQTTosddntT6',
    '2026-10-17 01:30:00'
),
(
    'usr_steve_premium_00000000000002', 2, 2, 'Steve_Premium', 'premium@bootes.local',
    '2026-09-05 14:00:00',
    '$2y$10$m9lzfH9.2J.5syUeloeMLu.MOo/gGEClAMrkCAAUOYQTTosddntT6',
    '2026-10-16 21:45:00'
),
(
    'usr_alex_player_0000000000000003', 3, 1, 'Alex_Player', 'alex@bootes.local',
    '2026-09-10 18:00:00',
    '$2y$10$m9lzfH9.2J.5syUeloeMLu.MOo/gGEClAMrkCAAUOYQTTosddntT6',
    '2026-10-16 19:10:00'
),
(
    'usr_noob_master_0000000000000004', NULL, 1, 'NoobMaster', 'noob@bootes.local',
    '2026-10-01 11:20:00',
    '$2y$10$m9lzfH9.2J.5syUeloeMLu.MOo/gGEClAMrkCAAUOYQTTosddntT6',
    '2026-10-15 16:05:00'
);

-- -----------------------------------------------------------------------------
-- 3. CATALOGUE DE MODS FABRIC & PLUGINS
-- -----------------------------------------------------------------------------

INSERT INTO `MOD` (MOD_ID, UTI_ID, IMG_ID, MOD_NOM, MOD_DATE_PUBLICATION, MOD_CHEMIN_DOSSIER, MOD_DESCRIPTION) VALUES
(1, 'usr_admin_bootes_000000000000001', 5, 'Lithium', '2026-09-01 10:00:00', 'lithium', 'Optimisation générale de la physique du jeu, de l\'IA des entités et du chargement des chunks sous Fabric sans altérer le comportement Vanilla.'),
(2, 'usr_admin_bootes_000000000000001', 5, 'FerriteCore', '2026-09-01 10:05:00', 'ferritecore', 'Réduit drastiquement l\'utilisation de la mémoire vive (RAM) jusqu\'à 45% en dédupliquant les données d\'état des blocs et des modèles.'),
(3, 'usr_admin_bootes_000000000000001', 5, 'Krypton', '2026-09-01 10:10:00', 'krypton', 'Optimise la pile réseau Netty de Minecraft pour réduire la consommation CPU liée aux paquets et améliorer le ping des joueurs.'),
(4, 'usr_steve_premium_00000000000002', NULL, 'Spark', '2026-09-12 15:00:00', 'spark', 'Profiler de performance avancé permettant d\'analyser les TPS, les fuites de mémoire RAM et les goulots d\'étranglement CPU en direct.');

INSERT INTO FICHIER_MOD (
    FMOD_ID, MOD_ID, VER_ID, FMOD_NOM_FICHIER, FMOD_TAILLE_MO, FMOD_DATE_PUBLICATION, FMOD_NB_DOWNLOAD, FMOD_DESCRIPTION
) VALUES
(1, 1, 1, 'lithium-fabric-mc1.20.4-0.12.1.jar', 1, '2026-09-01 10:00:00', 342, 'Build stable Fabric 1.20.4'),
(2, 2, 1, 'ferritecore-6.0.3-fabric.jar', 1, '2026-09-01 10:05:00', 318, 'Build mémoire optimisée Fabric 1.20.4'),
(3, 3, 1, 'krypton-0.2.6.jar', 1, '2026-09-01 10:10:00', 289, 'Build réseau Fabric 1.20.4'),
(4, 4, 1, 'spark-1.10.53-fabric.jar', 4, '2026-09-12 15:00:00', 164, 'Profiler complet Fabric 1.20.4'),
(5, 1, 2, 'lithium-fabric-mc1.20.1-0.11.2.jar', 1, '2026-09-01 10:00:00', 120, 'Build rétrocompatible Fabric 1.20.1');

INSERT INTO PLUGIN (PLUG_ID, UTI_ID, IMG_ID, PLUG_NOM, PLUG_DATE_PUBLICATION, PLUG_CHEMIN_DOSSIER, PLUG_DESCRIPTION) VALUES
(1, 'usr_admin_bootes_000000000000001', NULL, 'WorldEdit', '2026-09-02 12:00:00', 'worldedit', 'Éditeur de carte in-game ultra-rapide pour sculpter le terrain et copier-coller des structures.'),
(2, 'usr_steve_premium_00000000000002', NULL, 'EssentialsX', '2026-09-06 16:00:00', 'essentialsx', 'Suite de plus de 130 commandes essentielles (/spawn, /home, /tpa, économie et kits) pour serveurs survie.');

INSERT INTO FICHIER_PLUGIN (
    FPLU_ID, PLUG_ID, FPLUG_NOM_FICHIER, FPLUG_TAILLE_MO, FPLUG_DATE_PUBLICATION, FPLUG_NB_DOWNLOAD, FPLUG_DESCRIPTION
) VALUES
(1, 1, 'worldedit-bukkit-7.3.0.jar', 5, '2026-09-02 12:00:00', 95, 'Compatible Paper 1.20.x'),
(2, 2, 'EssentialsX-2.20.1.jar', 3, '2026-09-06 16:00:00', 112, 'Release officielle Paper/Spigot');

INSERT INTO COMPATIBLE_PLUGIN_VERSION (VER_ID, FPLU_ID) VALUES
(1, 1),
(2, 1),
(4, 1),
(4, 2);

-- -----------------------------------------------------------------------------
-- 4. MODPACKS & COMPOSITIONS
-- -----------------------------------------------------------------------------

INSERT INTO MOD_PACK (
    MOPA_ID, VER_ID, IMG_ID, UTI_ID, MOPA_NOM, MOPA_DATE_PUBLICATION, MOPA_CHEMIN_DOSSIER, MOPA_EST_PUBLIC, MOPA_NB_DOWNLOAD, MOPA_DESCRIPTION
) VALUES
(
    1, 1, 5, 'usr_admin_bootes_000000000000001',
    'Boötes Core - Vanilla Optimisé',
    '2026-09-01 12:00:00',
    'bootes_core_1204',
    1, 412,
    'Profil système officiel : expérience 100% Vanilla propulsée par Fabric avec Lithium, FerriteCore et Krypton pour économiser la RAM.'
),
(
    2, 1, NULL, 'usr_steve_premium_00000000000002',
    'Survie Explorateur',
    '2026-09-15 17:30:00',
    'survie_explorateur',
    1, 87,
    'ModPack communautaire orienté survie technique, profilage temps réel et exploration fluide à plusieurs.'
),
(
    3, 1, NULL, 'usr_alex_player_0000000000000003',
    'Pack_MiniJeux_EntreAmis',
    '2026-09-20 20:00:00',
    'srv_2_private',
    0, 1,
    'Profil privé généré automatiquement pour le serveur MiniJeux_EntreAmis.'
);

INSERT INTO CONTENIR_MOD (MOD_ID, MOPA_ID) VALUES
-- Pack 1 : Boötes Core (Lithium + FerriteCore + Krypton)
(1, 1),
(2, 1),
(3, 1),
-- Pack 2 : Survie Explorateur (Lithium + FerriteCore + Krypton + Spark)
(1, 2),
(2, 2),
(3, 2),
(4, 2),
-- Pack 3 : Profil privé d'Alex (Lithium + FerriteCore)
(1, 3),
(2, 3);

INSERT INTO CONTENIR_PLUGIN (PLUG_ID, MOPA_ID) VALUES
(1, 2),
(2, 3);

-- -----------------------------------------------------------------------------
-- 5. SERVEURS MINECRAFT, CARTES, CO-ADMINS, BACKUPS & HISTORIQUE RAM/CPU
-- -----------------------------------------------------------------------------

INSERT INTO CONFIGURATION_MAP (CMAP_ID, CMAP_AFFICHER_JOUEURS, CMAP_2D) VALUES
(1, 1, 0), -- Map 3D interactive avec position des joueurs affichée
(2, 1, 1), -- Map 2D légère avec joueurs affichés
(3, 0, 0); -- Map 3D furtive (sans affichage des joueurs)

INSERT INTO SERVEUR (
    SVR_ID, ETA_ID, MOPA_ID, CMAP_ID, UTI_ID, VER_ID, IMG_ID, MDJ_ID,
    SVR_NOM, SVR_ADDRESSE, SVR_PORT, SVR_DATE_CREATION, SVR_RAM_ALLOUEE_MO,
    SVR_JOUEURS_MAX, SVR_DECRIPTION, SVR_NB_SECONDES_OUVERT
) VALUES
(
    1, 2, 1, 1, 'usr_admin_bootes_000000000000001', 1, 4, 1,
    'Survie_Officielle', '127.0.0.1', 25565, '2026-09-02', 2048,
    20, 'Serveur survie officiel de démonstration Boötes sous Fabric 1.20.4 (Lithium + FerriteCore).', 86400
),
(
    2, 1, 3, 2, 'usr_alex_player_0000000000000003', 1, NULL, 2,
    'MiniJeux_EntreAmis', '127.0.0.1', 25566, '2026-09-20', 1536,
    10, 'Petit serveur créatif et mini-jeux entre amis pour le week-end.', 14400
),
(
    3, 1, 2, 3, 'usr_steve_premium_00000000000002', 1, NULL, 1,
    'Mega_Serveur_Mods', '127.0.0.1', 25567, '2026-09-25', 3072,
    30, 'Instance haute capacité (Offre Premium 3 Go RAM) dédiée aux projets d\''automatisation.', 43200
);

-- Co-administration : Alex_Player aide AdminBootes sur le Serveur 1 ; Steve_Premium aide Alex sur le Serveur 2
INSERT INTO ADMINISTRER_SERVEUR (SVR_ID, UTI_ID) VALUES
(1, 'usr_alex_player_0000000000000003'),
(2, 'usr_steve_premium_00000000000002');

-- Sauvegardes (.tar.gz) associées aux serveurs
INSERT INTO BACKUPS (BUP_ID, SVR_ID, BUP_NOM, BUP_DATE_CREATION, BUP_TAILLE_FICHIER, BUP_CHEMIN_FICHIER) VALUES
(1, 1, 'Backup #1 (Avant Ender Dragon)', '2026-10-15 22:00:00', 48234496, 'srv1_20261015_220000.tar.gz'),
(2, 1, 'Backup #1 (Quotidien Auto)', '2026-10-16 23:55:00', 52428800, 'srv1_20261016_235500.tar.gz'),
(3, 2, 'Backup #2 (Spawn Terminé)', '2026-10-14 18:30:00', 19922944, 'srv2_20261014_183000.tar.gz'),
(4, 3, 'Backup #3 (Usine à Fer)', '2026-10-16 12:15:00', 104857600, 'srv3_20261016_121500.tar.gz');

-- Relevés de consommation RAM (Mo) et CPU (%) pour les graphiques de monitoring
INSERT INTO HISTORIQUE_RESSOURCE (HIRE_ID, SVR_ID, HIRE_DATE_HEURE, HIRE_RAM_UTILISE, HIRE_CPU_POURCENTAGE) VALUES
(1, 1, '2026-10-17 00:30:00', 1120.50, 14.20),
(2, 1, '2026-10-17 00:45:00', 1245.00, 18.75),
(3, 1, '2026-10-17 01:00:00', 1380.25, 24.10),
(4, 1, '2026-10-17 01:15:00', 1310.80, 19.40),
(5, 1, '2026-10-17 01:30:00', 1420.00, 22.85),
(6, 2, '2026-10-16 18:00:00', 980.00, 11.50),
(7, 3, '2026-10-16 21:00:00', 2450.75, 38.90);

-- -----------------------------------------------------------------------------
-- 6. JOUEURS MINECRAFT, WHITELIST/BLACKLIST & SESSIONS DE JEU
-- -----------------------------------------------------------------------------

INSERT INTO JOUEUR (JOU_UUID_MINECRAFT, JOU_PSEUDO) VALUES
('069a79f4-44e9-4726-a5be-fca90e38aaf5', 'Notch'),
('853c80ef-3c37-49fd-aa49-938b674adae6', 'jeb_'),
('61699b2e-d327-4a01-9f1e-0ea8c3f06bc6', 'Dinnerbone'),
('f84c6a79-0a4e-45e0-879b-cd49ebd4c4e2', 'Alex_MC'),
('c06f8906-4c8a-4911-9c29-ea1dbd1aab82', 'GrieferDuDimanche');

-- EL_STATUT : 1 = Whitelist, 2 = Blacklist (Banni)
INSERT INTO ETRE_LISTER (JOU_UUID_MINECRAFT, SVR_ID, EL_STATUT) VALUES
('069a79f4-44e9-4726-a5be-fca90e38aaf5', 1, 1),
('853c80ef-3c37-49fd-aa49-938b674adae6', 1, 1),
('61699b2e-d327-4a01-9f1e-0ea8c3f06bc6', 1, 1),
('c06f8906-4c8a-4911-9c29-ea1dbd1aab82', 1, 2), -- Banni du serveur 1
('f84c6a79-0a4e-45e0-879b-cd49ebd4c4e2', 2, 1),
('853c80ef-3c37-49fd-aa49-938b674adae6', 3, 1);

-- Sessions de connexion (inclut deux sessions en cours avec COJ_DATE_DECONNEXION = NULL sur le Serveur 1)
INSERT INTO CONNEXION_JOUEUR (COJ_ID, SVR_ID, JOU_UUID_MINECRAFT, COJ_DATE_CONNEXION, COJ_DATE_DECONNEXION) VALUES
(1, 1, '069a79f4-44e9-4726-a5be-fca90e38aaf5', '2026-10-16 20:00:00', '2026-10-16 22:30:00'),
(2, 2, 'f84c6a79-0a4e-45e0-879b-cd49ebd4c4e2', '2026-10-16 17:15:00', '2026-10-16 19:00:00'),
(3, 3, '61699b2e-d327-4a01-9f1e-0ea8c3f06bc6', '2026-10-15 21:00:00', '2026-10-15 23:45:00'),
(4, 1, '853c80ef-3c37-49fd-aa49-938b674adae6', '2026-10-17 00:40:00', NULL), -- Actuellement en ligne sur Serveur 1
(5, 1, '61699b2e-d327-4a01-9f1e-0ea8c3f06bc6', '2026-10-17 01:05:00', NULL); -- Actuellement en ligne sur Serveur 1

-- -----------------------------------------------------------------------------
-- 7. INTERACTIONS : AMIS, ÉVALUATIONS & SIGNALEMENTS (MODÉRATION)
-- -----------------------------------------------------------------------------

-- Relations d'amitié (1 = En attente, 2 = Accepté, 3 = Bloqué)
INSERT INTO RELATION_UTILISATEUR (AMI_ID, UTI_ID, STA_ID, UTI_ID_DEMANDEUR, AMI_DATE_DEMANDE, AMI_DATE_ACCEPTATION) VALUES
(1, 'usr_alex_player_0000000000000003', 2, 'usr_steve_premium_00000000000002', '2026-09-12 14:00:00', '2026-09-12 14:15:00'),
(2, 'usr_admin_bootes_000000000000001', 2, 'usr_alex_player_0000000000000003', '2026-09-15 09:30:00', '2026-09-15 10:00:00'),
(3, 'usr_steve_premium_00000000000002', 1, 'usr_noob_master_0000000000000004', '2026-10-16 20:00:00', NULL);

-- Évaluations globales et spécialisées (Notes de 1 à 5)
INSERT INTO EVALUATION (EVA_ID, UTI_ID, EVA_NOTE, EVA_DATE_PUBLICATION, EVA_COMMENTAIRE) VALUES
(1, 'usr_alex_player_0000000000000003', 5, '2026-10-10 18:00:00', 'Lithium + FerriteCore font des miracles, 20 TPS constants avec 1,5 Go de RAM !'),
(2, 'usr_steve_premium_00000000000002', 5, '2026-10-12 21:00:00', 'Le ModPack Boötes Core démarre en moins de 12 secondes sous Linux.'),
(3, 'usr_noob_master_0000000000000004', 4, '2026-10-14 15:30:00', 'Serveur Survie_Officielle très fluide et communauté accueillante.'),
(4, 'usr_alex_player_0000000000000003', 5, '2026-10-15 11:00:00', 'WorldEdit indispensable pour préparer notre map mini-jeux.');

INSERT INTO EVALUATION_MOD (EVA_ID, MOD_ID, EVA_NOTE, EVA_DATE_PUBLICATION, EVA_COMMENTAIRE) VALUES
(1, 1, 5, '2026-10-10 18:00:00', 'Lithium + FerriteCore font des miracles, 20 TPS constants avec 1,5 Go de RAM !');

INSERT INTO EVALUATION_MODPACK (EVA_ID, MOPA_ID, EVA_NOTE, EVA_DATE_PUBLICATION, EVA_COMMENTAIRE) VALUES
(2, 1, 5, '2026-10-12 21:00:00', 'Le ModPack Boötes Core démarre en moins de 12 secondes sous Linux.');

INSERT INTO EVALUATION_SERVEUR (EVA_ID, SVR_ID, EVA_NOTE, EVA_DATE_PUBLICATION, EVA_COMMENTAIRE) VALUES
(3, 1, 4, '2026-10-14 15:30:00', 'Serveur Survie_Officielle très fluide et communauté accueillante.');

INSERT INTO EVALUATION_PLUGIN (EVA_ID, PLUG_ID, EVA_NOTE, EVA_DATE_PUBLICATION, EVA_COMMENTAIRE) VALUES
(4, 1, 5, '2026-10-15 11:00:00', 'WorldEdit indispensable pour préparer notre map mini-jeux.');

-- Signalements visibles dans la tour de contrôle Administrateur (/admin-dashboard)
INSERT INTO SIGNALEMENT (SGN_ID, UTI_ID, TSGN_ID, SGN_MESSAGE, SGN_DATE_PUBLICATION) VALUES
(
    1, 'usr_alex_player_0000000000000003', 2,
    'Tentative de connexion suspecte et spam de commandes détectés dans les logs sur le port 25566.',
    '2026-10-16 19:30:00'
),
(
    2, 'usr_steve_premium_00000000000002', 3,
    'Pic de consommation mémoire inhabituel lors de la génération intensive de chunks avec WorldEdit.',
    '2026-10-16 22:10:00'
),
(
    3, 'usr_admin_bootes_000000000000001', 1,
    'Vérification de modération demandée sur le profil utilisateur NoobMaster.',
    '2026-10-17 00:15:00'
);

INSERT INTO SIGNALEMENT_SERVEUR (SGN_ID, SVR_ID) VALUES
(1, 2);

INSERT INTO SIGNALEMENT_PLUGIN (SGN_ID, PLUG_ID) VALUES
(2, 1);

INSERT INTO SIGNALEMENT_UTILISATEUR (SGN_ID, UTI_ID) VALUES
(3, 'usr_noob_master_0000000000000004');

SET FOREIGN_KEY_CHECKS = 1;