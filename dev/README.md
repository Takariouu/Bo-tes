# Environnement de développement local

Lance Boötes sur ta machine sans droits root et sans toucher à la MariaDB ni à l'Apache du système.

```bash
bash dev/start.sh            # démarre MariaDB (port 3307) puis le site sur http://localhost:8000
bash dev/start.sh --reset-db # idem, en réimportant le schéma et les données de démonstration
bash dev/stop.sh             # arrête l'instance MariaDB (le serveur PHP s'arrête avec Ctrl+C)
```

Au premier lancement, le script :

1. initialise une instance MariaDB privée dans `.devdata/` (ignoré par git), administrée par ton compte système via `unix_socket` ;
2. crée un fichier `.env` de développement (`APP_ENV=development`, mot de passe aléatoire pour `bootes_user`) ;
3. crée la base `HEBERGEUR_MC` à partir de `dev/schema.sql` puis y charge `dev/seed.sql`.

| Fichier | Rôle |
|---------|------|
| `dev/schema.sql` | Schéma MariaDB de la base `HEBERGEUR_MC` utilisée par le code (18 tables) |
| `dev/seed.sql` | Données de démonstration : comptes, serveurs, ressources, avis, tickets... |
| `dev/seed-demo.php` | Annuaire, images (dessinées en pixel art par GD), galeries et pages Premium de démonstration |
| `dev/router.php` | Routeur du serveur PHP intégré, reproduit les règles du `.htaccess` |
| `dev/start.sh` / `dev/stop.sh` | Démarrage et arrêt de l'environnement |

Console SQL de l'instance locale :

```bash
mariadb --no-defaults --socket=.devdata/mariadb.sock HEBERGEUR_MC
```

## Comptes de démonstration

Mot de passe commun : `Bootes-Demo-2026` (connexion par pseudo ou e-mail).

| Pseudo | Rôle | Offre | Intérêt pour tester |
|--------|------|-------|---------------------|
| Takariou | SUPER_ADMIN | Premium | Toute l'administration, gouvernance des admins, journal d'audit |
| Arcturus | ADMIN | Premium | Modération, utilisateurs, supervision matérielle |
| Vega_Builds | CREATEUR | Premium | Atelier créateur, statistiques, serveur « Arcturus Créatif » |
| Nova | UTILISATEUR | Standard | Propriétaire de « Nébuleuse Survie » (en ligne) et « Bouvier Hardcore » |
| Orion_MC | UTILISATEUR | Standard | Co-admin CONSOLE de « Nébuleuse Survie » |
| Lyra | UTILISATEUR | Standard | Co-admin LECTURE, serveur privé « Lyra Aventure » |
| Mizar | UTILISATEUR | Standard | Compte banni (connexion refusée) |

Les sauvegardes de démonstration n'existent qu'en base (téléchargement : « fichier introuvable ») ;
`dev/seed-demo.php` crée en revanche de petites archives pour que le bouton « Télécharger » des
ressources fonctionne.

## Annuaire, galeries et pages Premium

`dev/seed-demo.php` est lancé automatiquement après une base neuve (`bash dev/start.sh --reset-db`) ;
il est idempotent et peut être relancé à la main : `php dev/seed-demo.php`.

| Page | Adresse | Ce qu'elle montre |
|------|---------|-------------------|
| Annuaire | `/serveurs` | Serveurs hébergés et externes, filtres, vue liste ou grille, « Copier l'IP » |
| Page Premium | Royaume de Pixelia (Vega_Builds) | Bannière, thème Améthyste, annonce, texte en 2 colonnes, points forts, galerie en grille, liens |
| Page Premium | SkyForge Network (Arcturus), Arcturus Créatif (Vega_Builds) | Thèmes Océan et Émeraude, autres agencements |
| Page Standard | Les Terres d'Eldoria (Nova) | Mise en page standard, galerie pleine (4 / 4) |
| Ressource Premium | Stellar Tools (Vega_Builds) | Mod avec bannière, thème Ambre, galerie, installation, liens |
| Ressource Standard | Nebula Economy (Orion_MC) | Plugin avec la mise en page standard |
| Statut inconnu | Atelier Créatif Ludus (Lyra) | Fiche jamais vérifiée ; Lyra n'a pas de photo (initiales) |

Pour comparer les droits : connectez-vous avec **Vega_Builds** (Premium) puis **Nova** (Standard) et
ouvrez « Modifier la fiche » sur leur serveur. Le statut des serveurs externes est vérifié par
`php cron/verifier-annuaire.php` (les adresses de démonstration sont fictives : elles passeront
« hors ligne » si vous lancez la vérification).

## Régénérer les fichiers de marque et d'icônes

Les scripts de `dev/tools/` (Python 3 + Pillow) produisent les fichiers de `assets/img/` à partir des logos
d'origine (dossier `images/` placé à côté du dépôt). À relancer si le logo évolue :

```bash
# Symbole vectorisé (croissant + étoile), favicons et masques des logos
python3 -I dev/tools/trace_symbol.py ../images/Logo_Horizontal_Sans_Slogan_Noir.png 4 19 182 227 /tmp/bootes-symbol.json
python3 -I dev/tools/build_brand.py /tmp/bootes-symbol.json ../images assets/img

# Fonds étoilés du thème sombre
python3 -I dev/tools/build_stars.py assets/img

# Sprite d'icônes Lucide (même liste que le sprite actuel, téléchargée depuis jsDelivr)
mkdir -p /tmp/bootes-lucide
ICONES=$(grep -o 'symbol id="[^"]*"' assets/img/icons.svg | cut -d'"' -f2 | tr '\n' ' ')
python3 -I dev/tools/build_sprite.py /tmp/bootes-lucide assets/img/icons.svg assets/img/LICENSE-lucide.txt "$ICONES"
```
