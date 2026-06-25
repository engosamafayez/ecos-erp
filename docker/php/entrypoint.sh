#!/usr/bin/env bash
###############################################################################
# ECOS ERP — container entrypoint
#
# Prepares the Laravel application on container start, then hands control to
# the CMD (Supervisor). Safe to run repeatedly (idempotent).
###############################################################################
set -euo pipefail

APP_DIR="/var/www/html"
cd "$APP_DIR"

log() { printf '\033[0;36m[entrypoint]\033[0m %s\n' "$*"; }

# --- Dependencies (relevant when source is bind-mounted in development) -----
if [ ! -d "$APP_DIR/vendor" ]; then
    log "vendor/ missing — installing Composer dependencies..."
    composer install --no-interaction --prefer-dist --no-progress
fi

# --- Environment file -------------------------------------------------------
if [ ! -f "$APP_DIR/.env" ] && [ -f "$APP_DIR/.env.example" ]; then
    log ".env missing — copying from .env.example"
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

# --- Application key --------------------------------------------------------
if ! grep -qE '^APP_KEY=base64:' "$APP_DIR/.env" 2>/dev/null; then
    log "Generating application key..."
    php artisan key:generate --force
fi

# --- Wait for MySQL ---------------------------------------------------------
log "Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
until mysqladmin ping -h"${DB_HOST:-mysql}" -P"${DB_PORT:-3306}" \
        -u"${DB_USERNAME:-ecos}" -p"${DB_PASSWORD:-secret}" --silent 2>/dev/null; do
    sleep 2
done
log "MySQL is ready."

# --- Storage symlink & permissions -----------------------------------------
php artisan storage:link 2>/dev/null || true
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" || true

# --- Migrations -------------------------------------------------------------
log "Running database migrations..."
php artisan migrate --force || log "Migrations skipped/failed (continuing)."

# --- Admin user (idempotent: firstOrCreate, never resets password) ----------
log "Ensuring admin user exists..."
php artisan db:seed --class=AdminUserSeeder --force 2>/dev/null || log "Admin seed skipped."

# --- Clear stale caches (development friendliness) --------------------------
php artisan config:clear  >/dev/null 2>&1 || true
php artisan route:clear   >/dev/null 2>&1 || true
php artisan view:clear    >/dev/null 2>&1 || true

log "Bootstrap complete — starting services."
exec "$@"
