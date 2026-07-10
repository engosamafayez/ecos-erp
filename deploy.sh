#!/usr/bin/env bash
# =============================================================================
# ECOS ERP — Production deployment script
#
# Usage
#   ./deploy.sh             Deploy (no migrations)
#   ./deploy.sh --migrate   Deploy and run database migrations
#
# Requirements
#   git  docker (Engine 24+)  docker compose v2  curl  python3
#
# Flow
#    1.  Environment validation (APP_ENV, APP_KEY, APP_DEBUG, passwords, domains)
#    2.  Security audit         (session cookies, trusted proxies)
#    3.  Tag current images as :rollback
#    4.  git pull origin main
#    5.  docker compose build   (composer + npm + artisan cache baked in)
#    6.  Image self-test        (artisan works without composer)
#    7.  docker compose up -d
#    8.  Wait for PHP-FPM healthcheck
#    9.  [--migrate] DB snapshot → migrate:status preview → migrate --force
#   10.  Deployment verification (artisan about / route:list / queue:restart / supervisorctl)
#   11.  Endpoint verification   (/healthz /build-info /app/ /api/health)
#   12.  Deployment validation   (PASS/FAIL table from /api/health)
#   13.  docker image prune
#
# Safety
#   set -euo pipefail: any unhandled error aborts the script.
#   ERR trap triggers automatic rollback if containers were updated.
#   DB snapshot is taken before migrations and its path is printed on rollback.
#
# Immutability (TASK-INFRA-002):
#   composer install / npm build / artisan cache:* run inside docker build.
#   The runtime container only performs: wait-mysql → storage → link → exec.
# =============================================================================
set -euo pipefail

# =============================================================================
# Flag parsing
# =============================================================================
RUN_MIGRATIONS=false
for arg in "$@"; do
    case "$arg" in
        --migrate) RUN_MIGRATIONS=true ;;
        *)
            printf '\033[0;31m[deploy ✗]\033[0m Unknown argument: %s\n' "$arg" >&2
            printf 'Usage: ./deploy.sh [--migrate]\n' >&2
            exit 1
            ;;
    esac
done

# =============================================================================
# Helpers
# =============================================================================
log()     { printf '\033[0;36m[deploy]\033[0m   %s\n' "$*"; }
ok()      { printf '\033[0;32m[deploy ✓]\033[0m %s\n' "$*"; }
warn()    { printf '\033[0;33m[deploy !]\033[0m %s\n' "$*" >&2; }
section() { printf '\n\033[1;37m━━━ %s ━━━\033[0m\n' "$*"; }
fail()    { printf '\033[0;31m[deploy ✗]\033[0m %s\n' "$*" >&2; exit 1; }

# Read a single value from backend/.env without sourcing the file.
# Strips surrounding quotes. Returns empty string if key is not found.
_env() {
    grep -E "^$1[[:space:]]*=" backend/.env 2>/dev/null \
        | head -1 \
        | sed "s/^$1[[:space:]]*=[[:space:]]*//" \
        | sed "s/^[\"']//" \
        | sed "s/[\"']$//"
}

# Always target the production compose file — never auto-merge the dev override.
COMPOSE="docker compose -f docker-compose.yml"

# State flags — used by the rollback trap.
CONTAINERS_UPDATED=false
PRE_MIGRATE_SNAP=""
DB_USER=""
DB_NAME=""

# =============================================================================
# Rollback
# Called automatically by the ERR trap when the deploy fails after containers
# were updated. Restores the :rollback image tags and restarts.
# Also prints DB snapshot restore command when a snapshot was taken.
# =============================================================================
rollback() {
    local code=$?
    [ $code -eq 0 ] && return
    [ "$CONTAINERS_UPDATED" = "false" ] && return

    printf '\n\033[0;31m[deploy ROLLBACK]\033[0m Deploy failed (exit %s) — restoring previous images...\n' "$code" >&2

    local rolled=false
    if docker image inspect ecos-erp/app:rollback >/dev/null 2>&1; then
        docker tag ecos-erp/app:rollback ecos-erp/app:latest && rolled=true
    fi
    if docker image inspect ecos-erp/nginx:rollback >/dev/null 2>&1; then
        docker tag ecos-erp/nginx:rollback ecos-erp/nginx:latest && rolled=true
    fi

    if [ "$rolled" = "true" ]; then
        $COMPOSE up -d --no-build 2>/dev/null || true
        printf '\033[0;32m[deploy ROLLBACK]\033[0m Previous version restored.\n' >&2
    else
        printf '\033[0;33m[deploy ROLLBACK]\033[0m No :rollback images found — manual intervention required.\n' >&2
    fi

    # Schema rollback guidance when a pre-migration snapshot exists.
    if [ -n "${PRE_MIGRATE_SNAP}" ] && [ -f "${PRE_MIGRATE_SNAP}" ]; then
        printf '\n\033[0;33m[deploy ROLLBACK]\033[0m Pre-migration DB snapshot: %s\n' "${PRE_MIGRATE_SNAP}" >&2
        printf '\033[0;33m[deploy ROLLBACK]\033[0m To restore schema:\n' >&2
        printf '  zcat %s | %s exec -T -e MYSQL_PWD=<password> mysql mysql -u%s %s\n' \
            "${PRE_MIGRATE_SNAP}" \
            "${COMPOSE}" \
            "${DB_USER:-ecos}" \
            "${DB_NAME:-ecos_erp}" >&2
        printf '\033[0;31m[deploy ROLLBACK]\033[0m Schema changes are NOT auto-reverted. Review and restore manually.\033[0m\n' >&2
    fi
}
trap rollback EXIT

# =============================================================================
# 1. Environment validation
# =============================================================================
section "Environment validation"

[ -f "docker-compose.yml" ] \
    || fail "docker-compose.yml not found. Run this script from the repository root."

[ -f "backend/.env" ] \
    || fail "backend/.env not found. Create it from backend/.env.example before deploying."

# The override file silently replaces the nginx image with stock nginx +
# a broken bind-mount that shadows the baked-in SPA.
if [ -f "docker-compose.override.yml" ]; then
    fail "docker-compose.override.yml detected.
  This file is for LOCAL DEVELOPMENT ONLY and must not exist on production servers.
  Remove it:  rm docker-compose.override.yml"
fi

# APP_ENV must be 'staging' or 'production'.
APP_ENV_VAL=$(_env APP_ENV)
case "${APP_ENV_VAL}" in
    staging|production) ;;
    local|"")
        fail "APP_ENV='${APP_ENV_VAL}' is not valid for deployment.
  Set APP_ENV=staging or APP_ENV=production in backend/.env." ;;
    *)
        warn "APP_ENV='${APP_ENV_VAL}' is non-standard. Continuing, but verify this is intentional." ;;
esac
ok "APP_ENV=${APP_ENV_VAL}"

# APP_DEBUG=true is a critical security misconfiguration.
if grep -qE '^APP_DEBUG[[:space:]]*=[[:space:]]*true' "backend/.env" 2>/dev/null; then
    fail "APP_DEBUG=true detected in backend/.env.
  Set it to false before deploying:
    sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' backend/.env"
fi
ok "APP_DEBUG=false"

# APP_KEY must be present and formatted as base64:<key>.
APP_KEY_VAL=$(_env APP_KEY)
[ -z "${APP_KEY_VAL}" ] \
    && fail "APP_KEY is not set in backend/.env.
  Generate one:
    docker run --rm php:8.4-cli php -r \"echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;\""
printf '%s' "${APP_KEY_VAL}" | grep -qE '^base64:.{40,}$' \
    || fail "APP_KEY format is invalid. Expected 'base64:<44 chars>'.
  Value starts with: ${APP_KEY_VAL:0:20}..."
ok "APP_KEY is set and well-formed"

# DB_PASSWORD must not be the development default.
# Hard-fail for production; warn for staging (staging may legitimately use simple passwords
# on internal networks, but production must always have strong credentials).
DB_PASS_VAL=$(_env DB_PASSWORD)
DB_USER=$(_env DB_USERNAME)
DB_NAME=$(_env DB_DATABASE)
if [ "${DB_PASS_VAL}" = "secret" ]; then
    if [ "${APP_ENV_VAL}" = "production" ]; then
        fail "DB_PASSWORD=secret is the development default and must not be used in production.
  Change DB_PASSWORD in backend/.env and MYSQL_PASSWORD in docker-compose.yml."
    else
        warn "DB_PASSWORD=secret is a dev default. Change before going to production."
    fi
else
    ok "DB credentials look non-default"
fi

# SANCTUM_STATEFUL_DOMAINS must be set or SPA auth will fail with 401.
SANCTUM_DOMAINS=$(_env SANCTUM_STATEFUL_DOMAINS)
[ -z "${SANCTUM_DOMAINS}" ] \
    && fail "SANCTUM_STATEFUL_DOMAINS is not set in backend/.env.
  SPA authentication (Sanctum cookie auth) will fail without it.
  Set it to your domain: SANCTUM_STATEFUL_DOMAINS=staging.ecos-erp.com"
ok "SANCTUM_STATEFUL_DOMAINS=${SANCTUM_DOMAINS}"

ok "Environment validation passed."

# =============================================================================
# 1b. Security audit (warnings — do not abort, but flag for operator attention)
# =============================================================================
section "Security audit"

SESSION_ENCRYPT=$(_env SESSION_ENCRYPT)
SESSION_SECURE=$(_env SESSION_SECURE_COOKIE)
TRUSTED_PROXIES_VAL=$(_env TRUSTED_PROXIES)
LOG_LEVEL_VAL=$(_env LOG_LEVEL)

if [ "${SESSION_ENCRYPT}" = "true" ]; then
    ok "SESSION_ENCRYPT=true"
else
    warn "SESSION_ENCRYPT is not 'true' — session data is stored unencrypted in Redis.
       Set SESSION_ENCRYPT=true in backend/.env."
fi

if [ "${SESSION_SECURE}" = "true" ]; then
    ok "SESSION_SECURE_COOKIE=true"
else
    warn "SESSION_SECURE_COOKIE is not 'true' — session cookies will be sent over HTTP.
       Set SESSION_SECURE_COOKIE=true when the application is served over HTTPS."
fi

if [ -n "${TRUSTED_PROXIES_VAL}" ]; then
    ok "TRUSTED_PROXIES=${TRUSTED_PROXIES_VAL}"
else
    warn "TRUSTED_PROXIES is not set — defaulting to '*' in bootstrap/app.php.
       Safe in Docker (PHP-FPM not publicly accessible). Set explicitly for bare-metal."
fi

if [ "${LOG_LEVEL_VAL}" = "debug" ]; then
    warn "LOG_LEVEL=debug — stack traces and query parameters will be written to logs.
       Set LOG_LEVEL=warning or LOG_LEVEL=error in production."
else
    ok "LOG_LEVEL=${LOG_LEVEL_VAL:-<not set>}"
fi

ok "Security audit complete."

# =============================================================================
# 2. Tag current images as :rollback (before anything changes)
# =============================================================================
section "Rollback snapshot"
if docker image inspect ecos-erp/app:latest >/dev/null 2>&1; then
    docker tag ecos-erp/app:latest   ecos-erp/app:rollback
    log "ecos-erp/app:latest  → :rollback"
fi
if docker image inspect ecos-erp/nginx:latest >/dev/null 2>&1; then
    docker tag ecos-erp/nginx:latest ecos-erp/nginx:rollback
    log "ecos-erp/nginx:latest → :rollback"
fi

# =============================================================================
# 3. Pull latest code
# =============================================================================
section "Source update"
PREV_SHA=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
log "Current commit : ${PREV_SHA}"
log "Current branch : $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'unknown')"
log "Migrate flag   : ${RUN_MIGRATIONS}"

git pull origin main

GIT_SHA=$(git rev-parse --short HEAD)
GIT_MSG=$(git log -1 --pretty=format:"%s" 2>/dev/null || echo "unknown")
APP_VERSION=$(git describe --tags --exact-match 2>/dev/null \
              || git describe --tags 2>/dev/null \
              || echo "${GIT_SHA}")
BUILD_TIME=$(date -u +%Y-%m-%dT%H:%M:%SZ)

log "Deploying commit  : ${GIT_SHA}"
log "Application ver.  : ${APP_VERSION}"
log "Build timestamp   : ${BUILD_TIME}"
log "Commit message    : ${GIT_MSG}"

# =============================================================================
# 4. Build images
#    --pull         refresh base images (php:8.4-fpm-bookworm, nginx:alpine, etc.)
#    --build-arg    bake traceability metadata into both images
#
#    During build, Stage 3 runs:
#      php artisan package:discover
#      php artisan route:cache
#      php artisan event:cache
#    and verifies no dev packages in packages.php.
#    If any of these fail, the build fails here.
# =============================================================================
section "Image build"
log "Building ecos-erp/app (Stage 3) and ecos-erp/nginx (Stage 4)..."
log "Bootstrap cache (package:discover + route:cache + event:cache) baked in during build."
$COMPOSE build --pull \
    --build-arg "GIT_SHA=${GIT_SHA}" \
    --build-arg "APP_VERSION=${APP_VERSION}" \
    --build-arg "BUILD_TIME=${BUILD_TIME}"
ok "Images built."

# =============================================================================
# 5. Image self-test
#    Verify the image is self-contained: artisan works without composer install.
#    Runs in an ephemeral throwaway container — does not start any services.
# =============================================================================
section "Image self-test"
log "Verifying ecos-erp/app:latest is self-contained (no composer needed)..."

SELF_TEST_KEY="base64:$(openssl rand -base64 32)"

docker run --rm \
    -e APP_NAME="ECOS-ERP" \
    -e APP_ENV=production \
    -e APP_KEY="${SELF_TEST_KEY}" \
    -e APP_DEBUG=false \
    -e APP_URL=http://localhost \
    -e DB_CONNECTION=mysql \
    -e DB_HOST=127.0.0.1 \
    -e DB_DATABASE=ecos_erp \
    -e DB_USERNAME=ecos \
    -e DB_PASSWORD=self-test \
    -e CACHE_STORE=array \
    -e SESSION_DRIVER=array \
    -e QUEUE_CONNECTION=sync \
    -e REDIS_HOST=127.0.0.1 \
    -e MAIL_MAILER=log \
    --entrypoint="" \
    ecos-erp/app:latest \
    php /var/www/html/artisan --version \
    || fail "Image self-test FAILED: 'php artisan --version' could not run. Image may be broken."
ok "artisan --version: OK."

log "Verifying bootstrap/cache has no dev packages..."
if docker run --rm ecos-erp/app:latest \
        grep -qi 'pail\|sail\|collision\|phpunit' \
        /var/www/html/bootstrap/cache/packages.php 2>/dev/null; then
    fail "Image self-test FAILED: dev packages found in bootstrap/cache/packages.php."
fi
ok "Bootstrap cache is clean (no dev packages)."

log "Verifying baked-in cache files..."
docker run --rm ecos-erp/app:latest \
    ls /var/www/html/bootstrap/cache/ \
    | { read -r CACHE_LIST || true; log "Cache files: ${CACHE_LIST}"; }
ok "Image self-test passed."

# =============================================================================
# 6. Recreate containers (rolling update)
# =============================================================================
section "Container rollout"
$COMPOSE up -d --remove-orphans
CONTAINERS_UPDATED=true
ok "Containers started."

# =============================================================================
# 7. Container status (immediate snapshot — some may still be starting)
# =============================================================================
section "Container status"
$COMPOSE ps

# =============================================================================
# 8. Wait for app container to pass its PHP-FPM healthcheck
#    Timeout: 40 × 5 s = 200 s
# =============================================================================
section "Health wait"
log "Waiting for ecos-app to become healthy (up to 200 s)..."
ATTEMPTS=0
until [ "$(docker inspect --format='{{.State.Health.Status}}' ecos-app 2>/dev/null)" = "healthy" ]; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [ "${ATTEMPTS}" -ge 40 ]; then
        fail "ecos-app did not become healthy within 200 s.
  Diagnose:
    docker logs ecos-app
    docker inspect --format '{{json .State.Health}}' ecos-app"
    fi
    printf '.'
    sleep 5
done
printf '\n'
ok "ecos-app is healthy (after $((ATTEMPTS * 5)) s)."

# =============================================================================
# 9. Database migrations [--migrate flag only]
#
# Schema changes are an explicit operator decision — never run automatically.
# Flow:
#   a. Show pending migrations (migrate:status)
#   b. Create a gzip DB snapshot for rollback reference
#   c. Run migrations
# =============================================================================
section "Database migrations"
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    log "Showing pending migrations before applying..."
    $COMPOSE exec -T app php artisan migrate:status 2>&1 | grep -E "Pending|Yes|No" | tail -20 || true

    # Take a pre-migration DB snapshot so the rollback function can print
    # the restore command if something goes wrong.
    log "Creating pre-migration database snapshot..."
    DB_PASS_SNAP=$(_env DB_PASSWORD)
    SNAP_FILE="/tmp/ecos-premigrate-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
    if $COMPOSE exec -T \
            -e MYSQL_PWD="${DB_PASS_SNAP}" \
            mysql mysqldump \
            -u"${DB_USER:-ecos}" \
            "${DB_NAME:-ecos_erp}" 2>/dev/null \
            | gzip > "${SNAP_FILE}"; then
        SNAP_SIZE=$(du -h "${SNAP_FILE}" 2>/dev/null | cut -f1 || echo "?")
        PRE_MIGRATE_SNAP="${SNAP_FILE}"
        ok "DB snapshot: ${SNAP_FILE} (${SNAP_SIZE})"
    else
        warn "DB snapshot failed — migrations will proceed without a pre-migration backup."
        warn "Manual backup: $COMPOSE exec mysql mysqldump -u${DB_USER:-ecos} ${DB_NAME:-ecos_erp} > backup.sql"
        rm -f "${SNAP_FILE}" 2>/dev/null || true
    fi

    log "Running php artisan migrate --force..."
    $COMPOSE exec -T app php artisan migrate --force
    ok "Migrations complete."
else
    log "Skipping migrations."
    log "To apply schema changes on next deploy: ./deploy.sh --migrate"
fi

# =============================================================================
# 10. Deployment verification
#     Verify that the running container has a healthy Laravel application with
#     all routes loaded, queue workers signalled, and Supervisor processes up.
# =============================================================================
section "Deployment verification"

log "Laravel boot (php artisan about)..."
$COMPOSE exec -T app php artisan about --only=Environment,Cache,Queue 2>&1 \
    || fail "php artisan about failed — Laravel may not be booting correctly."
ok "Laravel boot: OK."

log "Route cache (php artisan route:list)..."
ROUTE_COUNT=$($COMPOSE exec -T app php artisan route:list 2>/dev/null \
    | grep -cE "GET|POST|PUT|PATCH|DELETE" || echo "0")
if [ "${ROUTE_COUNT}" -gt 100 ]; then
    ok "Routes: ${ROUTE_COUNT} routes loaded from cache."
else
    warn "Route list returned only ${ROUTE_COUNT} routes — cache may be stale."
fi

log "Migration status (php artisan migrate:status)..."
$COMPOSE exec -T app php artisan migrate:status 2>&1 | tail -5
ok "Migration status checked."

log "Queue restart (php artisan queue:restart)..."
$COMPOSE exec -T app php artisan queue:restart
ok "Queue restart signal sent to all workers."

log "Supervisor process status..."
if $COMPOSE exec -T app supervisorctl status 2>/dev/null; then
    ok "Supervisor processes verified."
else
    warn "Could not query supervisorctl — verify manually:
       docker compose exec app supervisorctl status"
fi

# =============================================================================
# 11. Endpoint verification
#     All checks must pass or the script aborts (triggering rollback via trap).
# =============================================================================
section "Endpoint verification"

check_http() {
    local label="$1" url="$2" expected="$3"
    local code
    code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "${url}" || echo "000")
    if [ "${code}" != "${expected}" ]; then
        fail "GET ${url} → HTTP ${code} (expected ${expected})  [${label}]"
    fi
    ok "${label}: GET ${url} → HTTP ${code}."
}

log "Waiting for ecos-nginx to become healthy..."
NGINX_ATTEMPTS=0
until [ "$(docker inspect --format='{{.State.Health.Status}}' ecos-nginx 2>/dev/null)" = "healthy" ]; do
    NGINX_ATTEMPTS=$((NGINX_ATTEMPTS + 1))
    if [ "${NGINX_ATTEMPTS}" -ge 20 ]; then
        fail "ecos-nginx did not become healthy within 100 s.
  Diagnose:
    docker logs ecos-app
    docker logs ecos-nginx"
    fi
    sleep 5
done
ok "ecos-nginx is healthy (full stack: nginx → FPM → DB + Redis confirmed)."

check_http "healthz"            "http://localhost/healthz"    "200"
check_http "build-info"         "http://localhost/build-info" "200"
check_http "SPA /app/"          "http://localhost/app/"       "200"
check_http "api/health (Laravel)" "http://localhost/api/health" "200"

log "Verifying public/app/index.html in ecos-app..."
$COMPOSE exec -T app test -f /var/www/html/public/app/index.html \
    || fail "public/app/index.html missing in ecos-app."
ok "public/app/index.html confirmed in ecos-app."

log "Verifying public/app/index.html in ecos-nginx..."
docker exec ecos-nginx test -f /var/www/html/public/app/index.html \
    || fail "public/app/index.html missing in ecos-nginx."
ok "public/app/index.html confirmed in ecos-nginx."

log "Verifying public/build-info in ecos-nginx..."
docker exec ecos-nginx test -f /var/www/html/public/build-info \
    || fail "public/build-info missing in ecos-nginx."
ok "public/build-info confirmed in ecos-nginx."

# =============================================================================
# 12. Deployment validation — PASS / FAIL table
#     Reads /api/health JSON and reports each dependency individually.
#     All checks must pass or the deploy is declared failed.
# =============================================================================
section "Deployment validation"

HEALTH_JSON=$(curl -s --max-time 10 "http://localhost/api/health" 2>/dev/null || echo '{}')
VALIDATION_PASS=true

_health_field() {
    python3 -c "
import json, sys
try:
    d = json.loads('''${HEALTH_JSON}''')
    v = d.get('$1')
    print('true' if v is True else ('false' if v is False else str(v)))
except Exception:
    print('error')
" 2>/dev/null || echo "error"
}

_validate() {
    local label="$1"
    local result="$2"
    if [ "${result}" = "true" ]; then
        printf '\033[0;32m  ✓ PASS\033[0m  %s\n' "${label}"
    else
        printf '\033[0;31m  ✗ FAIL\033[0m  %s\n' "${label}"
        VALIDATION_PASS=false
    fi
}

printf '\n'

# Laravel boot — already verified above; check that /api/health returned a status field
HEALTH_STATUS=$(_health_field status)
_validate "Laravel boot"  "$([ "${HEALTH_STATUS}" != "error" ] && echo true || echo false)"

_validate "MySQL"         "$(_health_field database)"
_validate "Redis"         "$(_health_field redis)"
_validate "Queue"         "$(_health_field queue)"
_validate "Storage"       "$(_health_field storage)"
_validate "Scheduler"     "$(_health_field scheduler)"

# Storage symlink — verify independently inside the container
SYMLINK_OK="false"
$COMPOSE exec -T app test -L /var/www/html/public/storage 2>/dev/null && SYMLINK_OK="true" || true
_validate "Storage symlink" "${SYMLINK_OK}"

printf '\n'
if [ "$VALIDATION_PASS" = "true" ]; then
    ok "All validation checks passed."
else
    fail "One or more validation checks failed — see FAIL lines above."
fi

# =============================================================================
# 13. Image cleanup
# =============================================================================
section "Cleanup"
log "Pruning dangling images..."
docker image prune -f
ok "Image prune complete."

# =============================================================================
# Final status + deployment traceability
# =============================================================================
section "Deployment summary"
$COMPOSE ps

APP_IMAGE_SHA=$(docker inspect --format '{{.Id}}' ecos-erp/app:latest   2>/dev/null | cut -c1-19 || echo "unknown")
NGX_IMAGE_SHA=$(docker inspect --format '{{.Id}}' ecos-erp/nginx:latest 2>/dev/null | cut -c1-19 || echo "unknown")

log "━━━ Build info (from image) ━━━"
$COMPOSE exec -T app cat /var/www/html/.build-info 2>/dev/null || log "(not available)"

log "━━━ /api/health (live) ━━━"
HEALTH_RESPONSE=$(curl -s --max-time 10 http://localhost/api/health 2>/dev/null || echo '{"error":"unreachable"}')
printf '%s\n' "${HEALTH_RESPONSE}" | python3 -m json.tool 2>/dev/null || printf '%s\n' "${HEALTH_RESPONSE}"

log "━━━ /build-info (static) ━━━"
BUILD_INFO_RESPONSE=$(curl -s --max-time 10 http://localhost/build-info 2>/dev/null || echo '{"error":"unreachable"}')
printf '%s\n' "${BUILD_INFO_RESPONSE}" | python3 -m json.tool 2>/dev/null || printf '%s\n' "${BUILD_INFO_RESPONSE}"

ok "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
ok "Deployment finished successfully."
ok ""
ok "  Version        : ${APP_VERSION}"
ok "  Git SHA        : ${GIT_SHA}"
ok "  Built at       : ${BUILD_TIME}"
ok "  Commit message : ${GIT_MSG}"
ok ""
ok "  Image (app)    : ${APP_IMAGE_SHA}…"
ok "  Image (nginx)  : ${NGX_IMAGE_SHA}…"
ok ""
ok "  Live health    : curl http://localhost/api/health"
ok "  Build info     : curl http://localhost/build-info"
ok "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
