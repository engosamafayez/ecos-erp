#!/usr/bin/env bash
# NAME: ESLint
#
# Mode-aware scope (the runner passes MODE as $2):
#   pre-commit          → lint ONLY the files staged in this commit
#   pre-push|ci|full    → lint the ENTIRE repository (unchanged behaviour)
#
# Guarantees preserved in BOTH scopes:
#   • The exact same eslint.config.js is used — every rule stays at its
#     configured severity (ERROR remains ERROR). Nothing is disabled.
#   • No file is added to any ignore list; at pre-commit we simply lint the
#     files that belong to the commit instead of the whole tree.
#   • A staged file that introduces a new violation still fails the commit;
#     historical debt in files outside the commit no longer blocks it.
set -euo pipefail

PROJECT_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
MODE="${2:-full}"
FRONTEND="$PROJECT_ROOT/frontend"

if ! command -v node &>/dev/null; then
  echo "node not in PATH — install Node.js 22+"
  exit 2
fi

if [[ ! -d "$FRONTEND/node_modules" ]]; then
  echo "frontend/node_modules not found — run: cd frontend && npm install"
  exit 2
fi

cd "$FRONTEND"
ESLINT="node_modules/.bin/eslint"

if [[ "$MODE" == "pre-commit" ]]; then
  # ── Staged-scope lint ──────────────────────────────────────────────────────
  # Collect the JS/TS files staged for this commit (Added/Copied/Modified/Renamed),
  # normalise their paths to be relative to the frontend/ project root.
  mapfile -t STAGED < <(
    git -C "$PROJECT_ROOT" diff --cached --name-only --diff-filter=ACMR \
      | grep -E '^frontend/.*\.(ts|tsx|js|jsx|cjs|mjs)$' \
      | sed 's|^frontend/||' \
      || true
  )

  # Keep only paths that still exist on disk (a rename can leave a stale entry).
  FILES=()
  for f in "${STAGED[@]:-}"; do
    [[ -n "$f" && -f "$f" ]] && FILES+=("$f")
  done

  if [[ ${#FILES[@]} -eq 0 ]]; then
    echo "no staged frontend JS/TS files — nothing to lint"
    exit 2   # SKIP
  fi

  echo "Linting ${#FILES[@]} staged file(s) with the full ruleset:"
  printf '  %s\n' "${FILES[@]}"
  echo
  # Same config, same rules. eslint exits non-zero on any error → commit blocked.
  "$ESLINT" "${FILES[@]}"
else
  # ── Full-repository lint — unchanged for pre-push / ci / full ───────────────
  npm run lint 2>&1
fi
