# Bo-tes
The goal of this project is simply to recreate an Aternos-style website. This will allow me to learn how to create a complete web application with a database, shell scripting, user interface design, and efficient algorithms.

## Déploiement

Prérequis : PHP 8.2+ (extensions `pdo_mysql`, `mbstring`, `fileinfo`, `zip`), MariaDB, Apache avec `mod_rewrite`, `mod_headers` et `AllowOverride All`.

1. Copier `.env.example` en `.env` et renseigner les accès MariaDB (le fichier `.env` n'est jamais versionné).
2. Créer le stockage privé hors de la racine web et le déclarer :
   `BOOTES_STORAGE_PATH=/var/lib/bootes` (propriétaire : utilisateur du serveur web, droits `0750`).
   À défaut, le dossier `storage/` du projet est utilisé ; il est protégé par son propre `.htaccess`.
3. Servir le site en HTTPS : le cookie de session passe alors en `Secure` et l'en-tête HSTS est envoyé.

Le rapport d'audit et les points restant à traiter sont dans [docs/AUDIT_SECURITE.md](docs/AUDIT_SECURITE.md).

## Tests

```bash
php tests/run.php
```

La suite démarre le serveur PHP intégré sur une base SQLite jetable (`tests/support/schema.sql`) et rejoue des parcours complets : authentification, droits, uploads, quotas, RGPD, en-têtes de sécurité. Elle nécessite l'extension `pdo_sqlite`.

- `php tests/run.php "Co-admin"` : n'exécute que les tests dont le nom correspond à l'expression.
- `php tests/run.php --list` puis `php tests/run.php --index=N` : exécute un seul test.

## Développement local

Voir [dev/README.md](dev/README.md) : `bash dev/start.sh` lance une base MariaDB privée (port 3307, sans droits root)
et le site sur http://localhost:8000, avec des comptes de démonstration pour chaque rôle.

## Base de données

Le schéma MariaDB utilisé par le code est versionné dans [dev/schema.sql](dev/schema.sql). Les évolutions vont dans
`dev/migrations/` sous forme de scripts idempotents, rejoués à chaque démarrage par `dev/start.sh`.

## Interface

- Thème « ciel nocturne » par défaut et thème clair (bouton soleil / lune), jetons de design dans `assets/css/variables.css`.
- Feuilles en couches CSS : `variables` (jetons, polices) → `style` (base) → `components` → `layout` → `terminal` → `pages`.
- Aides de présentation dans `src/View.php` : `icon()`, `badge()`, `label()`, `number()`, `date()`, `ago()`, `stars()`, `areaChart()`, `logLine()`.
- Composants partagés dans `includes/` : cartes serveur et ressource, fil d'Ariane, saisie de note en étoiles.
- Comportements dans `assets/js/script.js` (aucun script en ligne, compatible CSP) : thème, menus, onglets, confirmations,
  messages éphémères, copie d'adresse, robustesse du mot de passe, dépôt de fichier.
- Icônes Lucide (licence ISC) dans `assets/img/icons.svg`, polices auto-hébergées (licence OFL) dans `assets/fonts/`.

