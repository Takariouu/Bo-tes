<?php

declare(strict_types=1);

namespace Bootes\Src;

use Bootes\Config\AppConfig;
use PDO;
use PDOException;
use InvalidArgumentException;
use ZipArchive;

final class ResourceManager
{
    public const ALLOWED_ARCHIVE_EXTENSIONS = [
        'jar'    => 'application/java-archive',
        'zip'    => 'application/zip',
        'mrpack' => 'application/zip',
    ];
    public const MAX_ARCHIVE_SIZE_BYTES = 104857600; // 100 Mo

    public const TYPE_MOD     = 'MOD';
    public const TYPE_PLUGIN  = 'PLUGIN';
    public const TYPE_MODPACK = 'MOD_PACK';

    public const ALLOWED_TYPES = [
        self::TYPE_MOD     => 'Mod',
        self::TYPE_PLUGIN  => 'Plugin',
        self::TYPE_MODPACK => 'Modpack',
    ];

    public const STATUS_EN_ATTENTE = 'EN_ATTENTE';
    public const STATUS_VALIDE     = 'VALIDE';
    public const STATUS_SUSPENDU   = 'SUSPENDU';

    public const ALLOWED_STATUSES = [
        self::STATUS_EN_ATTENTE => 'En attente de validation',
        self::STATUS_VALIDE     => 'Validé (Public)',
        self::STATUS_SUSPENDU   => 'Suspendu (Modération)',
    ];

    /** Catégories du catalogue, stockées en liste « ,technologie,magie, » (filtrable par LIKE). */
    public const CATEGORIES = [
        'aventure'       => 'Aventure',
        'technologie'    => 'Technologie',
        'magie'          => 'Magie',
        'optimisation'   => 'Optimisation',
        'utilitaire'     => 'Utilitaire',
        'construction'   => 'Construction',
        'carte'          => 'Carte et navigation',
        'economie'       => 'Économie',
        'administration' => 'Administration',
        'mini-jeux'      => 'Mini-jeux',
        'monde'          => 'Génération de monde',
        'bibliotheque'   => 'Bibliothèque',
    ];
    public const MAX_CATEGORIES = 3;

    /** Chargeurs proposés selon le type : mods (Fabric, Forge...) ou plugins (Paper, Spigot...). */
    public const LOADERS = [
        'FABRIC'   => 'Fabric',
        'FORGE'    => 'Forge',
        'NEOFORGE' => 'NeoForge',
        'QUILT'    => 'Quilt',
        'PAPER'    => 'Paper',
        'SPIGOT'   => 'Spigot',
        'PURPUR'   => 'Purpur',
        'VELOCITY' => 'Velocity',
    ];

    public const SUPPORTED_MC_VERSIONS = [
        '1.21.4',
        '1.21.1',
        '1.20.4',
        '1.20.1',
        '1.19.4',
        '1.18.2',
        '1.16.5',
        '1.12.2',
    ];

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Normalise le type de ressource (accepte MODPACK ou MOD_PACK) et vérifie sa validité.
     */
    /** Enregistre les catégories (liste encodée par encodeCategories) et le chargeur d'une ressource. */
    public function updateClassification(int $resourceId, string $categories, ?string $loader): void
    {
        $loader = $loader !== null && isset(self::LOADERS[$loader]) ? $loader : null;
        $this->db->prepare('UPDATE RESSOURCE SET categories = :categories, chargeur = :loader WHERE id_ressource = :id')
                 ->execute([':categories' => $categories, ':loader' => $loader, ':id' => $resourceId]);
    }

    /** @param array<int, mixed> $categories */
    public static function encodeCategories(array $categories): string
    {
        $valid = array_values(array_intersect(array_keys(self::CATEGORIES), array_map('strval', $categories)));

        return $valid === [] ? '' : ',' . implode(',', array_slice($valid, 0, self::MAX_CATEGORIES)) . ',';
    }

    /** @return array<int, string> */
    public static function decodeCategories(?string $raw): array
    {
        $values = explode(',', (string) $raw);

        return array_values(array_intersect(array_keys(self::CATEGORIES), $values));
    }

    public static function normalizeType(string $rawType): string
    {
        $upper = strtoupper(trim($rawType));
        if ($upper === 'MODPACK') {
            return self::TYPE_MODPACK;
        }

        if (!array_key_exists($upper, self::ALLOWED_TYPES)) {
            throw new InvalidArgumentException(sprintf('Type de ressource invalide : %s', $rawType));
        }

        return $upper;
    }

    /**
     * Retourne le libellé lisible d'un type de ressource.
     */
    public static function getTypeLabel(string $type): string
    {
        try {
            $normalized = self::normalizeType($type);
            return self::ALLOWED_TYPES[$normalized];
        } catch (InvalidArgumentException) {
            return 'Ressource';
        }
    }

    /**
     * Vérifie si un utilisateur possède les droits d'édition/modération sur une ressource.
     */
    public function canManageResource(int $userId, int $resourceId): bool
    {
        if ($userId <= 0 || $resourceId <= 0) {
            return false;
        }

        if (Auth::isLogged() && Auth::getUserId() === $userId && Auth::isAdmin()) {
            return true;
        }

        $stmt = $this->db->prepare('
            SELECT id_auteur
            FROM RESSOURCE
            WHERE id_ressource = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $resourceId]);
        $authorId = $stmt->fetchColumn();

        return $authorId !== false && (int) $authorId === $userId;
    }

    /**
     * Crée une nouvelle ressource (MOD, PLUGIN ou MOD_PACK) et retourne son identifiant.
     */
    public function createResource(
        int $authorId,
        string $name,
        string $type,
        string $mcVersion,
        string $summary,
        string $description,
        ?string $filePath = null
    ): ?int {
        $normalizedType = self::normalizeType($type);
        $cleanName      = Security::validateString($name, 3, 100);
        $cleanSummary   = Security::validateString($summary, 10, 255);
        $cleanDesc      = Security::validateMultiline($description, 20, 10000);
        $cleanVersion   = in_array($mcVersion, self::SUPPORTED_MC_VERSIONS, true)
            ? $mcVersion
            : self::SUPPORTED_MC_VERSIONS[0];

        if ($authorId <= 0 || $cleanName === null || $cleanSummary === null || $cleanDesc === null) {
            return null;
        }

        $sql = '
            INSERT INTO RESSOURCE (
                id_auteur,
                nom_ressource,
                type_ressource,
                version_compatible,
                resume,
                description,
                nb_telechargements,
                chemin_fichier,
                statut_moderation,
                date_publication
            ) VALUES (
                :id_auteur,
                :nom,
                :type,
                :version_mc,
                :resume,
                :description,
                0,
                :chemin_fichier,
                :statut,
                NOW()
            )
        ';

        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute([
            ':id_auteur'      => $authorId,
            ':nom'            => $cleanName,
            ':type'           => $normalizedType,
            ':version_mc'     => $cleanVersion,
            ':resume'         => $cleanSummary,
            ':description'    => $cleanDesc,
            ':chemin_fichier' => $filePath ?? '',
            ':statut'         => self::STATUS_VALIDE,
        ]);

        if (!$success) {
            return null;
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Récupère les informations complètes d'une ressource par son ID.
     */
    public function getResourceById(int $resourceId): ?array
    {
        if ($resourceId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT 
                r.id_ressource,
                r.id_auteur,
                r.nom_ressource,
                r.type_ressource,
                r.version_compatible,
                r.resume,
                r.description,
                r.nb_telechargements,
                r.chemin_fichier,
                r.statut_moderation,
                r.date_publication,
                r.categories,
                r.chargeur,
                u.pseudo AS auteur_pseudo,
                u.type_compte AS auteur_offre,
                COALESCE(AVG(ar.note), 0) AS note_moyenne,
                COUNT(DISTINCT ar.id_utilisateur) AS nb_avis,
                COUNT(DISTINCT fr.id_fichier) AS nb_versions
            FROM RESSOURCE r
            INNER JOIN UTILISATEUR u ON u.id_utilisateur = r.id_auteur
            LEFT JOIN AVIS_RESSOURCE ar ON ar.id_ressource = r.id_ressource
            LEFT JOIN FICHIER_RESSOURCE fr ON fr.id_ressource = r.id_ressource
            WHERE r.id_ressource = :id
            GROUP BY 
                r.id_ressource,
                r.id_auteur,
                r.categories,
                r.chargeur,
                u.type_compte,
                r.nom_ressource,
                r.type_ressource,
                r.version_compatible,
                r.resume,
                r.description,
                r.nb_telechargements,
                r.chemin_fichier,
                r.statut_moderation,
                r.date_publication,
                u.pseudo
            LIMIT 1
        ');
        $stmt->execute([':id' => $resourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Liste toutes les ressources créées par un utilisateur, avec filtrage optionnel par type.
     */
    public function getResourcesByUser(int $userId, ?string $typeFilter = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $params = [':user_id' => $userId];
        $typeCondition = '';

        if ($typeFilter !== null && $typeFilter !== '') {
            $normalized = self::normalizeType($typeFilter);
            $typeCondition = 'AND r.type_ressource = :type_res';
            $params[':type_res'] = $normalized;
        }

        $sql = "
            SELECT 
                r.id_ressource,
                r.nom_ressource,
                r.type_ressource,
                r.version_compatible,
                r.resume,
                r.nb_telechargements,
                r.statut_moderation,
                r.date_publication,
                COALESCE(AVG(ar.note), 0) AS note_moyenne,
                COUNT(DISTINCT ar.id_utilisateur) AS nb_avis,
                COUNT(DISTINCT fr.id_fichier) AS nb_versions
            FROM RESSOURCE r
            LEFT JOIN AVIS_RESSOURCE ar ON ar.id_ressource = r.id_ressource
            LEFT JOIN FICHIER_RESSOURCE fr ON fr.id_ressource = r.id_ressource
            WHERE r.id_auteur = :user_id {$typeCondition}
            GROUP BY 
                r.id_ressource,
                r.nom_ressource,
                r.type_ressource,
                r.version_compatible,
                r.resume,
                r.nb_telechargements,
                r.statut_moderation,
                r.date_publication
            ORDER BY r.date_publication DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Met à jour les métadonnées d'une ressource existante.
     */
    public function updateResource(
        int $resourceId,
        string $name,
        string $type,
        string $mcVersion,
        string $summary,
        string $description,
        ?string $moderationStatus = null
    ): bool {
        $normalizedType = self::normalizeType($type);
        $cleanName      = Security::validateString($name, 3, 100);
        $cleanSummary   = Security::validateString($summary, 10, 255);
        $cleanDesc      = Security::validateMultiline($description, 20, 10000);
        $cleanVersion   = in_array($mcVersion, self::SUPPORTED_MC_VERSIONS, true)
            ? $mcVersion
            : self::SUPPORTED_MC_VERSIONS[0];

        if ($resourceId <= 0 || $cleanName === null || $cleanSummary === null || $cleanDesc === null) {
            return false;
        }

        $statusClause = '';
        $params = [
            ':id'          => $resourceId,
            ':nom'         => $cleanName,
            ':type'        => $normalizedType,
            ':version_mc'  => $cleanVersion,
            ':resume'      => $cleanSummary,
            ':description' => $cleanDesc,
        ];

        if ($moderationStatus !== null && array_key_exists($moderationStatus, self::ALLOWED_STATUSES)) {
            $statusClause = ', statut_moderation = :statut';
            $params[':statut'] = $moderationStatus;
        }

        $sql = "
            UPDATE RESSOURCE
            SET nom_ressource = :nom,
                type_ressource = :type,
                version_compatible = :version_mc,
                resume = :resume,
                description = :description
                {$statusClause}
            WHERE id_ressource = :id
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Une ressource n'est visible et installable publiquement qu'une fois validée par la modération.
     *
     * @param array<string, mixed> $resource
     */
    public static function isPubliclyAvailable(array $resource): bool
    {
        return ($resource['statut_moderation'] ?? '') === self::STATUS_VALIDE;
    }

    /**
     * Supprime définitivement une ressource et ses dépendances (fichiers versionnés, avis, installations).
     * Si $purgeFiles est faux (transaction englobante), l'appelant efface les archives après validation.
     */
    public function deleteResource(int $resourceId, bool $purgeFiles = true): bool
    {
        if ($resourceId <= 0) {
            return false;
        }

        $storedFiles    = $this->getStoredFilePaths($resourceId);
        $mediaStmt      = $this->db->prepare("SELECT jeton FROM MEDIA WHERE type_cible = 'RESSOURCE' AND id_cible = :id");
        $mediaStmt->execute([':id' => $resourceId]);
        $mediaTokens    = array_map('strval', $mediaStmt->fetchAll(PDO::FETCH_COLUMN));
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare('DELETE FROM FICHIER_RESSOURCE WHERE id_ressource = :id')
                     ->execute([':id' => $resourceId]);

            $this->db->prepare('DELETE FROM AVIS_RESSOURCE WHERE id_ressource = :id')
                     ->execute([':id' => $resourceId]);

            $this->db->prepare('DELETE FROM INSTALLER_RESSOURCE WHERE id_ressource = :id')
                     ->execute([':id' => $resourceId]);

            $this->db->prepare("DELETE FROM SIGNALEMENT WHERE type_cible = 'RESSOURCE' AND id_cible = :id")
                     ->execute([':id' => $resourceId]);

            // Images (icône, bannière, galerie) et mise en page personnalisée
            foreach (['MEDIA', 'PAGE_PERSONNALISEE'] as $table) {
                $this->db->prepare("DELETE FROM {$table} WHERE type_cible = 'RESSOURCE' AND id_cible = :id")
                         ->execute([':id' => $resourceId]);
            }

            $delStmt = $this->db->prepare('DELETE FROM RESSOURCE WHERE id_ressource = :id');
            $delStmt->execute([':id' => $resourceId]);
            $deleted = $delStmt->rowCount() > 0;

            if ($ownTransaction) {
                $this->db->commit();
            }

            if ($purgeFiles && $deleted) {
                $this->purgeStoredFiles($storedFiles);
                MediaManager::deleteFiles($mediaTokens);
            }

            return $deleted;
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log(sprintf('[Boötes][ResourceManager] Erreur suppression ressource #%d : %s', $resourceId, $e->getMessage()));
            return false;
        }
    }

    /**
     * Anonymise les ressources d'un utilisateur en les rattachant à un compte système/anonyme (RGPD).
     */
    public function anonymizeUserResources(int $userId, int $anonymousUserId): bool
    {
        if ($userId <= 0 || $anonymousUserId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('
            UPDATE RESSOURCE
            SET id_auteur = :anon_id
            WHERE id_auteur = :user_id
        ');

        return $stmt->execute([
            ':anon_id' => $anonymousUserId,
            ':user_id' => $userId,
        ]);
    }

    /**
     * Ajoute une nouvelle version de fichier (.jar, .zip, .mrpack) pour une ressource.
     *
     * @param array<int, string> $mcVersions Liste des versions de Minecraft compatibles
     */
    public function addVersionFile(
        int $resourceId,
        string $versionNumber,
        array $mcVersions,
        string $fileName,
        string $filePath,
        int $fileSize,
        string $changelog = ''
    ): bool {
        if ($resourceId <= 0 || $fileSize <= 0) {
            return false;
        }

        $cleanVerNum = Security::validateString($versionNumber, 1, 32);
        $cleanName   = Security::validateString($fileName, 3, 255);
        $cleanPath   = Security::validateString($filePath, 3, 255);
        $cleanNotes  = Security::validateString($changelog, 0, 2000) ?? '';

        $validMcVersions = array_values(array_intersect($mcVersions, self::SUPPORTED_MC_VERSIONS));
        if ($cleanVerNum === null || $cleanName === null || $cleanPath === null || empty($validMcVersions)) {
            return false;
        }

        $versionsString = implode(', ', $validMcVersions);
        $primaryMcVer   = $validMcVersions[0];

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare('
                INSERT INTO FICHIER_RESSOURCE (
                    id_ressource,
                    numero_version,
                    version_mc,
                    nom_fichier,
                    chemin_fichier,
                    taille_octets,
                    changelog,
                    nb_telechargements_version,
                    date_ajout
                ) VALUES (
                    :res_id,
                    :num_ver,
                    :ver_mc,
                    :nom_fichier,
                    :chemin_fichier,
                    :taille,
                    :changelog,
                    0,
                    NOW()
                )
            ');

            $stmt->execute([
                ':res_id'         => $resourceId,
                ':num_ver'        => $cleanVerNum,
                ':ver_mc'         => $versionsString,
                ':nom_fichier'    => $cleanName,
                ':chemin_fichier' => $cleanPath,
                ':taille'         => $fileSize,
                ':changelog'      => $cleanNotes,
            ]);

            $updRes = $this->db->prepare('
                UPDATE RESSOURCE
                SET chemin_fichier = :chemin,
                    version_compatible = :primary_ver
                WHERE id_ressource = :res_id
            ');
            $updRes->execute([
                ':chemin'      => $cleanPath,
                ':primary_ver' => $primaryMcVer,
                ':res_id'      => $resourceId,
            ]);

            if ($ownTransaction) {
                $this->db->commit();
            }

            return true;
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log(sprintf('[Boötes][ResourceManager] Erreur ajout fichier versionné : %s', $e->getMessage()));
            return false;
        }
    }

    /**
     * Récupère la liste des fichiers versionnés d'une ressource.
     */
    public function getVersionFiles(int $resourceId): array
    {
        if ($resourceId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare('
            SELECT 
                id_fichier,
                id_ressource,
                numero_version,
                version_mc,
                nom_fichier,
                chemin_fichier,
                taille_octets,
                COALESCE(changelog, \'\') AS changelog,
                COALESCE(nb_telechargements_version, 0) AS nb_telechargements_version,
                date_ajout
            FROM FICHIER_RESSOURCE
            WHERE id_ressource = :res_id
            ORDER BY date_ajout DESC, id_fichier DESC
        ');
        $stmt->execute([':res_id' => $resourceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Supprime une version spécifique de fichier associée à une ressource (base et disque).
     */
    public function deleteVersionFile(int $fileId, int $resourceId): bool
    {
        if ($fileId <= 0 || $resourceId <= 0) {
            return false;
        }

        $pathStmt = $this->db->prepare('SELECT chemin_fichier FROM FICHIER_RESSOURCE WHERE id_fichier = :file_id AND id_ressource = :res_id');
        $pathStmt->execute([':file_id' => $fileId, ':res_id' => $resourceId]);
        $storedPath = $pathStmt->fetchColumn();

        $stmt = $this->db->prepare('
            DELETE FROM FICHIER_RESSOURCE
            WHERE id_fichier = :file_id AND id_ressource = :res_id
        ');
        $stmt->execute([
            ':file_id' => $fileId,
            ':res_id'  => $resourceId,
        ]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        if ($storedPath !== false) {
            $this->purgeStoredFiles([(string) $storedPath]);
        }

        return true;
    }

    /**
     * Valide une archive téléversée (taille, extension, signature ZIP réelle) puis la déplace
     * dans le stockage privé sous un nom aléatoire ne contenant aucune donnée fournie par l'utilisateur.
     *
     * @param mixed $upload Entrée de $_FILES
     * @return array{success: bool, message: string, stored_name?: string, original_name?: string, size?: int}
     */
    public function storeUploadedArchive(mixed $upload, int $resourceId): array
    {
        if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['name']) || is_array($upload['error'])) {
            return ['success' => false, 'message' => 'Veuillez sélectionner un fichier (.jar, .zip ou .mrpack) à téléverser.'];
        }

        $error = (int) $upload['error'];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return ['success' => false, 'message' => 'Le fichier dépasse la taille maximale autorisée (100 Mo).'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Le téléversement a échoué, veuillez réessayer.'];
        }

        $tmpPath = (string) $upload['tmp_name'];
        if (!is_uploaded_file($tmpPath)) {
            return ['success' => false, 'message' => 'Fichier téléversé invalide.'];
        }

        $originalName = Security::validateString(basename((string) $upload['name']), 3, 255);
        $extension    = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
        $fileSize     = (int) filesize($tmpPath);

        if ($originalName === null || !array_key_exists($extension, self::ALLOWED_ARCHIVE_EXTENSIONS)) {
            return ['success' => false, 'message' => 'Extension non autorisée. Seuls les fichiers .jar, .zip et .mrpack sont acceptés.'];
        }
        if ($fileSize <= 0 || $fileSize > self::MAX_ARCHIVE_SIZE_BYTES) {
            return ['success' => false, 'message' => 'La taille du fichier est invalide ou dépasse la limite autorisée de 100 Mo.'];
        }
        if (!self::isZipArchive($tmpPath)) {
            return ['success' => false, 'message' => 'Le contenu du fichier n\'est pas une archive ZIP valide (.jar, .zip et .mrpack sont des archives ZIP).'];
        }

        $directory = self::archiveDirectory();
        if (!AppConfig::ensureDirectory($directory)) {
            error_log('[Boötes][ResourceManager] Stockage des archives indisponible : ' . $directory);
            return ['success' => false, 'message' => 'Le stockage des archives est indisponible. Contactez un administrateur.'];
        }

        $storedName = sprintf('res-%d-%s.%s', $resourceId, bin2hex(random_bytes(16)), $extension);
        if (!move_uploaded_file($tmpPath, $directory . '/' . $storedName)) {
            error_log('[Boötes][ResourceManager] Échec move_uploaded_file vers ' . $directory);
            return ['success' => false, 'message' => 'L\'enregistrement du fichier a échoué. Aucune version n\'a été publiée.'];
        }
        @chmod($directory . '/' . $storedName, 0640);

        return [
            'success'       => true,
            'message'       => 'Archive enregistrée.',
            'stored_name'   => $storedName,
            'original_name' => $originalName,
            'size'          => $fileSize,
        ];
    }

    /**
     * Chemin absolu d'une archive du stockage privé à partir du chemin enregistré en base (null si absente).
     */
    public function resolveStoredFile(string $storedPath): ?string
    {
        $fileName = basename($storedPath);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return null;
        }

        $path = self::archiveDirectory() . '/' . $fileName;

        return is_file($path) ? $path : null;
    }

    /**
     * @return array<int, string> Chemins enregistrés des archives d'une ressource
     */
    public function getStoredFilePaths(int $resourceId): array
    {
        $stmt = $this->db->prepare('SELECT chemin_fichier FROM FICHIER_RESSOURCE WHERE id_ressource = :res_id');
        $stmt->execute([':res_id' => $resourceId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array<int, string> $storedPaths
     */
    public function purgeStoredFiles(array $storedPaths): void
    {
        foreach ($storedPaths as $storedPath) {
            $path = $this->resolveStoredFile($storedPath);
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    /**
     * Dernière version publiée d'une ressource (null si aucun fichier).
     */
    public function getLatestVersionFile(int $resourceId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id_fichier, nom_fichier, chemin_fichier
            FROM FICHIER_RESSOURCE
            WHERE id_ressource = :res_id
            ORDER BY date_ajout DESC, id_fichier DESC
            LIMIT 1
        ');
        $stmt->execute([':res_id' => $resourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Comptabilise un téléchargement sur la ressource et sur la version concernée.
     */
    public function registerDownload(int $resourceId, int $fileId): void
    {
        $this->db->prepare('UPDATE RESSOURCE SET nb_telechargements = nb_telechargements + 1 WHERE id_ressource = :id')
                 ->execute([':id' => $resourceId]);
        $this->db->prepare('UPDATE FICHIER_RESSOURCE SET nb_telechargements_version = COALESCE(nb_telechargements_version, 0) + 1 WHERE id_fichier = :fid')
                 ->execute([':fid' => $fileId]);
    }

    /**
     * Installe une ressource validée sur un serveur ; le compteur n'augmente qu'à la première installation.
     *
     * @return array{success: bool, message: string}
     */
    public function installOnServer(int $serverId, int $resourceId): array
    {
        $resource = $this->getResourceById($resourceId);
        if ($resource === null || !self::isPubliclyAvailable($resource)) {
            return ['success' => false, 'message' => 'Cette ressource est introuvable ou n\'est pas validée par la modération.'];
        }

        $existsStmt = $this->db->prepare('SELECT 1 FROM INSTALLER_RESSOURCE WHERE id_serveur = :sid AND id_ressource = :rid LIMIT 1');
        $existsStmt->execute([':sid' => $serverId, ':rid' => $resourceId]);

        if ($existsStmt->fetchColumn() !== false) {
            $this->db->prepare('UPDATE INSTALLER_RESSOURCE SET date_installation = NOW() WHERE id_serveur = :sid AND id_ressource = :rid')
                     ->execute([':sid' => $serverId, ':rid' => $resourceId]);
        } else {
            $this->db->prepare('INSERT INTO INSTALLER_RESSOURCE (id_serveur, id_ressource, date_installation) VALUES (:sid, :rid, NOW())')
                     ->execute([':sid' => $serverId, ':rid' => $resourceId]);
            $this->db->prepare('UPDATE RESSOURCE SET nb_telechargements = nb_telechargements + 1 WHERE id_ressource = :rid')
                     ->execute([':rid' => $resourceId]);
        }

        return ['success' => true, 'message' => 'Ressource installée avec succès sur l\'instance. Redémarrez le serveur pour l\'activer.'];
    }

    private static function archiveDirectory(): string
    {
        return AppConfig::uploadsPath() . '/ressources';
    }

    /**
     * Vérifie la signature ZIP (en-tête local « PK\x03\x04 ») et, si l'extension zip est présente, la structure complète.
     */
    private static function isZipArchive(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $signature = (string) fread($handle, 4);
        fclose($handle);

        if ($signature !== "PK\x03\x04") {
            return false;
        }

        if (!class_exists(ZipArchive::class)) {
            return true;
        }

        $zip    = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            return false;
        }
        $hasEntries = $zip->numFiles > 0;
        $zip->close();

        return $hasEntries;
    }

    /**
     * Agrège l'ensemble des statistiques d'une ressource (MOD, PLUGIN, MOD_PACK).
     */
    public function getResourceStatistics(int $resourceId): array
    {
        $resource = $this->getResourceById($resourceId);
        if ($resource === null) {
            return [];
        }

        $versions = $this->getVersionFiles($resourceId);

        // Répartition des notes de 1 à 5 étoiles
        $ratingBreakdown = [
            5 => 0,
            4 => 0,
            3 => 0,
            2 => 0,
            1 => 0,
        ];

        $ratingStmt = $this->db->prepare('
            SELECT note, COUNT(*) AS total
            FROM AVIS_RESSOURCE
            WHERE id_ressource = :res_id
            GROUP BY note
        ');
        $ratingStmt->execute([':res_id' => $resourceId]);
        foreach ($ratingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $star = (int) $row['note'];
            if (isset($ratingBreakdown[$star])) {
                $ratingBreakdown[$star] = (int) $row['total'];
            }
        }

        // Liste complète des avis et commentaires
        $reviewsStmt = $this->db->prepare('
            SELECT 
                ar.id_utilisateur,
                ar.note,
                ar.commentaire,
                ar.date_avis,
                u.pseudo
            FROM AVIS_RESSOURCE ar
            INNER JOIN UTILISATEUR u ON u.id_utilisateur = ar.id_utilisateur
            WHERE ar.id_ressource = :res_id
            ORDER BY ar.date_avis DESC
        ');
        $reviewsStmt->execute([':res_id' => $resourceId]);
        $reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Nombre de serveurs Boötes ayant installé cette ressource + évolution mensuelle
        $activeServersCount = 0;
        $monthlyEvolution   = [];

        try {
            $srvCountStmt = $this->db->prepare('
                SELECT COUNT(DISTINCT id_serveur)
                FROM INSTALLER_RESSOURCE
                WHERE id_ressource = :res_id
            ');
            $srvCountStmt->execute([':res_id' => $resourceId]);
            $activeServersCount = (int) $srvCountStmt->fetchColumn();

            $evoStmt = $this->db->prepare('
                SELECT 
                    DATE_FORMAT(date_installation, \'%Y-%m\') AS periode,
                    COUNT(*) AS nb_installations
                FROM INSTALLER_RESSOURCE
                WHERE id_ressource = :res_id
                GROUP BY DATE_FORMAT(date_installation, \'%Y-%m\')
                ORDER BY periode DESC
                LIMIT 12
            ');
            $evoStmt->execute([':res_id' => $resourceId]);
            $monthlyEvolution = $evoStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log(sprintf('[Boötes][ResourceManager] Info installations : %s', $e->getMessage()));
        }

        return [
            'resource'             => $resource,
            'versions'             => $versions,
            'rating_breakdown'     => $ratingBreakdown,
            'reviews'              => $reviews,
            'active_servers_count' => $activeServersCount,
            'monthly_evolution'    => $monthlyEvolution,
        ];
    }
}