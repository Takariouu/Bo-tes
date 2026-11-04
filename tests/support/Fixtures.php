<?php

declare(strict_types=1);

namespace Bootes\Tests;

use PDO;

/**
 * Jeu de données de test : comptes de chaque rôle, serveurs publics/privés,
 * co-administrateurs de niveaux différents, ressources validées et suspendues.
 */
final class Fixtures
{
    public const PASSWORD = 'Motdepasse-Test-2026';

    public const ALICE   = 1; // Utilisateur Standard, propriétaire des serveurs 1 (public) et 2 (privé)
    public const BOB     = 2; // Co-admin LECTURE du serveur 1
    public const CAROL   = 3; // Administratrice
    public const DAVE    = 4; // Super-Administrateur
    public const EVE     = 5; // Utilisatrice ordinaire
    public const MALLORY = 6; // Utilisateur malveillant, auteur d'une ressource suspendue
    public const FRANK   = 7; // Co-admin COMPLET du serveur 1

    public static function seed(string $databaseFile): PDO
    {
        // Sous Windows, le serveur peut encore tenir le fichier quelques millisecondes après sa réponse.
        for ($attempt = 0; is_file($databaseFile) && !@unlink($databaseFile); $attempt++) {
            if ($attempt >= 50) {
                throw new \RuntimeException('Impossible de réinitialiser la base de test : ' . $databaseFile);
            }
            usleep(100000);
        }

        $pdo = TestPdo::open($databaseFile);
        $pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));

        // Hash calculé une seule fois avec les paramètres de production (aucun re-hachage à la connexion).
        static $hash = null;
        $hash ??= password_hash(self::PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2]);

        $users = [
            [self::ALICE,   'alice',   'alice@example.test',   'UTILISATEUR', 'STANDARD', 2048],
            [self::BOB,     'bob',     'bob@example.test',     'UTILISATEUR', 'STANDARD', 2048],
            [self::CAROL,   'carol',   'carol@example.test',   'ADMIN',       'PREMIUM',  6144],
            [self::DAVE,    'dave',    'dave@example.test',    'SUPER_ADMIN', 'PREMIUM',  6144],
            [self::EVE,     'eve',     'eve@example.test',     'UTILISATEUR', 'STANDARD', 2048],
            [self::MALLORY, 'mallory', 'mallory@example.test', 'UTILISATEUR', 'STANDARD', 2048],
            [self::FRANK,   'frank',   'frank@example.test',   'UTILISATEUR', 'STANDARD', 2048],
        ];
        $insUser = $pdo->prepare('
            INSERT INTO UTILISATEUR (id_utilisateur, pseudo, email, mot_de_passe, role, type_compte, max_ram_allouable, est_banni, date_inscription)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ');
        foreach ($users as [$id, $pseudo, $email, $role, $tier, $ram]) {
            $insUser->execute([$id, $pseudo, $email, $hash, $role, $tier, $ram]);
        }

        $insServer = $pdo->prepare("
            INSERT INTO SERVEUR (id_serveur, id_proprietaire, nom_serveur, adresse_ip, port, version_mc, type_loader, mode_jeu,
                                 statut, joueurs_connectes, slots_max, ram_allouee, description, est_public, date_creation)
            VALUES (?, ?, ?, 'play.bootes.local', ?, '1.21.4', 'VANILLA', 'SURVIE', 'HORS_LIGNE', 0, 20, 1024, ?, ?, NOW())
        ");
        $insServer->execute([1, self::ALICE, 'Survie Alice', 25565, 'Serveur public de test', 1]);
        $insServer->execute([2, self::ALICE, 'Prive Alice', 25566, 'Serveur privé de test', 0]);
        $insServer->execute([3, self::DAVE, 'Serveur Dave', 25567, 'Serveur du super-admin', 1]);

        $pdo->exec("
            INSERT INTO ADMINISTRER_SERVEUR (id_serveur, id_utilisateur, niveau_permission, date_attribution) VALUES
                (1, " . self::BOB . ", 'LECTURE', NOW()),
                (1, " . self::FRANK . ", 'COMPLET', NOW())
        ");

        $pdo->exec("
            INSERT INTO BACKUP (id_backup, id_serveur, nom_backup, nom_fichier, chemin_fichier, taille_octets, date_creation)
            VALUES (1, 1, 'Sauvegarde de test', 'server_1_backup_test.tar.gz', '/backups/server_1_backup_test.tar.gz', 12, NOW())
        ");

        $insRes = $pdo->prepare("
            INSERT INTO RESSOURCE (id_ressource, id_auteur, nom_ressource, type_ressource, version_compatible, resume, description,
                                   nb_telechargements, chemin_fichier, statut_moderation, date_publication)
            VALUES (?, ?, ?, ?, '1.21.4', 'Résumé de la ressource de test', 'Description détaillée de la ressource de test.', 0, '', ?, NOW())
        ");
        $insRes->execute([1, self::ALICE, 'Alpha Mod', 'MOD', 'VALIDE']);
        $insRes->execute([2, self::ALICE, 'Pack Beta', 'MOD_PACK', 'VALIDE']);
        $insRes->execute([3, self::MALLORY, 'Mod Suspendu', 'MOD', 'SUSPENDU']);

        $pdo->exec("
            INSERT INTO SIGNALEMENT (id_signalement, id_auteur_signalement, type_cible, id_cible, motif, description, statut, date_signalement) VALUES
                (1, " . self::ALICE . ", 'RESSOURCE', 3, 'Malware', 'Archive suspecte signalée par un joueur.', 'EN_ATTENTE', NOW()),
                (2, " . self::MALLORY . ", 'UTILISATEUR', " . self::DAVE . ", 'Abus', 'Signalement abusif visant le super-admin.', 'EN_ATTENTE', NOW())
        ");

        $pdo->exec("
            INSERT INTO RELATION_UTILISATEUR (id_demandeur, id_destinataire, statut, date_relation)
            VALUES (" . self::ALICE . ", " . self::BOB . ", 'ACCEPTE', NOW())
        ");

        $pdo->exec("
            INSERT INTO TICKET_SUPPORT (id_ticket, id_utilisateur, sujet, categorie, statut, date_creation)
            VALUES (1, " . self::ALICE . ", 'Serveur bloqué', 'TECHNIQUE', 'OUVERT', NOW())
        ");
        $pdo->exec("
            INSERT INTO MESSAGE_TICKET (id_ticket, id_auteur, contenu, date_envoi)
            VALUES (1, " . self::ALICE . ", 'Mon serveur ne démarre plus depuis hier.', NOW())
        ");

        return $pdo;
    }
}
