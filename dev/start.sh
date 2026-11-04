#!/usr/bin/env bash
# Environnement de développement local de Boötes, sans droits root :
#   1. une instance MariaDB dédiée (127.0.0.1:3307, données dans .devdata/) ;
#   2. le serveur PHP intégré (http://localhost:8000) avec dev/router.php.
# Au premier lancement : création du fichier .env de développement, de la base
# HEBERGEUR_MC (dev/schema.sql) et des données de démonstration (dev/seed.sql).
#
# Usage : bash dev/start.sh [--reset-db]
#   --reset-db  réimporte le schéma et les données de démonstration
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DATA="$ROOT/.devdata"
DB_DIR="$DATA/mariadb"
SOCK="$DATA/mariadb.sock"
DB_PORT="${BOOTES_DEV_DB_PORT:-3307}"
HTTP_PORT="${BOOTES_DEV_HTTP_PORT:-8000}"
DB_NAME="HEBERGEUR_MC"
DB_USER="bootes_user"
OS_USER="$(id -un)"
DBA=(mariadb --no-defaults --socket="$SOCK" -u "$OS_USER")

db_ready() {
    mariadb-admin --no-defaults --socket="$SOCK" -u "$OS_USER" ping >/dev/null 2>&1
}

mkdir -p "$DATA"
chmod 700 "$DATA"

# 1. Instance MariaDB (le compte système courant en est l'administrateur, via unix_socket)
if [ ! -d "$DB_DIR/mysql" ]; then
    echo "[dev] Initialisation de l'instance MariaDB locale..."
    mariadb-install-db --no-defaults --datadir="$DB_DIR" --auth-root-authentication-method=socket \
        --skip-test-db >"$DATA/mariadb-install.log" 2>&1
fi

if ! db_ready; then
    echo "[dev] Démarrage de MariaDB sur 127.0.0.1:$DB_PORT..."
    setsid nohup mariadbd --no-defaults --datadir="$DB_DIR" --socket="$SOCK" --port="$DB_PORT" \
        --bind-address=127.0.0.1 --skip-name-resolve --pid-file="$DATA/mariadb.pid" \
        --log-error="$DATA/mariadb.err" --character-set-server=utf8mb4 \
        --collation-server=utf8mb4_unicode_ci >/dev/null 2>&1 &
    for _ in $(seq 1 60); do
        db_ready && break
        sleep 0.5
    done
    db_ready || { echo "[dev] MariaDB ne répond pas, voir $DATA/mariadb.err" >&2; exit 1; }
fi

# 2. Fichier .env de développement (jamais versionné), mot de passe aléatoire
if [ ! -f "$ROOT/.env" ]; then
    DB_PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32 || true)"
    cat >"$ROOT/.env" <<EOF
APP_ENV=development
DB_HOST=127.0.0.1
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
BOOTES_STORAGE_PATH=
EOF
    chmod 600 "$ROOT/.env"
    echo "[dev] Fichier .env de développement créé."
fi
DB_PASS="$(grep -E '^DB_PASS=' "$ROOT/.env" | cut -d= -f2-)"

# 3. Base et compte applicatif (droits limités aux données)
"${DBA[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON $DB_NAME.* TO '$DB_USER'@'127.0.0.1';
SQL

TABLE_COUNT="$("${DBA[@]}" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB_NAME'")"
if [ "$TABLE_COUNT" = "0" ] || [ "${1:-}" = "--reset-db" ]; then
    echo "[dev] Import du schéma et des données de démonstration..."
    "${DBA[@]}" "$DB_NAME" <"$ROOT/dev/schema.sql"
    "${DBA[@]}" "$DB_NAME" <"$ROOT/dev/seed.sql"
    SEED_DEMO=1
fi

# Migrations idempotentes (CREATE TABLE IF NOT EXISTS...) pour faire évoluer une base existante
for migration in "$ROOT"/dev/migrations/*.sql; do
    [ -f "$migration" ] && "${DBA[@]}" "$DB_NAME" <"$migration"
done

# Annuaire, images et pages Premium de démonstration (générés par GD, après une base neuve)
if [ "${SEED_DEMO:-0}" = "1" ]; then
    echo "[dev] Génération des images et pages de démonstration..."
    php "$ROOT/dev/seed-demo.php"
fi

# 4. Serveur PHP intégré
echo "[dev] Boötes est disponible sur http://localhost:$HTTP_PORT (Ctrl+C pour arrêter le serveur PHP)"
exec php -d upload_max_filesize=6M -d post_max_size=12M -S "127.0.0.1:$HTTP_PORT" -t "$ROOT" "$ROOT/dev/router.php"
