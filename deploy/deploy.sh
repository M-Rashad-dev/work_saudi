#!/usr/bin/env bash
# Update the app on the VPS after `git push`:   bash deploy/deploy.sh
# Safe to run any time. Uses php8.3 explicitly so other projects' PHP versions don't matter.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP:-php8.3}"
SUDO=""; [ "$(id -u)" -ne 0 ] && SUDO="sudo"   # root (no sudo needed) or a sudo user
COMPOSER="$(command -v composer)"
cd "$APP_DIR"

echo "→ Deploying $APP_DIR"
$PHP artisan down --retry=15 || true

git pull --ff-only

$PHP "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --prefer-dist
npm ci --no-audit --no-fund
npm run build

$PHP artisan migrate --force
$PHP artisan db:seed --force          # idempotent: only adds missing settings/queries/blocked domains
$PHP artisan optimize:clear
$PHP artisan optimize

# Writable dirs for PHP-FPM / Horizon (www-data)
$SUDO chgrp -R www-data storage bootstrap/cache
$SUDO chmod -R ug+rwX storage bootstrap/cache

$PHP artisan horizon:terminate || true   # Supervisor restarts it with the new code
$SUDO systemctl reload php8.3-fpm

$PHP artisan up
echo "✓ Done"
