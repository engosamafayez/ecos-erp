#!/usr/bin/env bash
###############################################################################
# ECOS ERP — Server-side deployment script
#
# Called by GitHub Actions via SSH after the scripts/ directory has been
# synced.  Also safe to run manually from the project root.
#
# Usage:
#   bash scripts/deploy.sh [GIT_SHA] [GIT_BRANCH] [GIT_ACTOR]
#
# Prerequisites on the server:
#   - git, docker, docker compose v2, rsync
#   - Project cloned at DEPLOY_PATH with a valid .env
#   - SSH key added to GitHub deploy keys (read access)
###############################################################################
set -euo pipefail

# ── Arguments ────────────────────────────────────────────────────────────────
GIT_SHA="${1:-HEAD}"
GIT_BRANCH="${2:-main}"
GIT_ACTOR="${3:-manual}"

# ── Paths ────────────────────────────────────────────────────────────────────
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROLLBACK_FILE="$PROJECT_DIR/.deploy_rollback"
LOG_DIR="$PROJECT_DIR/storage/logs"
DEPLOY_LOG="$LOG_DIR/deploy-$(date +%Y%m%d_%H%M%S).log"

mkdir -p "$LOG_DIR"
cd "$PROJECT_DIR"

# ── Helpers ──────────────────────────────────────────────────────────────────
log()  { local msg="[$(date '+%H:%M:%S')] $*"; echo "$msg"; echo "$msg" >> "$DEPLOY_LOG"; }
step() { log ""; log "==> [$1/8] $2"; }
fail() { log ""; log "FAILED: $*"; exit 1; }

log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log " ECOS ERP Deployment"
log " SHA:    $GIT_SHA"
log " Branch: $GIT_BRANCH"
log " Actor:  $GIT_ACTOR"
log " Log:    $DEPLOY_LOG"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# ── Save rollback point ───────────────────────────────────────────────────────
PREV_SHA="$(git rev-parse HEAD 2>/dev/null || echo '')"
if [ -n "$PREV_SHA" ]; then
    echo "$PREV_SHA" > "$ROLLBACK_FILE"
    log "Rollback point saved: $PREV_SHA"
fi

# ── Step 1: Pull latest code ──────────────────────────────────────────────────
step 1 "Pulling latest code"
git fetch --all --tags --prune
git checkout "$GIT_BRANCH"
git pull origin "$GIT_BRANCH" --ff-only \
    || fail "git pull failed — local branch may have diverged from origin/$GIT_BRANCH"
log "HEAD is now: $(git rev-parse HEAD)"

# ── Step 2: PHP dependencies ─────────────────────────────────────────────────
step 2 "Installing PHP dependencies (no-dev)"
docker compose exec -T app composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    || fail "composer install failed"

# ── Step 3: Database migrations ──────────────────────────────────────────────
step 3 "Running database migrations"
docker compose exec -T app php artisan migrate --force \
    || fail "php artisan migrate failed"

# ── Step 4: Frontend build ────────────────────────────────────────────────────
# Run inside a temporary node:22-alpine container.
# Mount frontend/ and backend/public/ so vite can write to
# backend/public/app/ (outDir: '../backend/public/app' in vite.config.ts).
# Nginx reads from backend/public/ via bind mount — files are live immediately.
step 4 "Building frontend assets (npm ci + vite build)"
docker run --rm \
    --name ecos-frontend-build \
    -v "$PROJECT_DIR/frontend:/workspace/frontend:ro" \
    -v "$PROJECT_DIR/backend/public:/workspace/backend/public" \
    -w /workspace/frontend \
    node:22-alpine \
    sh -c "npm ci --no-audit --no-fund && npm run build" \
    || fail "Frontend build failed"
log "Assets written to backend/public/app/"

# ── Step 5: Restart containers ────────────────────────────────────────────────
# Picks up any changes to docker-compose.yml (new services, changed env, etc.).
# --wait blocks until all healthchecks pass, ensuring the app is ready before
# the next step runs artisan commands.
step 5 "Restarting containers (docker compose up -d)"
docker compose up -d --remove-orphans --wait \
    || fail "docker compose up failed"

# ── Step 6: Optimize Laravel ─────────────────────────────────────────────────
# Run AFTER `up -d` because the container entrypoint clears caches on start.
step 6 "Optimizing Laravel (config + route + view cache)"
docker compose exec -T app php artisan optimize \
    || fail "php artisan optimize failed"

# ── Step 7: Restart queue workers ────────────────────────────────────────────
step 7 "Signaling queue workers to restart"
docker compose exec -T app php artisan queue:restart \
    || log "WARNING: queue:restart failed (workers may not be running yet)"

# ── Step 8: Health check ─────────────────────────────────────────────────────
step 8 "Running health check"
bash "$(dirname "${BASH_SOURCE[0]}")/healthcheck.sh" "http://localhost" \
    || fail "Health check failed — deployment may be broken"

# ── Report ───────────────────────────────────────────────────────────────────
log ""
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log " Deployment SUCCESSFUL"
log " SHA:     $(git rev-parse HEAD)"
log " Branch:  $GIT_BRANCH"
log " Actor:   $GIT_ACTOR"
log " Time:    $(date '+%Y-%m-%d %H:%M:%S')"
log " Log:     $DEPLOY_LOG"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
