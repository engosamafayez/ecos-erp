#!/usr/bin/env bash
# NAME: Laravel Bootstrap
set -euo pipefail

PROJECT_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
BACKEND="$PROJECT_ROOT/backend"

if ! command -v php &>/dev/null; then
  echo "php not in PATH"
  exit 2
fi

if [[ ! -f "$BACKEND/.env" ]]; then
  echo "backend/.env not found — copy from .env.example and configure to run Laravel checks"
  exit 2
fi

cd "$BACKEND"
php artisan --version 2>&1
php artisan optimize:clear --quiet 2>&1
echo "Laravel bootstrap OK."
