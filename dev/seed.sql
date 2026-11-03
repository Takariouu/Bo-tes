-- =============================================================================
--  Données de démonstration (développement local uniquement).
--  Tous les comptes ont le même mot de passe de test : Bootes-Demo-2026
--  Les adresses e-mail utilisent le domaine réservé example.test.
--
--  Import (après dev/schema.sql) :  mariadb HEBERGEUR_MC < dev/seed.sql
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- Comptes (un par rôle) — hash Argon2id de « Bootes-Demo-2026 »
-- -----------------------------------------------------------------------------
SET @demo_hash = '$argon2id$v=19$m=65536,t=4,p=2$bnY0WVdhMVpELjFINnRnTQ$KPJve+TK4gq3CGeNYWYhY2RI0CIVOIwLTNNPsAtMIqU';

INSERT INTO UTILISATEUR (id_utilisateur, pseudo, email, mot_de_passe, role, type_compte, max_ram_allouable, est_banni, date_inscription) VALUES
    (1, 'Takariou',    'takariou@example.test', @demo_hash, 'SUPER_ADMIN', 'PREMIUM',  6144, 0, NOW() - INTERVAL 60 DAY),
    (2, 'Arcturus',    'arcturus@example.test', @demo_hash, 'ADMIN',       'PREMIUM',  6144, 0, NOW() - INTERVAL 55 DAY),
    (3, 'Vega_Builds', 'vega@example.test',     @demo_hash, 'CREATEUR',    'PREMIUM',  6144, 0, NOW() - INTERVAL 48 DAY),
    (4, 'Nova',        'nova@example.test',     @demo_hash, 'UTILISATEUR', 'STANDARD', 2048, 0, NOW() - INTERVAL 40 DAY),
    (5, 'Orion_MC',    'orion@example.test',    @demo_hash, 'UTILISATEUR', 'STANDARD', 2048, 0, NOW() - INTERVAL 31 DAY),
    (6, 'Lyra',        'lyra@example.test',     @demo_hash, 'UTILISATEUR', 'STANDARD', 2048, 0, NOW() - INTERVAL 20 DAY),
    (7, 'Mizar',       'mizar@example.test',    @demo_hash, 'UTILISATEUR', 'STANDARD', 2048, 1, NOW() - INTERVAL 12 DAY);

-- -----------------------------------------------------------------------------
-- Serveurs Minecraft (2 en ligne : 5 Go alloués sur les 6 Go de l'hôte)
-- -----------------------------------------------------------------------------
INSERT INTO SERVEUR (id_serveur, id_proprietaire, nom_serveur, adresse_ip, port, version_mc, type_loader, mode_jeu,
                     statut, joueurs_connectes, slots_max, ram_allouee, description, est_public, date_creation) VALUES
    (1, 4, 'Nébuleuse Survie', 'play.bootes.local', 25565, '1.21.4', 'PAPER', 'SURVIE',
        'EN_LIGNE', 12, 30, 2048,
        'Survie communautaire sans téléportation : économie entre joueurs, villes et grands chantiers. Ambiance calme et entraide.', 1, NOW() - INTERVAL 38 DAY),
    (2, 3, 'Arcturus Créatif', 'play.bootes.local', 25566, '1.21.1', 'FABRIC', 'CREATIF',
        'EN_LIGNE', 5, 20, 3072,
        'Serveur créatif pour bâtisseurs : parcelles illimitées, concours mensuels et outils de construction.', 1, NOW() - INTERVAL 30 DAY),
    (3, 4, 'Bouvier Hardcore', 'play.bootes.local', 25567, '1.20.4', 'VANILLA', 'HARDCORE',
        'HORS_LIGNE', 0, 10, 1024,
        'Une seule vie, aucune seconde chance. Le monde est réinitialisé à chaque saison.', 1, NOW() - INTERVAL 21 DAY),
    (4, 2, 'Station Orbitale', 'play.bootes.local', 25568, '1.20.1', 'FORGE', 'MODDE',
        'MAINTENANCE', 0, 16, 4096,
        'Survie moddée tournée vers la technologie et l''exploration spatiale (modpack Constellation).', 1, NOW() - INTERVAL 15 DAY),
    (5, 6, 'Lyra Aventure', 'play.bootes.local', 25569, '1.21.4', 'PAPER', 'AVENTURE',
        'HORS_LIGNE', 0, 12, 1024,
        'Carte d''aventure privée entre amis : énigmes, donjons et récits.', 0, NOW() - INTERVAL 9 DAY),
    (6, 5, 'Constellation SMP', 'play.bootes.local', 25570, '1.21.1', 'NEOFORGE', 'MODDE',
        'HORS_LIGNE', 0, 20, 2048,
        'SMP moddé léger, idéal pour découvrir les mods sans machine puissante.', 1, NOW() - INTERVAL 4 DAY);

-- Co-administrateurs (LECTURE, CONSOLE, COMPLET)
INSERT INTO ADMINISTRER_SERVEUR (id_serveur, id_utilisateur, niveau_permission, date_attribution) VALUES
    (1, 5, 'CONSOLE', NOW() - INTERVAL 30 DAY),
    (1, 6, 'LECTURE', NOW() - INTERVAL 10 DAY),
    (2, 4, 'COMPLET', NOW() - INTERVAL 25 DAY);

-- Listes de joueurs
INSERT INTO ETRE_LISTER (id_serveur, pseudo_mc, type_liste, raison, date_ajout) VALUES
    (1, 'NovaStar',       'OP',        'Propriétaire',                      NOW() - INTERVAL 38 DAY),
    (1, 'OrionTheHunter', 'WHITELIST', NULL,                                NOW() - INTERVAL 30 DAY),
    (1, 'LyraSky',        'WHITELIST', NULL,                                NOW() - INTERVAL 10 DAY),
    (1, 'Pixel_Comet',    'WHITELIST', NULL,                                NOW() - INTERVAL 6 DAY),
    (1, 'Griefer42',      'BLACKLIST', 'Destruction de constructions',      NOW() - INTERVAL 3 DAY),
    (2, 'VegaBuilds',     'OP',        'Propriétaire',                      NOW() - INTERVAL 30 DAY),
    (2, 'NovaStar',       'WHITELIST', NULL,                                NOW() - INTERVAL 20 DAY);

-- Sauvegardes (lignes de démonstration : les archives ne sont pas présentes sur le disque)
INSERT INTO BACKUP (id_backup, id_serveur, nom_backup, nom_fichier, chemin_fichier, taille_octets, date_creation) VALUES
    (1, 1, 'Avant la mise à jour 1.21.4', 'server_1_demo_a.zip', 'backups/server_1_demo_a.zip', 734003200, NOW() - INTERVAL 7 DAY),
    (2, 1, 'Sauvegarde hebdomadaire',     'server_1_demo_b.zip', 'backups/server_1_demo_b.zip', 786432000, NOW() - INTERVAL 1 DAY),
    (3, 2, 'Fin du concours d''octobre',  'server_2_demo_a.zip', 'backups/server_2_demo_a.zip', 312475648, NOW() - INTERVAL 4 DAY);

-- Cartes web
INSERT INTO CONFIGURATION_MAP (id_serveur, est_active, mode_rendu, afficher_joueurs, afficher_marqueurs, port_map, date_maj) VALUES
    (1, 1, '3D', 1, 1, 26565, NOW() - INTERVAL 5 DAY),
    (2, 1, '2D', 1, 0, 26566, NOW() - INTERVAL 2 DAY);

-- Historique de consommation : une mesure par heure sur 72 h pour les serveurs en ligne
INSERT INTO HISTORIQUE_RESSOURCE (id_serveur, ram_utilisee_mo, cpu_pourcentage, joueurs_en_ligne, date_mesure)
SELECT s.id_serveur,
       ROUND(s.ram_allouee * (0.42 + 0.30 * ABS(SIN(h.n / 6 + s.id_serveur)))),
       ROUND(9 + 38 * ABS(SIN(h.n / 4 + s.id_serveur * 2)), 2),
       ROUND(s.slots_max * 0.55 * ABS(SIN(h.n / 8 + s.id_serveur))),
       NOW() - INTERVAL h.n HOUR
FROM SERVEUR s
CROSS JOIN (
    SELECT u.d + 10 * t.d AS n
    FROM (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
          UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) u
    CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7) t
) h
WHERE s.statut = 'EN_LIGNE' AND h.n < 72;

-- Connexions de joueurs (date_deconnexion NULL = toujours en ligne)
INSERT INTO CONNEXION_JOUEUR (id_serveur, pseudo_mc, uuid_mc, adresse_ip, date_connexion, date_deconnexion, motif_deconnexion) VALUES
    (1, 'NovaStar',       '6f3c1a52-8d4e-4a1b-9b6e-2c7d1e0f4a11', '192.0.2.10', NOW() - INTERVAL 3 HOUR,   NULL, NULL),
    (1, 'OrionTheHunter', '0b8e7d41-5c2a-4f63-8e19-7a4d2c9b1e22', '192.0.2.11', NOW() - INTERVAL 2 HOUR,   NULL, NULL),
    (1, 'LyraSky',        '9a1d3e7f-2b4c-4d85-a6f0-1e2b3c4d5e33', '192.0.2.12', NOW() - INTERVAL 50 MINUTE, NULL, NULL),
    (1, 'Pixel_Comet',    '4c5d6e7f-8a9b-4c0d-9e1f-2a3b4c5d6e44', '192.0.2.13', NOW() - INTERVAL 1 DAY,  NOW() - INTERVAL 22 HOUR, 'Déconnexion'),
    (1, 'Griefer42',      'd1e2f3a4-b5c6-4d7e-8f90-a1b2c3d4e555', '192.0.2.66', NOW() - INTERVAL 3 DAY,  NOW() - INTERVAL 3 DAY + INTERVAL 20 MINUTE, 'Banni par un opérateur'),
    (2, 'VegaBuilds',     '7e8f9a0b-1c2d-4e3f-8a4b-5c6d7e8f9a66', '192.0.2.20', NOW() - INTERVAL 4 HOUR,   NULL, NULL),
    (2, 'NovaStar',       '6f3c1a52-8d4e-4a1b-9b6e-2c7d1e0f4a11', '192.0.2.10', NOW() - INTERVAL 2 DAY,  NOW() - INTERVAL 2 DAY + INTERVAL 2 HOUR, 'Déconnexion');

-- Avis sur les serveurs (jamais par le propriétaire)
INSERT INTO AVIS_SERVEUR (id_serveur, id_utilisateur, note, commentaire, date_avis) VALUES
    (1, 6, 5, 'Communauté adorable et serveur très stable, même à 12 joueurs.', NOW() - INTERVAL 8 DAY),
    (1, 5, 4, 'Super ambiance. Quelques ralentissements le week-end.',          NOW() - INTERVAL 5 DAY),
    (1, 3, 5, 'Les villes des joueurs sont superbes, bravo !',                  NOW() - INTERVAL 2 DAY),
    (2, 4, 4, 'Parfait pour construire tranquillement.',                         NOW() - INTERVAL 6 DAY),
    (2, 1, 5, 'Concours très bien organisés.',                                   NOW() - INTERVAL 3 DAY),
    (4, 3, 4, 'Hâte que la maintenance se termine !',                            NOW() - INTERVAL 1 DAY);

-- -----------------------------------------------------------------------------
-- Ressources communautaires
-- -----------------------------------------------------------------------------
INSERT INTO RESSOURCE (id_ressource, id_auteur, nom_ressource, type_ressource, version_compatible, resume, description,
                       nb_telechargements, chemin_fichier, statut_moderation, date_publication) VALUES
    (1, 3, 'Stellar Tools', 'MOD', '1.21.1',
        'Outils de construction rapides : règle, miroir et remplissage de zones.',
        'Stellar Tools ajoute une baguette de construction, une règle de mesure et un mode miroir pour bâtir des structures symétriques en quelques secondes. Compatible Fabric, aucune dépendance.',
        1240, '', 'VALIDE', NOW() - INTERVAL 40 DAY),
    (2, 3, 'Orbital Minimap', 'MOD', '1.20.4',
        'Mini-carte légère avec points de passage partagés entre amis.',
        'Une mini-carte circulaire inspirée des cadrans d''astronomie : points de passage, mode nuit, affichage des grottes et partage des repères avec les membres du serveur.',
        860, '', 'VALIDE', NOW() - INTERVAL 33 DAY),
    (3, 3, 'BootesEssentials', 'PLUGIN', '1.21.4',
        'Commandes indispensables pour serveurs Paper : /home, /spawn, /tpa, kits.',
        'BootesEssentials regroupe les commandes de base d''un serveur communautaire : résidences, téléportation sur demande, kits de départ, messages privés et annonces planifiées.',
        3120, '', 'VALIDE', NOW() - INTERVAL 28 DAY),
    (4, 5, 'Nebula Economy', 'PLUGIN', '1.21.4',
        'Économie entre joueurs : boutiques, enchères et banque.',
        'Système économique complet : monnaie configurable, boutiques par panneaux, hôtel des ventes et intérêts bancaires. Stockage local, aucune base externe requise.',
        540, '', 'VALIDE', NOW() - INTERVAL 21 DAY),
    (5, 3, 'Constellation Pack', 'MOD_PACK', '1.20.1',
        'Modpack technologie et exploration spatiale (85 mods).',
        'Constellation Pack réunit des mods de technologie, d''automatisation et d''exploration spatiale autour d''une progression guidée par quêtes. Recommandé : 4 Go de RAM.',
        2210, '', 'VALIDE', NOW() - INTERVAL 18 DAY),
    (6, 2, 'Vide Spatial Skyblock', 'MOD_PACK', '1.21.1',
        'Skyblock dans le vide : partir de rien pour bâtir un monde.',
        'Fidèle au slogan Boötes « Transformer le vide en lieu de vie » : vous démarrez sur un astéroïde et devez recréer chaque ressource. 40 mods, quêtes intégrées.',
        410, '', 'VALIDE', NOW() - INTERVAL 7 DAY),
    (7, 6, 'Lunar Builder', 'MOD', '1.21.4',
        'Blocs décoratifs sur le thème de la Lune et des étoiles.',
        'Plus de 120 blocs décoratifs (régolithe, verre stellaire, lanternes célestes) pour donner une ambiance spatiale à vos constructions.',
        0, '', 'EN_ATTENTE', NOW() - INTERVAL 1 DAY),
    (8, 7, 'FreeOps Hack', 'PLUGIN', '1.21.4',
        'Donne les droits opérateur à tout le monde (ressource suspendue).',
        'Ressource suspendue par la modération suite à plusieurs signalements pour code malveillant.',
        12, '', 'SUSPENDU', NOW() - INTERVAL 10 DAY);

-- Versions publiées (lignes de démonstration : archives absentes du disque)
INSERT INTO FICHIER_RESSOURCE (id_ressource, numero_version, version_mc, nom_fichier, chemin_fichier, taille_octets, changelog, nb_telechargements_version, date_ajout) VALUES
    (1, '2.0.0', '1.21.1', 'stellar-tools-2.0.0.jar', 'res-1-demo-a.jar', 245760, 'Première version publique.', 830, NOW() - INTERVAL 40 DAY),
    (1, '2.1.0', '1.21.1', 'stellar-tools-2.1.0.jar', 'res-1-demo-b.jar', 251904, 'Ajout du mode miroir et corrections.', 410, NOW() - INTERVAL 12 DAY),
    (2, '1.4.2', '1.20.4', 'orbital-minimap-1.4.2.jar', 'res-2-demo-a.jar', 532480, 'Partage des repères entre amis.', 860, NOW() - INTERVAL 33 DAY),
    (3, '3.2.0', '1.21.4', 'BootesEssentials-3.2.0.jar', 'res-3-demo-a.jar', 1048576, 'Compatibilité Paper 1.21.4.', 3120, NOW() - INTERVAL 28 DAY),
    (4, '1.0.3', '1.21.4', 'NebulaEconomy-1.0.3.jar', 'res-4-demo-a.jar', 393216, 'Hôtel des ventes.', 540, NOW() - INTERVAL 21 DAY),
    (5, '1.2.0', '1.20.1', 'constellation-pack-1.2.0.zip', 'res-5-demo-a.zip', 314572800, 'Ajout de 12 quêtes et équilibrage.', 2210, NOW() - INTERVAL 18 DAY),
    (6, '0.9.0', '1.21.1', 'vide-spatial-skyblock-0.9.0.zip', 'res-6-demo-a.zip', 157286400, 'Version bêta.', 410, NOW() - INTERVAL 7 DAY),
    (7, '1.0.0', '1.21.4', 'lunar-builder-1.0.0.jar', 'res-7-demo-a.jar', 3145728, 'Version initiale.', 0, NOW() - INTERVAL 1 DAY);

-- Avis sur les ressources (jamais par l'auteur)
INSERT INTO AVIS_RESSOURCE (id_ressource, id_utilisateur, note, commentaire, date_avis) VALUES
    (1, 4, 5, 'Indispensable pour construire vite, merci !',              NOW() - INTERVAL 20 DAY),
    (1, 5, 4, 'Très pratique, il manque juste une touche d''annulation.', NOW() - INTERVAL 9 DAY),
    (2, 6, 4, 'Jolie mini-carte, légère et lisible.',                     NOW() - INTERVAL 15 DAY),
    (3, 4, 5, 'Installé sur Nébuleuse Survie, rien à redire.',            NOW() - INTERVAL 14 DAY),
    (3, 6, 5, 'Simple à configurer.',                                     NOW() - INTERVAL 6 DAY),
    (4, 4, 4, 'Bonne économie, documentation à compléter.',               NOW() - INTERVAL 10 DAY),
    (5, 2, 5, 'Le meilleur pack technologique du catalogue.',             NOW() - INTERVAL 8 DAY),
    (6, 3, 4, 'Concept génial, encore quelques bugs de bêta.',            NOW() - INTERVAL 3 DAY);

-- Ressources installées sur les serveurs
INSERT INTO INSTALLER_RESSOURCE (id_serveur, id_ressource, date_installation) VALUES
    (1, 3, NOW() - INTERVAL 27 DAY),
    (1, 4, NOW() - INTERVAL 20 DAY),
    (2, 1, NOW() - INTERVAL 25 DAY),
    (4, 5, NOW() - INTERVAL 14 DAY);

-- -----------------------------------------------------------------------------
-- Modération, amis, support, journal d'audit
-- -----------------------------------------------------------------------------
INSERT INTO SIGNALEMENT (id_auteur_signalement, type_cible, id_cible, motif, description, statut, date_signalement) VALUES
    (4, 'RESSOURCE',   8, 'MALWARE',    'Le plugin ajoute un compte opérateur caché au démarrage du serveur.', 'TRAITE',     NOW() - INTERVAL 9 DAY),
    (6, 'UTILISATEUR', 7, 'HARASSMENT', 'Messages insultants répétés dans le chat de Nébuleuse Survie.',      'EN_ATTENTE', NOW() - INTERVAL 2 DAY),
    (5, 'SERVEUR',     3, 'EULA_VIOLATION', 'Vente d''objets contre de l''argent réel annoncée sur le serveur.', 'EN_ATTENTE', NOW() - INTERVAL 1 DAY),
    (6, 'RESSOURCE',   2, 'BROKEN',     'La mini-carte plante au chargement en 1.20.6.',                     'EN_ATTENTE', NOW() - INTERVAL 5 HOUR);

INSERT INTO RELATION_UTILISATEUR (id_demandeur, id_destinataire, statut, date_relation) VALUES
    (4, 5, 'ACCEPTE',    NOW() - INTERVAL 30 DAY),
    (4, 6, 'ACCEPTE',    NOW() - INTERVAL 15 DAY),
    (3, 4, 'EN_ATTENTE', NOW() - INTERVAL 2 DAY),
    (6, 1, 'EN_ATTENTE', NOW() - INTERVAL 1 DAY);

INSERT INTO TICKET_SUPPORT (id_ticket, id_utilisateur, sujet, categorie, statut, date_creation) VALUES
    (1, 4, 'Mon serveur Bouvier Hardcore ne démarre plus', 'TECHNIQUE',   'REPONDU', NOW() - INTERVAL 3 DAY),
    (2, 6, 'Passer à l''offre Premium',                    'FACTURATION', 'OUVERT',  NOW() - INTERVAL 1 DAY),
    (3, 5, 'Suppression de mes anciennes données',         'RGPD',        'FERME',   NOW() - INTERVAL 12 DAY);

INSERT INTO MESSAGE_TICKET (id_ticket, id_auteur, contenu, date_envoi) VALUES
    (1, 4, 'Bonjour, depuis la dernière sauvegarde mon serveur reste bloqué au démarrage.', NOW() - INTERVAL 3 DAY),
    (1, 2, 'Bonjour Nova, le quota RAM de votre compte est atteint : arrêtez Nébuleuse Survie ou réduisez sa mémoire avant de démarrer Bouvier Hardcore.', NOW() - INTERVAL 2 DAY),
    (2, 6, 'Bonjour, comment obtenir plus de RAM pour mon serveur d''aventure ?', NOW() - INTERVAL 1 DAY),
    (3, 5, 'Merci de supprimer les journaux de connexion de mon ancien serveur.', NOW() - INTERVAL 12 DAY),
    (3, 2, 'C''est fait, les journaux ont été purgés. Bonne journée !', NOW() - INTERVAL 11 DAY);

INSERT INTO LOG_SYSTEME (id_utilisateur, niveau, action, details, adresse_ip, date_log) VALUES
    (1, 'CRITICAL', 'PROMOTE_ADMIN',     'Arcturus promu administrateur.',                         '127.0.0.1', NOW() - INTERVAL 50 DAY),
    (2, 'WARNING',  'SUSPEND_RESOURCE',  'Ressource #8 « FreeOps Hack » suspendue (malware).',     '127.0.0.1', NOW() - INTERVAL 9 DAY),
    (2, 'WARNING',  'BAN_USER',          'Compte Mizar banni suite à des signalements répétés.',    '127.0.0.1', NOW() - INTERVAL 8 DAY),
    (2, 'INFO',     'RESOLVE_REPORT',    'Signalement #1 traité.',                                 '127.0.0.1', NOW() - INTERVAL 8 DAY),
    (1, 'INFO',     'RGPD_LOG_PURGE',    'Purge des journaux de connexion de plus de 12 mois.',    '127.0.0.1', NOW() - INTERVAL 2 DAY);
