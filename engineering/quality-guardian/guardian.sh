#!/usr/bin/env bash
# ECOS Engineering Guardian — main runner
#
# Usage:
#   ./guardian.sh [mode]
#
# Modes:
#   pre-commit   PHP syntax, Composer, ESLint, TypeScript          (fast, ~30s)
#   pre-push     + Laravel bootstrap, Pint, PHPStan, Vite build    (~2min)
#   ci | full    + Docker production build                         (~10min)
#
# Exit codes:
#   0   all checks passed
#   1   one or more checks failed
set -euo pipefail

GUARDIAN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$GUARDIAN_DIR/../.." && pwd)"
VALIDATOR_DIR="$GUARDIAN_DIR/validators"

source "$GUARDIAN_DIR/lib/colors.sh"
source "$GUARDIAN_DIR/lib/report.sh"
source "$GUARDIAN_DIR/config.sh"

MODE="${1:-full}"

case "$MODE" in
  pre-commit)
    VALIDATORS=(01-php-syntax 02-composer 06-eslint 07-typescript)
    ;;
  pre-push)
    VALIDATORS=(01-php-syntax 02-composer 03-laravel 04-pint 05-phpstan 06-eslint 07-typescript 08-vite-build)
    ;;
  ci|full)
    VALIDATORS=(01-php-syntax 02-composer 03-laravel 04-pint 05-phpstan 06-eslint 07-typescript 08-vite-build 09-docker)
    ;;
  *)
    printf 'Usage: %s [pre-commit|pre-push|ci|full]\n' "$0" >&2
    exit 1
    ;;
esac

STARTED_AT=$(date '+%H:%M:%S')
FAILED_COUNT=0

# ─── Header ──────────────────────────────────────────────────────────────────
printf '\n'
printf '%s━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━%s\n' "$C_BOLD" "$C_RESET"
printf '  %sECOS Engineering Guardian%s  %smode: %s  %s%s\n' \
  "$C_BOLD" "$C_RESET" "$C_DIM" "$MODE" "$STARTED_AT" "$C_RESET"
printf '%s━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━%s\n' "$C_BOLD" "$C_RESET"
printf '\n'

# ─── Run validators ──────────────────────────────────────────────────────────
for validator in "${VALIDATORS[@]}"; do
  script="$VALIDATOR_DIR/${validator}.sh"

  if [[ ! -f "$script" ]]; then
    printf '  %s%s%s  %-30s not found\n' "$C_YELLOW" "$ICO_WARN" "$C_RESET" "$validator"
    continue
  fi

  name=$(grep -m1 '^# NAME:' "$script" 2>/dev/null | sed 's/^# NAME: *//')
  name="${name:-$validator}"

  printf '  %-30s' "$name"

  START_TIME=$SECONDS
  output=$(bash "$script" "$PROJECT_ROOT" 2>&1) && exit_code=0 || exit_code=$?
  DURATION=$((SECONDS - START_TIME))

  case "$exit_code" in
    0)
      printf '%s%s PASS%s  %s%ds%s\n' "$C_GREEN" "$ICO_PASS" "$C_RESET" "$C_DIM" "$DURATION" "$C_RESET"
      _report_add "$name" "pass" "$DURATION" ""
      ;;
    2)
      printf '%s%s SKIP  %ds%s\n' "$C_DIM" "$ICO_SKIP" "$DURATION" "$C_RESET"
      _report_add "$name" "skip" "$DURATION" ""
      ;;
    *)
      printf '%s%s FAIL%s  %s%ds%s\n' "$C_RED" "$ICO_FAIL" "$C_RESET" "$C_DIM" "$DURATION" "$C_RESET"
      _report_add "$name" "fail" "$DURATION" "$output"
      FAILED_COUNT=$((FAILED_COUNT + 1))
      ;;
  esac
done

# ─── Failure details ─────────────────────────────────────────────────────────
_print_failures

# ─── Footer ──────────────────────────────────────────────────────────────────
printf '\n'
printf '%s━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━%s\n' "$C_BOLD" "$C_RESET"
if [[ "$FAILED_COUNT" -eq 0 ]]; then
  printf '  %s%sAll checks passed.%s\n' "$C_GREEN" "$C_BOLD" "$C_RESET"
else
  printf '  %s%s%d check(s) failed — commit/push blocked.%s\n' \
    "$C_RED" "$C_BOLD" "$FAILED_COUNT" "$C_RESET"
fi
printf '%s━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━%s\n' "$C_BOLD" "$C_RESET"
printf '\n'

[[ "$FAILED_COUNT" -eq 0 ]] && exit 0 || exit 1
