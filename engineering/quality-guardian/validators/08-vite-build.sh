#!/usr/bin/env bash
# NAME: Vite Production Build
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
# npm run build runs: tsc -b && vite build
# tsc -b catches type errors; vite build catches import/bundle errors.
npm run build 2>&1
