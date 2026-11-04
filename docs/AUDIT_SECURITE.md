# Audit de sécurité — Boötes (novembre 2026)

Périmètre : l'ensemble du dépôt (contrôleur frontal, `src/`, `config/`, `pages/`, `includes/`, `.htaccess`).
Méthode : revue manuelle du code, puis vérification par la suite de tests `tests/run.php`. Sur le code d'origine,
21 des 23 scénarios de sécurité échouaient ; après correction, les 35 scénarios passent.

Points déjà sains avant l'audit : requêtes SQL toutes préparées, échappement XSS systématique (`Security::e`),
hachage Argon2id, cookies de session `HttpOnly` + `SameSite=Lax`, jeton CSRF sur tous les formulaires POST,
routage par liste blanche.

## Vulnérabilités corrigées

| # | Gravité | Problème constaté | Correction |
|---|---------|-------------------|------------|
| 1 | Critique | `.htaccess` ne bloquait que 5 dossiers : `instances/` et `uploads/` étaient téléchargeables, et le nom des archives contenait le numéro de version saisi (`res-1-v1.php.-ab12.jar`), d'où une exécution PHP possible avec `AddHandler`. | `.htaccess` en liste blanche (seuls `index.php` et les fichiers statiques de `/assets` sont servis, `mod_rewrite` obligatoire). Données déplacées dans un stockage privé (`storage/`, configurable hors racine web via `BOOTES_STORAGE_PATH`) protégé par son propre `.htaccess`. Archives renommées aléatoirement et servies par l'application. |
| 2 | Critique | Un ADMIN pouvait changer l'e-mail et le mot de passe du Super-Admin, le bannir ou le supprimer (`/user/compte?id=X`, modération, signalements). | Règle centrale `Auth::canAdministrate()` : on ne gère qu'un compte de rang strictement inférieur. Appliquée au profil, au bannissement, au changement d'offre et aux signalements. |
| 3 | Élevé | Les pages s'exécutaient après l'envoi de l'en-tête HTML : `session_regenerate_id()` échouait à la connexion (fixation de session), les redirections, le logout et le téléchargement des sauvegardes ne fonctionnaient pas. | La vue est exécutée dans un tampon avant le rendu du gabarit ; redirections et fichiers passent par `Http::redirect()` / `Http::sendFile()`. |
| 4 | Élevé | Session jamais revérifiée ni expirée : un compte banni, supprimé ou rétrogradé conservait ses droits. | `Auth::refreshSession()` à chaque requête, expiration après 30 min d'inactivité et 12 h au maximum, nouveau jeton CSRF à la connexion. |
| 5 | Élevé | Aucune limitation des tentatives de connexion (brute force, et saturation mémoire car Argon2id utilise 64 Mo par essai). | `RateLimiter` : 5 échecs par compte et par IP, 20 par IP sur 15 min ; 5 inscriptions par IP et par heure ; 10 signalements par heure. |
| 6 | Élevé | Niveaux de co-admin (`LECTURE`, `CONSOLE`, `COMPLET`) ignorés : un co-admin en lecture pouvait tout faire. | `Auth::getServerAccessLevel()` et vérification par action sur toutes les pages serveur ; formulaires désactivés selon le niveau ; niveau par défaut `LECTURE`. |
| 7 | Élevé | N'importe qui pouvait s'inscrire avec le pseudo `Anonyme_RGPD` et hériter des ressources des comptes supprimés. | Pseudos du staff, du compte système et domaine `@bootes.local` réservés. Compte anonyme identifié par pseudo + e-mail + quota nul, et verrouillé (banni). |
| 8 | Élevé | Upload contrôlé sur la seule extension ; un échec de `move_uploaded_file` était ignoré (version publiée sans fichier). | `ResourceManager::storeUploadedArchive()` : `is_uploaded_file`, taille, extension, signature ZIP réelle (avec `ZipArchive::CHECKCONS`), échec bloquant. |
| 9 | Moyen | Ressources suspendues ou en attente toujours consultables et installables. | Masquées (404) hors auteur et administrateurs ; installation réservée aux ressources validées (`installOnServer`). |
| 10 | Moyen | Quotas absents : serveurs illimités, `max_ram_allouable` ignoré, RAM modifiable sur un serveur en ligne (contournement du plafond). | 3 serveurs en Standard et 10 en Premium, RAM limitée par l'offre et le quota du compte, contrôle au démarrage sous verrou MariaDB `GET_LOCK`, RAM figée tant que le serveur tourne. |
| 11 | Moyen | Avis et signalements possibles sur un serveur privé (contrôle de visibilité effectué après le traitement). | Contrôle déplacé avant le traitement ; on ne peut plus évaluer son propre serveur ni sa propre ressource. |
| 12 | Moyen | Suppressions RGPD et modération non atomiques et incomplètes (clés étrangères bloquantes, données et fichiers orphelins). | `UserManager::deleteAccount()` et `ServerManager::deleteServer()` transactionnels, fichiers effacés après validation ; ré-authentification exigée pour supprimer son compte. |
| 13 | Moyen | Pas de gestionnaire d'erreurs global : stack traces possibles. | Gestionnaires d'exceptions et d'erreurs fatales : page 500 générique, détail dans le journal serveur, `display_errors` coupé en production. |
| 14 | Moyen | Validation incomplète : version et mode de jeu libres, port incrémental sans contrôle, log lu entièrement en mémoire, port de carte arbitraire. | Listes blanches (`SUPPORTED_MC_VERSIONS`, `GAME_MODES`), premier port libre de la plage, lecture des 256 derniers Ko, port de carte dans une plage dédiée et sans collision. |
| 15 | Faible | Logout en GET (jeton CSRF dans l'URL), repli CSRF non cryptographique, énumération de comptes par le temps de réponse, `LIKE` sans échappement, compteur de téléchargements gonflable, en-têtes incomplets. | Logout en POST uniquement, échec franc si le CSPRNG est indisponible, vérification sur un hash factice, `Security::likeContains()` + `ESCAPE '!'`, compteur incrémenté à la première installation seulement, CSP stricte, `Permissions-Policy`, HSTS sous HTTPS, `Cache-Control: no-store` pour les pages connectées. |

## Bugs fonctionnels corrigés

- Onglet Modpacks du catalogue toujours vide (`MODPACK` au lieu de `MOD_PACK`).
- Lien « Découvrir les offres » en 404 : la route `offres` manquait.
- Le bouton « Télécharger » ne livrait aucun fichier ; la restauration annonçait un succès même sans archive.
- Aucune feuille de style n'était chargée : CSS minimale, confirmations sur les actions destructrices et état de chargement des boutons (script externe, compatible CSP).

## Points restant à traiter (hors de cette passe)

1. **Racine web** : idéalement, faire pointer le `DocumentRoot` sur un dossier `public/` contenant uniquement `index.php` et `assets/`. En production, placer le stockage hors racine web (`BOOTES_STORAGE_PATH=/var/lib/bootes`, droits `0750`).
2. **Isolation des instances Minecraft** : `ServerManager` simule encore le lancement de la JVM. Le jour où les serveurs tournent réellement, il faut un utilisateur système dédié sans privilèges par instance (ou un conteneur), une limite mémoire au niveau du système (cgroup `MemoryMax`), aucun shell interpolé, et un répertoire d'instance non accessible au serveur web.
3. **Modération à priori** : `createResource()` publie directement en `VALIDE`. Pour du code exécutable (mods, plugins), le statut `EN_ATTENTE` jusqu'à validation par un admin est recommandé.
4. **Schéma SQL** : il n'est pas versionné. À ajouter avec des clés étrangères explicites, `UNIQUE` sur `SERVEUR.port`, et un `id_utilisateur` nullable dans `LOG_SYSTEME`.
5. **Sessions multiples** : un changement de mot de passe ne déconnecte pas les autres appareils (il faudrait une colonne de version de session).
6. **Journal d'audit** : enregistrer aussi les échecs de connexion, bannissements et suppressions dans `LOG_SYSTEME`.
7. **CI** : ~~`workflow/gemini-review.yml` n'est jamais exécuté~~ — corrigé le 04/11/2026 : déplacé dans `.github/workflows/` (le secret `GEMINI_API_KEY` doit exister sur GitHub).

## Migration depuis la version précédente

- Déplacer d'éventuels dossiers `instances/`, `backups/` et `uploads/ressources/` de la racine vers `storage/` (ou vers `BOOTES_STORAGE_PATH`). Les chemins en base restent compatibles.
- Vérifier qu'aucun compte existant n'utilise le pseudo `Anonyme_RGPD` ou une adresse `@bootes.local`.
- Toutes les sessions ouvertes seront réinitialisées une fois (reconnexion nécessaire).
- Apache : `mod_rewrite` est désormais indispensable (sinon erreur 500, volontairement) et `AllowOverride All` doit être actif.
