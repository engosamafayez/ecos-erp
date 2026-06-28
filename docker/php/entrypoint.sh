#!/usr/bin/env bash
# =============================================================================
# ECOS ERP — Container entrypoint (TASK-INFRA-002 simplified)
#
# IMMUTABILITY CONTRACT:
#   This script NEVER runs:
#     composer install / dump-autoload
#     artisan package:discover
#     artisan optimize / config:cache / route:cache / event:cache / view:cache
#   All cache files were baked into the image during docker build.
#
# 8-step startup sequence:
#   1. Wait for MySQL
#   2. Ensure storage directory tree
#   3. Fix storage permissions
#   4. Create storage symlink (if missing)
#   5. Generate APP_KEY (if blank)
#   6. [Optional] Run migrations        — controlled by MIGRATE_ON_START=true
#   7. [Optional] Seed AdminUserSeeder  — controlled by SEED_ADMIN_ON_START=true
#   8. exec supervisord (PHP-FPM + queue:work + schedule:work)
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/html"
cd "${APP_DIR}"

log()  { printf '\033[0;36m[entrypoint]\033[0m  %s\n' "$*"; }
ok()   { printf '\033[0;32m[entrypoint ✓]\033[0m %s\n' "$*"; }
warn() { printf '\033[0;33m[entrypoint !]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[0;31m[entrypoint ✗]\033[0m %s\n' "$*" >&2; exit 1; }

# =============================================================================
# 1. Wait for MySQL
#    Poll mysqladmin ping every 2 s for up to 120 s (60 attempts).
#    Abort if MySQL never becomes reachable — there is no value in starting
#    PHP-FPM when the database is unavailable.
# =============================================================================
log "Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."

MYSQL_ATTEMPTS=0
until mysqladmin ping \
        -h "${DB_HOST:-mysql}" \
        -P "${DB_PORT:-3306}" \
        -u "${DB_USERNAME:-ecos}" \
        -p"${DB_PASSWORD:-secret}" \
        --silent 2>/dev/null; do
    MYSQL_ATTEMPTS=$((MYSQL_ATTEMPTS + 1))
    if [ "${MYSQL_ATTEMPTS}" -ge 60 ]; then
        die "MySQL did not become reachable within 120 s. Check DB_HOST, DB_PORT, credentials."
    fi
    sleep 2
done
ok "MySQL is reachable."

# =============================================================================
# 2. Ensure storage directory tree
#    Named volume mounts storage/ as an empty directory on first run.
#    Laravel requires these subdirectories to exist before it can serve requests.
# =============================================================================
log "Ensuring storage directories..."
mkdir -p \
    "${APP_DIR}/storage/app/public" \
    "${APP_DIR}/storage/framework/cache/data" \
    "${APP_DIR}/storage/framework/sessions" \
    "${APP_DIR}/storage/framework/views" \
    "${APP_DIR}/storage/framework/testing" \
    "${APP_DIR}/storage/logs"
ok "Storage directories ready."

# =============================================================================
# 3. Fix storage permissions
#    On first run the named volume may be root-owned. PHP-FPM runs as www-data
#    and must be able to write logs, sessions, and cache.
#    bootstrap/cache/ is NOT touched here — it is baked into the image.
# =============================================================================
log "Fixing storage permissions..."
chown -R www-data:www-data "${APP_DIR}/storage"
chmod -R ug+rwX            "${APP_DIR}/storage"
ok "Storage permissions set."

# =============================================================================
# 4. Storage symlink
#    public/storage -> storage/app/public
#    Required for publicly accessible uploaded files.
#    Skip if the symlink already exists (idempotent).
# =============================================================================
if [ ! -L "${APP_DIR}/public/storage" ]; then
    log "Creating storage symlink..."
    php artisan storage:link --ansi
    ok "Storage symlink created."
else
    ok "Storage symlink already exists."
fi

# =============================================================================
# 5. Generate APP_KEY
#    Required for encryption. Only generate if the current value is blank or
#    the literal placeholder. Never overwrite an already-set key (would
#    invalidate all existing sessions and encrypted data).
# =============================================================================
if ! grep -qE '^APP_KEY[[:space:]]*=[[:space:]]*base64:' "${APP_DIR}/.env" 2>/dev/null; then
    log "APP_KEY not set — generating..."
    php artisan key:generate --ansi --force
    ok "APP_KEY generated."
else
    ok "APP_KEY already set."
fi

# =============================================================================
# 6. Database migrations [opt-in]
#
#    Set MIGRATE_ON_START=true to run migrations at container startup.
#    Default: false — migrations are an explicit operator decision that should
#    be run via: ./deploy.sh --migrate
#    or manually: docker compose exec app php artisan migrate --force
#
#    artisan migrate --force is idempotent ("Nothing to migrate" = exit 0).
# =============================================================================
if [ "${MIGRATE_ON_START:-false}" = "true" ]; then
    log "MIGRATE_ON_START=true — running migrations..."
    php artisan migrate --force --ansi
    ok "Migrations complete."
else
    log "MIGRATE_ON_START=false — skipping migrations."
    log "To migrate: ./deploy.sh --migrate  or  docker compose exec app php artisan migrate --force"
fi

# =============================================================================
# 7. Admin user seeder [opt-in]
#
#    Set SEED_ADMIN_ON_START=true to seed the initial admin user.
#    Default: false — only needed on first deployment.
#    The seeder should be idempotent (updateOrCreate / firstOrCreate).
# =============================================================================
if [ "${SEED_ADMIN_ON_START:-false}" = "true" ]; then
    log "SEED_ADMIN_ON_START=true — seeding AdminUserSeeder..."
    php artisan db:seed --class=AdminUserSeeder --force --ansi 2>/dev/null \
        || warn "AdminUserSeeder: already seeded or non-fatal failure — continuing."
    ok "AdminUserSeeder complete."
fi

# =============================================================================
# 8. Start supervisord
#    Manages: php-fpm, artisan queue:work, artisan schedule:work
#    exec replaces this shell — PID 1 is supervisord.
# =============================================================================
ok "Starting supervisord..."
exec "$@"
