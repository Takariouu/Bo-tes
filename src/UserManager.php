<?php

declare(strict_types=1);

namespace Bootes\Src;

use PDO;
use PDOException;

/**
 * Cycle de vie des comptes : suppression RGPD complète et compte système anonyme.
 */
final class UserManager
{
    public const ANONYMOUS_PSEUDO = 'Anonyme_RGPD';
    public const ANONYMOUS_EMAIL  = 'anonyme-rgpd@bootes.local';

    public function __construct(
        private readonly PDO $db,
        private readonly ServerManager $serverManager,
        private readonly ResourceManager $resourceManager
    ) {
    }

    /**
     * Supprime définitivement un compte et ses données personnelles (droit à l'oubli).
     * Les ressources publiées sont supprimées ou rattachées au compte anonyme verrouillé ;
     * les fichiers (instances, sauvegardes, archives) ne sont effacés qu'après validation de la transaction.
     */
    public function deleteAccount(int $userId, bool $deleteResources): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $serverStmt = $this->db->prepare('SELECT id_serveur FROM SERVEUR WHERE id_proprietaire = :uid');
        $serverStmt->execute([':uid' => $userId]);
        $serverIds = array_map('intval', $serverStmt->fetchAll(PDO::FETCH_COLUMN));

        $backupFiles = [];
        foreach ($serverIds as $serverId) {
            $backupFiles[$serverId] = array_column($this->serverManager->getBackups($serverId), 'nom_fichier');
        }

        $resourceIds   = array_map(static fn (array $r): int => (int) $r['id_ressource'], $this->resourceManager->getResourcesByUser($userId));
        $resourceFiles = [];
        if ($deleteResources) {
            foreach ($resourceIds as $resourceId) {
                $resourceFiles = array_merge($resourceFiles, $this->resourceManager->getStoredFilePaths($resourceId));
            }
        }

        $this->db->beginTransaction();

        try {
            foreach ($serverIds as $serverId) {
                if (!$this->serverManager->deleteServer($serverId, false)) {
                    throw new PDOException(sprintf('Suppression du serveur #%d impossible.', $serverId));
                }
            }

            $anonymousId = $this->getOrCreateAnonymousAccountId();

            if ($deleteResources) {
                foreach ($resourceIds as $resourceId) {
                    if (!$this->resourceManager->deleteResource($resourceId, false)) {
                        throw new PDOException(sprintf('Suppression de la ressource #%d impossible.', $resourceId));
                    }
                }
            } else {
                $this->resourceManager->anonymizeUserResources($userId, $anonymousId);
            }

            $statements = [
                'DELETE FROM AVIS_RESSOURCE WHERE id_utilisateur = :uid',
                'DELETE FROM AVIS_SERVEUR WHERE id_utilisateur = :uid',
                'DELETE FROM MESSAGE_TICKET WHERE id_ticket IN (SELECT id_ticket FROM TICKET_SUPPORT WHERE id_utilisateur = :uid)',
                'DELETE FROM TICKET_SUPPORT WHERE id_utilisateur = :uid',
                "DELETE FROM SIGNALEMENT WHERE type_cible = 'UTILISATEUR' AND id_cible = :uid",
                'DELETE FROM ADMINISTRER_SERVEUR WHERE id_utilisateur = :uid',
            ];
            foreach ($statements as $sql) {
                $this->db->prepare($sql)->execute([':uid' => $userId]);
            }

            // Les contributions laissées chez des tiers sont conservées mais dissociées de l'identité.
            $this->db->prepare('UPDATE MESSAGE_TICKET SET id_auteur = :anon WHERE id_auteur = :uid')
                     ->execute([':anon' => $anonymousId, ':uid' => $userId]);
            $this->db->prepare('UPDATE SIGNALEMENT SET id_auteur_signalement = :anon WHERE id_auteur_signalement = :uid')
                     ->execute([':anon' => $anonymousId, ':uid' => $userId]);
            $this->db->prepare('UPDATE LOG_SYSTEME SET id_utilisateur = :anon WHERE id_utilisateur = :uid')
                     ->execute([':anon' => $anonymousId, ':uid' => $userId]);

            $this->db->prepare('DELETE FROM RELATION_UTILISATEUR WHERE id_demandeur = :u1 OR id_destinataire = :u2')
                     ->execute([':u1' => $userId, ':u2' => $userId]);

            $delete = $this->db->prepare('DELETE FROM UTILISATEUR WHERE id_utilisateur = :uid');
            $delete->execute([':uid' => $userId]);

            if ($delete->rowCount() === 0) {
                throw new PDOException('Compte introuvable.');
            }

            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log(sprintf('[Boötes][UserManager] Suppression du compte #%d annulée : %s', $userId, $e->getMessage()));
            return false;
        }

        foreach ($backupFiles as $serverId => $files) {
            $this->serverManager->purgeServerFiles($serverId, $files);
        }
        $this->resourceManager->purgeStoredFiles($resourceFiles);

        return true;
    }

    /**
     * Retourne le compte système qui hérite des ressources anonymisées, en le créant verrouillé si besoin.
     * Il est identifié par son pseudo ET son e-mail réservés (refusés à l'inscription) et un quota RAM nul,
     * afin qu'un compte ordinaire ne puisse jamais être confondu avec lui.
     */
    public function getOrCreateAnonymousAccountId(): int
    {
        $find = $this->db->prepare('
            SELECT id_utilisateur
            FROM UTILISATEUR
            WHERE pseudo = :pseudo AND email = :email AND max_ram_allouable = 0
            LIMIT 1
        ');
        $find->execute([':pseudo' => self::ANONYMOUS_PSEUDO, ':email' => self::ANONYMOUS_EMAIL]);
        $existing = $find->fetchColumn();

        if ($existing !== false) {
            $this->db->prepare('UPDATE UTILISATEUR SET est_banni = 1 WHERE id_utilisateur = :id')
                     ->execute([':id' => (int) $existing]);
            return (int) $existing;
        }

        $create = $this->db->prepare("
            INSERT INTO UTILISATEUR (pseudo, email, mot_de_passe, role, type_compte, max_ram_allouable, est_banni, date_inscription)
            VALUES (:pseudo, :email, :hash, 'UTILISATEUR', 'STANDARD', 0, 1, NOW())
        ");
        $create->execute([
            ':pseudo' => self::ANONYMOUS_PSEUDO,
            ':email'  => self::ANONYMOUS_EMAIL,
            ':hash'   => Auth::hashPassword(bin2hex(random_bytes(32))),
        ]);

        return (int) $this->db->lastInsertId();
    }
}
