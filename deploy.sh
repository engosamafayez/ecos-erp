#!/usr/bin/env bash
# =============================================================================
# ECOS ERP — Production deployment script
#
# Usage
#   ./deploy.sh             Deploy (no migrations)
#   ./deploy.sh --migrate   Deploy and run database migrations
#
# Requirements
#   git  docker (Engine 24+)  docker compose v2  curl
#
# Flow
#   1.  Validate environment
#   2.  Tag current images as :rollback
#   3.  git pull origin main
#   4.  docker compose build
#   5.  Image self-test (Rule 10: artisan works without composer)
#   6.  docker compose up -d
#   7.  Wait for app to become healthy
#   8.  [--migrate] php artisan migrate --force
#   9.  Verify all endpoints
#   10. Rollback automatically on any failure
#   11. docker image prune
#
# Safety
#   set -euo pipefail: any unhandled error aborts the script.
#   ERR trap triggers rollback if containers were updated before the failure.
#
# Immutability notes (TASK-INFRA-002):
#   The runtime container never runs composer or artisan cache commands.
#   Bootstrap cache (package:discover, route:cache, event:cache) is baked
#   into the image during docker build. MIGRATE_ON_START defaults to false
#   in the entrypoint; migrations only run when --migrate is passed here.
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
section() { printf '\n\033[1;37m━━━ %s ━━━\033[0m\n' "$*"; }
fail()    { printf '\033[0;31m[deploy ✗]\033[0m %s\n' "$*" >&2; exit 1; }

# Always target the production compose file — never auto-merge the dev override.
COMPOSE="docker compose -f docker-compose.yml"

# Track whether containers have been updated so the ERR trap knows whether
# a rollback attempt is meaningful.
CONTAINERS_UPDATED=false

# =============================================================================
# Rollback
# Called automatically by the ERR trap if the deploy fails after containers
# were updated. Restores the :rollback image tags and restarts.
# =============================================================================
rollback() {
    local code=$?
    [ $code -eq 0 ] && return       # successful exit — nothing to do
    [ "$CONTAINERS_UPDATED" = "false" ] && return   # failed before any changes

    printf '\n\033[0;31m[deploy ROLLBACK]\033[0m Deploy failed (exit %s) — restoring previous images...\n' "$code" >&2

    local rolled=false
    if docker image inspect ecos-erp/app:rollback   >/dev/null 2>&1; then
        docker tag ecos-erp/app:rollback   ecos-erp/app:latest   && rolled=true
    fi
    if docker image inspect ecos-erp/nginx:rollback >/dev/null 2>&1; then
        docker tag ecos-erp/nginx:rollback ecos-erp/nginx:latest && rolled=true
    fi

    if [ "$rolled" = "true" ]; then
        $COMPOSE up -d --no-build 2>/dev/null || true
        printf '\033[0;33m[deploy ROLLBACK]\033[0m Previous version restored.\n' >&2
    else
        printf '\033[0;33m[deploy ROLLBACK]\033[0m No :rollback images found — manual intervention required.\n' >&2
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

# The override file on a production server silently replaces the nginx image
# with the stock image + a broken bind-mount that shadows the baked-in SPA.
if [ -f "docker-compose.override.yml" ]; then
    fail "docker-compose.override.yml detected.
  This file is for LOCAL DEVELOPMENT ONLY and must not exist on production servers.
  Remove it:  rm docker-compose.override.yml"
fi

# APP_DEBUG=true in production is a critical security misconfiguration.
if grep -qE '^APP_DEBUG[[:space:]]*=[[:space:]]*true' "backend/.env" 2>/dev/null; then
    fail "APP_DEBUG=true detected in backend/.env.
  Set it to false before deploying:
    sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' backend/.env"
fi

ok "Environment validation passed."

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
#    If any of these fail, the build fails here (Rule 8).
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
# 5. Image self-test (TASK-INFRA-002 Rule 10)
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
# Schema changes are an explicit operator decision.
# The entrypoint does NOT auto-migrate (MIGRATE_ON_START defaults to false).
# Migrations are always run via this script to ensure they happen after the
# app is confirmed healthy and before endpoint verification.
# =============================================================================
section "Database migrations"
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    log "Running php artisan migrate --force..."
    log "(--migrate flag: operator has confirmed schema changes are safe to apply.)"
    $COMPOSE exec -T app php artisan migrate --force
    ok "Migrations complete."
else
    log "Skipping migrations."
    log "To apply schema changes on next deploy: ./deploy.sh --migrate"
fi

# =============================================================================
# 10. Endpoint verification
#    All checks must pass or the script aborts (triggering rollback via trap).
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

# Wait for nginx to also become healthy (it depends_on app:healthy)
log "Waiting for ecos-nginx to become healthy..."
NGINX_ATTEMPTS=0
until [ "$(docker inspect --format='{{.State.Health.Status}}' ecos-nginx 2>/dev/null)" = "healthy" ]; do
    NGINX_ATTEMPTS=$((NGINX_ATTEMPTS + 1))
    if [ "${NGINX_ATTEMPTS}" -ge 20 ]; then
        fail "ecos-nginx did not become healthy within 100 s.
  Note: nginx healthcheck now uses /api/health (real Laravel endpoint).
  If this fails, check that PHP-FPM can respond to /api/health requests:
    docker logs ecos-app
    docker logs ecos-nginx"
    fi
    sleep 5
done
ok "ecos-nginx is healthy (full stack: nginx → FPM → DB + Redis confirmed)."

check_http "healthz"    "http://localhost/healthz"    "200"
check_http "build-info" "http://localhost/build-info" "200"
check_http "SPA /app/"  "http://localhost/app/"       "200"

# /api/health now returns 200 only when DB + Redis + queue are all healthy.
# A 503 here means the application is degraded — treat as deploy failure.
check_http "api/health (Laravel)" "http://localhost/api/health" "200"

# Verify SPA and build-info files are present inside both containers
log "Verifying public/app/index.html in ecos-app..."
$COMPOSE exec -T app test -f /var/www/html/public/app/index.html \
    || fail "public/app/index.html missing in ecos-app. Stage 3 build may have failed."
ok "public/app/index.html confirmed in ecos-app."

log "Verifying public/app/index.html in ecos-nginx..."
docker exec ecos-nginx test -f /var/www/html/public/app/index.html \
    || fail "public/app/index.html missing in ecos-nginx. Stage 4 build may have failed."
ok "public/app/index.html confirmed in ecos-nginx."

log "Verifying public/build-info in ecos-nginx..."
docker exec ecos-nginx test -f /var/www/html/public/build-info \
    || fail "public/build-info missing in ecos-nginx."
ok "public/build-info confirmed in ecos-nginx."

# =============================================================================
# 11. Image cleanup
# =============================================================================
section "Cleanup"
log "Pruning dangling images..."
docker image prune -f
ok "Image prune complete."

# =============================================================================
# Final status + full deployment traceability (TASK-INFRA-003 Rule 5)
# =============================================================================
section "Deployment summary"
$COMPOSE ps

# Collect image digest SHAs for traceability
APP_IMAGE_SHA=$(docker inspect --format '{{.Id}}' ecos-erp/app:latest   2>/dev/null | cut -c1-19 || echo "unknown")
NGX_IMAGE_SHA=$(docker inspect --format '{{.Id}}' ecos-erp/nginx:latest 2>/dev/null | cut -c1-19 || echo "unknown")

# Print build-info from the running app container
log "━━━ Build info (from image) ━━━"
$COMPOSE exec -T app cat /var/www/html/.build-info 2>/dev/null || log "(not available)"

# Print /api/health (real DB + Redis + queue check)
log "━━━ /api/health (live) ━━━"
HEALTH_RESPONSE=$(curl -s --max-time 10 http://localhost/api/health 2>/dev/null || echo '{"error":"unreachable"}')
printf '%s\n' "${HEALTH_RESPONSE}" | python3 -m json.tool 2>/dev/null || printf '%s\n' "${HEALTH_RESPONSE}"

# Print /build-info (static JSON baked into nginx image)
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
