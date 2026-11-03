#!/usr/bin/env bash
# Arrête l'instance MariaDB de développement (le serveur PHP s'arrête avec Ctrl+C).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOCK="$ROOT/.devdata/mariadb.sock"
OS_USER="$(id -un)"

if mariadb-admin --no-defaults --socket="$SOCK" -u "$OS_USER" ping >/dev/null 2>&1; then
    mariadb-admin --no-defaults --socket="$SOCK" -u "$OS_USER" shutdown
    echo "[dev] MariaDB arrêtée."
else
    echo "[dev] MariaDB n'était pas démarrée."
fi
