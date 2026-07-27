#!/usr/bin/env bash
# Деплой: pull. Changelog на главной обновится сам при первом заходе.
set -euo pipefail
cd "$(dirname "$0")/.."

git pull "$@"
php bin/update-changelog.php --force || true

echo "Deploy done."
