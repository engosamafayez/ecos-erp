#!/usr/bin/env bash
###############################################################################
# ECOS ERP — Production deployment script
#
# Usage
#   ./deploy.sh             Deploy without running migrations
#   ./deploy.sh --migrate   Deploy AND run database migrations
#
# Requires: git  ·  docker (Engine 24+)  ·  docker compose v2  ·  curl
#
# Steps
#   1.  Environment validation
#   2.  Show current commit
#   3.  git pull origin main
#   4.  Build images (GIT_SHA + APP_VERSION baked in)
#   5.  Recreate containers (rolling; removes orphans)
#   6.  Show container status
#   7.  Wait for app healthcheck
#   8.  Database migrations  [--migrate flag only]
#   9.  php artisan optimize
#   10. Prune dangling images
#   11. Full health verification (6 checks)
#   12. Print build metadata from inside the container
#
# Aborts immediately on any failure (set -euo pipefail).
###############################################################################
set -euo pipefail

# ---------------------------------------------------------------------------
# Flag parsing
# ---------------------------------------------------------------------------
RUN_MIGRATIONS=false
for arg in "$@"; do
    case "$arg" in
        --migrate) RUN_MIGRATIONS=true ;;
        *) printf '\033[0;31m[deploy ✗]\033[0m Unknown argument: %s\nUsage: ./deploy.sh [--migrate]\n' "$arg" >&2; exit 1 ;;
    esac
done

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
log()     { printf '\033[0;36m[deploy]\033[0m   %s\n' "$*"; }
ok()      { printf '\033[0;32m[deploy ✓]\033[0m %s\n' "$*"; }
section() { printf '\n\033[1;37m━━━ %s ━━━\033[0m\n' "$*"; }
fail()    { printf '\033[0;31m[deploy ✗]\033[0m %s\n' "$*" >&2; exit 1; }

# Only use the production compose file — never auto-merge the dev override.
COMPOSE="docker compose -f docker-compose.yml"

# ---------------------------------------------------------------------------
# 1. Environment validation — abort before touching anything if preconditions fail
# ---------------------------------------------------------------------------
section "Environment validation"

[ -f "docker-compose.yml" ] \
    || fail "docker-compose.yml not found. Run this script from the repository root."

[ -f "backend/.env" ] \
    || fail "backend/.env not found. Create it from backend/.env.example before deploying."

# docker-compose.override.yml on a production server silently replaces the
# nginx image with the stock image + a broken bind-mount that shadows the
# baked-in SPA.  The only safe way to run production is without this file.
if [ -f "docker-compose.override.yml" ]; then
    fail "docker-compose.override.yml detected in $(pwd).
  This file is for local development only and must NOT exist on production servers.
  Remove it and retry:  rm docker-compose.override.yml"
fi

# APP_DEBUG=true in production is a critical security misconfiguration.
if grep -qE '^APP_DEBUG[[:space:]]*=[[:space:]]*true' "backend/.env" 2>/dev/null; then
    fail "APP_DEBUG=true detected in backend/.env.
  This must be set to false in production.
  Fix: sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' backend/.env"
fi

ok "Preconditions passed."

# ---------------------------------------------------------------------------
# 2. Current state
# ---------------------------------------------------------------------------
section "Pre-deployment state"
PREV_SHA=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
log "Current commit : ${PREV_SHA}"
log "Current branch : $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'unknown')"
log "Migrate flag   : ${RUN_MIGRATIONS}"

# ---------------------------------------------------------------------------
# 3. Pull latest code
# ---------------------------------------------------------------------------
section "Source update"
log "Pulling origin/main..."
git pull origin main

GIT_SHA=$(git rev-parse --short HEAD)
GIT_MSG=$(git log -1 --pretty=format:"%s" 2>/dev/null || echo "unknown")

# Derive the application version from the nearest git tag; fall back to SHA.
APP_VERSION=$(git describe --tags --exact-match 2>/dev/null \
              || git describe --tags 2>/dev/null \
              || echo "${GIT_SHA}")

log "Deploying commit  : ${GIT_SHA}"
log "Application ver.  : ${APP_VERSION}"
log "Commit message    : ${GIT_MSG}"

# ---------------------------------------------------------------------------
# 4. Build images
#    --pull       : always refresh upstream base images
#    --build-arg  : bakes commit SHA and app version into images for traceability
# ---------------------------------------------------------------------------
section "Image build"
log "Building ecos-erp/app and ecos-erp/nginx..."
log "  GIT_SHA=${GIT_SHA}  APP_VERSION=${APP_VERSION}"
$COMPOSE build --pull \
    --build-arg "GIT_SHA=${GIT_SHA}" \
    --build-arg "APP_VERSION=${APP_VERSION}"
ok "Images built."

# ---------------------------------------------------------------------------
# 5. Recreate containers
# ---------------------------------------------------------------------------
section "Container rollout"
log "Starting containers..."
$COMPOSE up -d --remove-orphans
ok "Containers started."

# ---------------------------------------------------------------------------
# 6. Container status (immediate view — some may still be starting)
# ---------------------------------------------------------------------------
section "Container status"
$COMPOSE ps

# ---------------------------------------------------------------------------
# 7. Wait for the app container to pass its PHP-FPM healthcheck
#    Timeout: 30 × 5 s = 150 s
# ---------------------------------------------------------------------------
section "Health wait"
log "Waiting for ecos-app to become healthy..."
ATTEMPTS=0
until [ "$(docker inspect --format='{{.State.Health.Status}}' ecos-app 2>/dev/null)" = "healthy" ]; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [ "$ATTEMPTS" -ge 30 ]; then
        fail "ecos-app did not become healthy after 150 s.
  Diagnose: docker logs ecos-app
  Status:   docker inspect --format '{{json .State.Health}}' ecos-app"
    fi
    printf '.'
    sleep 5
done
printf '\n'
ok "ecos-app is healthy (after $((ATTEMPTS * 5)) s)."

# ---------------------------------------------------------------------------
# 8. Database migrations [--migrate flag only]
#
# Schema changes are an explicit operator decision.
# Run this ONLY when you have confirmed the migration is safe to apply.
# ---------------------------------------------------------------------------
section "Database migrations"
if [ "$RUN_MIGRATIONS" = "true" ]; then
    log "Running php artisan migrate --force..."
    log "(--migrate flag passed: operator has confirmed schema changes are safe.)"
    $COMPOSE exec -T app php artisan migrate --force
    ok "Migrations complete."
else
    log "Skipping migrations."
    log "To apply schema changes: ./deploy.sh --migrate"
fi

# ---------------------------------------------------------------------------
# 9. Laravel optimization (always runs — refreshes caches in app-cache volume)
# ---------------------------------------------------------------------------
section "Optimization"
log "Caching config, routes, views..."
$COMPOSE exec -T app php artisan optimize
ok "Laravel optimize complete."

# ---------------------------------------------------------------------------
# 10. Prune dangling images
# ---------------------------------------------------------------------------
section "Cleanup"
log "Pruning dangling images..."
docker image prune -f
ok "Image prune complete."

# ---------------------------------------------------------------------------
# 11. Health verification — all 6 checks must pass or deployment is aborted
# ---------------------------------------------------------------------------
section "Verification"

# 11a. Final container status
log "Container status:"
$COMPOSE ps

# 11b. Nginx /healthz
log "Checking GET /healthz..."
HEALTHZ_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 http://localhost/healthz || echo "000")
[ "$HEALTHZ_CODE" = "200" ] \
    || fail "GET /healthz returned HTTP ${HEALTHZ_CODE} (expected 200). Check: docker logs ecos-nginx"
ok "GET /healthz → HTTP ${HEALTHZ_CODE}."

# 11c. React SPA entry point
log "Checking GET /app..."
SPA_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 http://localhost/app || echo "000")
[ "$SPA_CODE" = "200" ] \
    || fail "GET /app returned HTTP ${SPA_CODE} (expected 200). Check: docker logs ecos-nginx"
ok "GET /app → HTTP ${SPA_CODE}."

# 11d. Build-info endpoint
log "Checking GET /build-info..."
BUILDINFO_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 http://localhost/build-info || echo "000")
[ "$BUILDINFO_CODE" = "200" ] \
    || fail "GET /build-info returned HTTP ${BUILDINFO_CODE} (expected 200).
  The build-info file may not have been copied into the nginx image (Stage 4).
  Check: docker exec ecos-nginx ls /var/www/html/public/build-info"
ok "GET /build-info → HTTP ${BUILDINFO_CODE}."

# 11e. SPA index.html present in app container
log "Checking public/app/index.html in ecos-app..."
$COMPOSE exec -T app test -f /var/www/html/public/app/index.html \
    || fail "public/app/index.html NOT FOUND in ecos-app. Stage 3 image build may have failed."
ok "public/app/index.html confirmed in ecos-app."

# 11f. SPA index.html present in nginx container
log "Checking public/app/index.html in ecos-nginx..."
docker exec ecos-nginx test -f /var/www/html/public/app/index.html \
    || fail "public/app/index.html NOT FOUND in ecos-nginx. Stage 4 nginx build may have failed."
ok "public/app/index.html confirmed in ecos-nginx."

# ---------------------------------------------------------------------------
# 12. Build metadata
# ---------------------------------------------------------------------------
section "Build metadata"
log "Reading .build-info from ecos-app:"
$COMPOSE exec -T app cat /var/www/html/.build-info || log "(not available)"

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
section "Complete"
ok "Deployment finished successfully."
ok "Version : ${APP_VERSION}"
ok "Commit  : ${GIT_SHA}"
ok "Message : ${GIT_MSG}"
ok "Verify  : curl http://localhost/build-info"
