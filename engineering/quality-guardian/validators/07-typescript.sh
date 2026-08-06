#!/usr/bin/env bash
# NAME: TypeScript
#
# Mode-aware scope (the runner passes MODE as $2):
#   pre-commit          → type-check the staged files (+ their imports) and FAIL
#                         only if a staged file carries a diagnostic.
#   pre-push|ci|full    → clean, whole-repository type-check (unchanged).
#
# Type safety is never reduced:
#   • At pre-commit we build a throw-away project that EXTENDS tsconfig.app.json,
#     so every compiler option is byte-for-byte identical (paths, jsx, strict,
#     lib, allowImportingTsExtensions, …). Nothing is relaxed and TypeScript is
#     never skipped. We only narrow the ROOT file set to the staged files, which
#     makes tsc build a program from those files plus their transitive imports
#     instead of re-loading the entire ~1000-file app on every commit.
#   • A staged file with a new type error still fails the commit. The thousands
#     of historical i18n-typing errors that live in unstaged files are not this
#     commit's responsibility and no longer block unrelated work.
#
# OOM fix (the reported "FATAL ERROR: JavaScript heap out of memory"):
#   The frontend is a single large project, so a whole-program `tsc -b` re-checks
#   ~1000 files and exhausts Node's default old-space heap. Scoping the program to
#   the staged files' transitive closure bounds both peak memory and wall-time.
#   A raised Node old-space limit remains as a backstop (memory configuration
#   only — no tsc flags, no check semantics, no type-safety are altered).
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

# Backstop heap. Honour any NODE_OPTIONS already set in the environment.
export NODE_OPTIONS="${NODE_OPTIONS:-} --max-old-space-size=8192"
TSC="node_modules/.bin/tsc"

if [[ "$MODE" == "pre-commit" ]]; then
  # ── Staged-scope type-check ────────────────────────────────────────────────
  TMP="$FRONTEND/tsconfig.guardian-precommit.json"
  # The EXIT trap installed further down does not fire on SIGKILL or a closed
  # terminal, so an interrupted run strands this file next to tsconfig.app.json.
  # Sweep unconditionally on entry — before the no-staged-files early exit, or a
  # no-op run would leave a previous run's leftover in place.
  rm -f "$TMP"

  mapfile -t STAGED < <(
    git -C "$PROJECT_ROOT" diff --cached --name-only --diff-filter=ACMR \
      | grep -E '^frontend/.*\.(ts|tsx)$' \
      | sed 's|^frontend/||' \
      || true
  )

  FILES=()
  for f in "${STAGED[@]:-}"; do
    [[ -n "$f" && -f "$f" ]] && FILES+=("$f")
  done

  if [[ ${#FILES[@]} -eq 0 ]]; then
    echo "no staged frontend TS files — nothing to type-check"
    exit 2   # SKIP
  fi

  # Build a temp project scoped to the staged files. `extends` inherits every
  # compiler option from the real app config; we override only the root file set
  # and turn off composite/incremental so this is a clean single-shot check.
  trap 'rm -f "$TMP"' EXIT

  # Ambient declarations must be part of the program or the scoped check reports
  # false positives. src/i18n/types.ts carries the i18next CustomTypeOptions
  # augmentation that makes selector mode — t($ => $.key) — typed. Without it
  # every selector in a staged file is an implicit any, so a file that happens
  # to import the augmentation transitively passes while an identical one fails.
  # Measured: an untouched, already-committed file reports 59 diagnostics without
  # this and 0 with it. This widens the program only; no compiler option changes
  # and no diagnostic is suppressed.
  AMBIENT_FILES=()
  [[ -f "src/i18n/types.ts" ]] && AMBIENT_FILES+=("src/i18n/types.ts")

  files_json="$(printf '"%s",' "${AMBIENT_FILES[@]}" "${FILES[@]}" | sed 's/,$//')"
  cat > "$TMP" <<JSON
{
  "extends": "./tsconfig.app.json",
  "compilerOptions": {
    "composite": false,
    "incremental": false,
    "noEmit": true
  },
  "include": [${files_json}],
  "files": [${files_json}]
}
JSON

  echo "Type-checking ${#FILES[@]} staged file(s) with the full compiler options:"
  printf '  %s\n' "${FILES[@]}"
  echo

  set +e
  OUT="$("$TSC" -p "$TMP" --noEmit 2>&1)"
  set -e

  # Fail iff a diagnostic points at one of the staged files.
  STAGED_ERRORS=""
  for f in "${FILES[@]}"; do
    hits="$(printf '%s\n' "$OUT" | grep -F "$f(" || true)"
    [[ -n "$hits" ]] && STAGED_ERRORS+="$hits"$'\n'
  done

  if [[ -n "$(printf '%s' "$STAGED_ERRORS" | tr -d '[:space:]')" ]]; then
    echo "TypeScript diagnostics in staged files:"
    printf '%s\n' "$STAGED_ERRORS"
    exit 1
  fi

  echo "No TypeScript diagnostics in the staged file(s)."
  exit 0
else
  # ── Full-repository type-check — unchanged for pre-push / ci / full ─────────
  # Clean, whole-repo project-reference build. `|| exit 1` remaps tsc's exit
  # code 2 (DiagnosticsPresent) to 1 so the guardian treats it as FAIL.
  "$TSC" -b --force 2>&1 || exit 1
fi
