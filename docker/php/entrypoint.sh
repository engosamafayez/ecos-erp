#!/usr/bin/env bash
# =============================================================================
# ECOS ERP — Container entrypoint
#
# Responsibility: runtime initialisation ONLY.
# This script runs on every container start and must be fully idempotent.
# Restarting the container must never break anything.
#
# Execution order:
#   1.  Wait for MySQL
#   2.  Create required storage directories
#   3.  Fix permissions
#   4.  Create storage symlink (idempotent)
#   5.  Generate APP_KEY if missing
#   6.  composer dump-autoload --no-dev --optimize
#   7.  artisan package:discover
#   8.  artisan migrate --force
#   9.  artisan optimize
#   10. artisan db:seed --class=AdminUserSeeder --force
#   11. exec supervisord (hand control to CMD)
#
# Rules:
#   composer install is NEVER executed here — vendor is baked into the image.
#   Only composer dump-autoload is allowed at runtime.
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/html"
cd "${APP_DIR}"

log()  { printf '\033[0;36m[entrypoint]\033[0m %s\n' "$*"; }
warn() { printf '\033[0;33m[entrypoint WARN]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[0;31m[entrypoint FATAL]\033[0m %s\n' "$*" >&2; exit 1; }

# =============================================================================
# 1. Wait for MySQL
# =============================================================================
log "Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
MYSQL_ATTEMPTS=0
until mysqladmin ping \
      -h"${DB_HOST:-mysql}" \
      -P"${DB_PORT:-3306}" \
      -u"${DB_USERNAME:-ecos}" \
      -p"${DB_PASSWORD:-secret}" \
      --silent 2>/dev/null; do
    MYSQL_ATTEMPTS=$((MYSQL_ATTEMPTS + 1))
    if [ "${MYSQL_ATTEMPTS}" -ge 60 ]; then
        die "MySQL did not become available after 120 s."
    fi
    sleep 2
done
log "MySQL is ready (after $((MYSQL_ATTEMPTS * 2)) s)."

# =============================================================================
# 2. Create required storage directories
#
# The app-storage named volume starts empty on first run.
# Laravel crashes on first request if any of these paths are absent.
# mkdir -p is idempotent.
# =============================================================================
log "Ensuring storage directory structure..."
for dir in \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs
do
    mkdir -p "${APP_DIR}/${dir}"
done

# =============================================================================
# 3. Fix permissions
#
# Run AFTER mkdir so newly created directories are also covered.
# =============================================================================
chown -R www-data:www-data \
    "${APP_DIR}/storage" \
    "${APP_DIR}/bootstrap/cache" \
    || true
chmod -R ug+rwX \
    "${APP_DIR}/storage" \
    "${APP_DIR}/bootstrap/cache" \
    || true

# =============================================================================
# 4. Storage symlink
#
# public/storage -> storage/app/public
# Created only on first run; skipped on every subsequent restart.
# =============================================================================
if [ ! -L "${APP_DIR}/public/storage" ]; then
    log "Creating storage symlink (public/storage -> storage/app/public)..."
    php artisan storage:link
else
    log "Storage symlink exists — skipping."
fi

# =============================================================================
# 5. Application key
#
# In production APP_KEY must be set in backend/.env before first deploy.
# This is a safety net for fresh environments only.
# =============================================================================
if ! grep -qE '^APP_KEY=base64:' "${APP_DIR}/.env" 2>/dev/null; then
    log "APP_KEY missing — generating..."
    php artisan key:generate --force
fi

# =============================================================================
# 6. Composer dump-autoload
#
# Regenerates the optimised class map inside the running container.
# This is the ONLY composer command allowed at runtime.
# composer install is NOT executed here.
#
# Runs before package:discover because discover reads from composer's class map.
# =============================================================================
log "Refreshing autoloader (composer dump-autoload --no-dev --optimize)..."
composer dump-autoload \
    --no-dev \
    --optimize \
    --no-scripts \
    --working-dir="${APP_DIR}"

# =============================================================================
# 7. Package discovery
#
# Scans vendor/ for service providers and facades.
# Writes bootstrap/cache/packages.php into the app-cache volume.
#
# Must run AFTER dump-autoload with --no-dev so dev-only providers
# (laravel/pail, phpunit, etc.) are excluded from the package manifest.
# This is the fix for the "No Pail issue" success criterion.
# =============================================================================
log "Running package:discover..."
php artisan package:discover --ansi

# =============================================================================
# 8. Database migrations
#
# Idempotent: "Nothing to migrate" exits 0 when already up to date.
# Failure here correctly aborts startup — a partially migrated DB is unsafe.
# =============================================================================
log "Running database migrations..."
php artisan migrate --force

# =============================================================================
# 9. Laravel optimisation
#
# Generates into bootstrap/cache/ (the app-cache named volume):
#   config.php  routes-v7.php  services.php  events.php
#
# Fresh on every container start — no stale cache accumulation.
# =============================================================================
log "Caching config, routes, and views (artisan optimize)..."
php artisan optimize

# =============================================================================
# 10. Admin user (idempotent)
# =============================================================================
log "Ensuring admin user exists..."
php artisan db:seed --class=AdminUserSeeder --force 2>/dev/null \
    || warn "AdminUserSeeder skipped (admin may already exist)."

# =============================================================================
# 11. Start Supervisor
# =============================================================================
log "Bootstrap complete — starting supervisord."
exec "$@"
