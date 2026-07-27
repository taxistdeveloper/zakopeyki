#!/usr/bin/env bash
# Деплой на сервере: pull + авто-changelog для модалки на главной.
set -euo pipefail
cd "$(dirname "$0")/.."

git pull "$@"
php bin/update-changelog.php

echo "Deploy done."
