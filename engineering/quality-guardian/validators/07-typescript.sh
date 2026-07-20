#!/usr/bin/env bash
# NAME: TypeScript
set -euo pipefail

PROJECT_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
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
# Use the local tsc directly (faster than npx, avoids version resolution).
# tsconfig.app.json has noEmit:true + allowImportingTsExtensions — tsc -b
# type-checks all project references without emitting any files.
# --force bypasses the incremental build cache for a clean check.
# || exit 1 remaps tsc's exit code 2 (DiagnosticsPresent_OutputsGenerated)
# to exit 1 so the guardian treats it as FAIL, not SKIP.
node_modules/.bin/tsc -b --force 2>&1 || exit 1
