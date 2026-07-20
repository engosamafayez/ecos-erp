#!/usr/bin/env bash
# NAME: Laravel Pint
set -euo pipefail

PROJECT_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
BACKEND="$PROJECT_ROOT/backend"

if ! command -v php &>/dev/null; then
  echo "php not in PATH"
  exit 2
fi

if [[ ! -f "$BACKEND/vendor/bin/pint" ]]; then
  echo "vendor/bin/pint not found — run: cd backend && composer install"
  exit 2
fi

cd "$BACKEND"
# --test: report violations without modifying files (exits 1 if any found)
php vendor/bin/pint --test 2>&1
