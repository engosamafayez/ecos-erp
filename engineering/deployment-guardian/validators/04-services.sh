#!/usr/bin/env bash
# NAME: Services Health
# Checks the health status of all Docker services.
# Reports which containers are running, healthy, or degraded.
# Does NOT start containers — only inspects current state.
set -euo pipefail

PROJECT_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"

if ! command -v docker &>/dev/null; then
  echo "docker not in PATH"
  exit 2
fi

if ! docker info &>/dev/null 2>&1; then
  echo "Docker daemon is not running"
  exit 2
fi

FAILURES=0
DEGRADED=0

fail()    { echo "FAIL: $*";    FAILURES=$((FAILURES + 1)); }
degraded(){ echo "DEGRADED: $*"; DEGRADED=$((DEGRADED + 1)); }
ok()      { echo "  OK: $*"; }
info()    { echo "INFO: $*"; }

# ── Required containers ────────────────────────────────────────────────────────
declare -A REQUIRED_CONTAINERS=(
  [ecos-app]="app"
  [ecos-nginx]="nginx"
  [ecos-mysql]="mysql"
  [ecos-redis]="redis"
)

all_running=1

for container in "${!REQUIRED_CONTAINERS[@]}"; do
  service="${REQUIRED_CONTAINERS[$container]}"

  # Check if container exists
  state=$(docker inspect --format '{{.State.Status}}' "$container" 2>/dev/null || echo "missing")

  if [[ "$state" == "missing" ]]; then
    fail "Container '$container' ($service) does not exist — run: docker compose up -d"
    all_running=0
    continue
  fi

  if [[ "$state" != "running" ]]; then
    fail "Container '$container' is in state '$state' (expected: running)"
    all_running=0
    continue
  fi

  # Check health status (for containers with healthcheck)
  health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' \
    "$container" 2>/dev/null || echo "unknown")

  case "$health" in
    healthy)
      ok "$container ($service): running and healthy"
      ;;
    starting)
      info "$container ($service): running, health check still starting"
      ;;
    unhealthy)
      degraded "$container ($service): running but UNHEALTHY"
      # Print last health log
      last_log=$(docker inspect --format \
        '{{if .State.Health}}{{range .State.Health.Log}}{{.Output}}{{end}}{{end}}' \
        "$container" 2>/dev/null | tail -1 | tr -d '\n')
      [[ -n "$last_log" ]] && echo "       Last health log: $last_log"
      ;;
    no-healthcheck)
      info "$container ($service): running (no healthcheck configured)"
      ;;
    *)
      info "$container ($service): running (health=$health)"
      ;;
  esac
done

# ── Check queue worker is running inside app container ───────────────────────
if [[ $all_running -eq 1 ]]; then
  queue_running=$(docker exec ecos-app pgrep -cf "artisan queue:work" 2>/dev/null || echo "0")
  if [[ "$queue_running" -gt 0 ]]; then
    ok "Queue worker is running (pgrep count: $queue_running)"
  else
    fail "Queue worker is NOT running inside ecos-app — check Supervisor config"
  fi

  # Check scheduler is running
  scheduler_running=$(docker exec ecos-app pgrep -cf "artisan schedule" 2>/dev/null || echo "0")
  if [[ "$scheduler_running" -gt 0 ]]; then
    ok "Scheduler is running (pgrep count: $scheduler_running)"
  else
    degraded "Scheduler is NOT running inside ecos-app — check Supervisor config"
  fi
fi

# ── Docker compose ps summary ─────────────────────────────────────────────────
printf '\n'
docker compose -f "$PROJECT_ROOT/docker-compose.yml" ps 2>/dev/null || true
printf '\n'

# ── Summary ───────────────────────────────────────────────────────────────────
if [[ $FAILURES -gt 0 ]]; then
  printf '%d service failure(s), %d degraded\n' "$FAILURES" "$DEGRADED"
  exit 1
fi

if [[ $DEGRADED -gt 0 ]]; then
  printf '0 failures, %d degraded service(s)\n' "$DEGRADED"
  exit 1
fi

exit 0
