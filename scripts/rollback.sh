#!/usr/bin/env bash
###############################################################################
# ECOS ERP — Rollback script
#
# Reverts the running application to a previous Git commit.
#
# Usage:
#   bash scripts/rollback.sh              # reverts to last recorded rollback point
#   bash scripts/rollback.sh <git-sha>    # reverts to a specific commit
#
# IMPORTANT — Database migrations are NOT automatically reversed.
# If the previous commit had schema changes, run:
#   docker compose exec app php artisan migrate:rollback
# after this script completes.
###############################################################################
set -euo pipefail

TARGET_SHA="${1:-}"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROLLBACK_FILE="$PROJECT_DIR/.deploy_rollback"
LOG_DIR="$PROJECT_DIR/storage/logs"
ROLLBACK_LOG="$LOG_DIR/rollback-$(date +%Y%m%d_%H%M%S).log"

mkdir -p "$LOG_DIR"
cd "$PROJECT_DIR"

log()  { local msg="[$(date '+%H:%M:%S')] $*"; echo "$msg"; echo "$msg" >> "$ROLLBACK_LOG"; }
fail() { log ""; log "FAILED: $*"; exit 1; }

# ── Resolve target SHA ───────────────────────────────────────────────────────
if [ -z "$TARGET_SHA" ]; then
    if [ -f "$ROLLBACK_FILE" ]; then
        TARGET_SHA="$(cat "$ROLLBACK_FILE")"
        log "Using saved rollback point: $TARGET_SHA"
    else
        fail "No rollback point found. Provide a SHA: bash scripts/rollback.sh <sha>"
    fi
fi

CURRENT_SHA="$(git rev-parse HEAD)"

if [ "$TARGET_SHA" = "$CURRENT_SHA" ]; then
    log "Already at $TARGET_SHA — nothing to roll back."
    exit 0
fi

log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log " ECOS ERP Rollback"
log " From:   $CURRENT_SHA"
log " To:     $TARGET_SHA"
log " Log:    $ROLLBACK_LOG"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Save the current SHA so a re-rollback can get back here.
echo "$CURRENT_SHA" > "$ROLLBACK_FILE"

# ── Step 1: Reset to target commit ───────────────────────────────────────────
log ""
log "==> [1/6] Resetting to $TARGET_SHA"
git fetch --all --tags
git reset --hard "$TARGET_SHA" \
    || fail "git reset --hard failed"
log "HEAD is now: $(git rev-parse HEAD)"

# ── Step 2: PHP dependencies ─────────────────────────────────────────────────
log ""
log "==> [2/6] Restoring PHP dependencies"
docker compose exec -T app composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    || fail "composer install failed"

# ── Step 3: Rebuild frontend assets ──────────────────────────────────────────
log ""
log "==> [3/6] Rebuilding frontend assets"
docker run --rm \
    --name ecos-frontend-rollback \
    -v "$PROJECT_DIR/frontend:/workspace/frontend:ro" \
    -v "$PROJECT_DIR/backend/public:/workspace/backend/public" \
    -w /workspace/frontend \
    node:22-alpine \
    sh -c "npm ci --no-audit --no-fund && npm run build" \
    || fail "Frontend build failed"

# ── Step 4: Restart containers ────────────────────────────────────────────────
log ""
log "==> [4/6] Restarting containers"
docker compose up -d --remove-orphans --wait \
    || fail "docker compose up failed"

# ── Step 5: Optimize Laravel ─────────────────────────────────────────────────
log ""
log "==> [5/6] Optimizing Laravel"
docker compose exec -T app php artisan optimize \
    || fail "php artisan optimize failed"
docker compose exec -T app php artisan queue:restart || true

# ── Step 6: Health check ─────────────────────────────────────────────────────
log ""
log "==> [6/6] Running health check"
bash "$(dirname "${BASH_SOURCE[0]}")/healthcheck.sh" "http://localhost" \
    || fail "Health check failed after rollback"

log ""
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log " Rollback SUCCESSFUL"
log " Running SHA: $(git rev-parse HEAD)"
log ""
log " NOTE: Database migrations were NOT reversed."
log " If you need to reverse schema changes:"
log "   docker compose exec app php artisan migrate:rollback"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
