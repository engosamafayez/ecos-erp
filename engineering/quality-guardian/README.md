# ECOS Engineering Guardian

Prevents broken code from entering the repository by running a quality pipeline
before every commit and push.

## Install

```bash
bash engineering/quality-guardian/setup.sh
```

This copies the pre-commit and pre-push hooks into `.git/hooks/`. Re-running is
safe — existing hooks are backed up before being replaced.

## Run manually

```bash
# Fast pre-commit checks only (~30s)
bash engineering/quality-guardian/guardian.sh pre-commit

# Full pipeline without Docker (~2min)
bash engineering/quality-guardian/guardian.sh pre-push

# Full pipeline including Docker build (~10min)
bash engineering/quality-guardian/guardian.sh full
```

## Validator pipeline

| # | Name | pre-commit | pre-push | ci/full |
|---|------|:---:|:---:|:---:|
| 01 | PHP Syntax | ✓ | ✓ | ✓ |
| 02 | Composer Validate | ✓ | ✓ | ✓ |
| 03 | Laravel Bootstrap | — | ✓ | ✓ |
| 04 | Laravel Pint | — | ✓ | ✓ |
| 05 | PHPStan | — | ✓ | ✓ |
| 06 | ESLint | ✓ | ✓ | ✓ |
| 07 | TypeScript | ✓ | ✓ | ✓ |
| 08 | Vite Production Build | — | ✓ | ✓ |
| 09 | Docker Build | — | — | ✓ |

### Exit codes for each validator

| Code | Meaning |
|------|---------|
| `0` | PASS |
| `1` | FAIL — guardian blocks commit/push |
| `2` | SKIP — required tool not installed or precondition not met |

SKIP never blocks a commit. Fix the precondition (install PHP, run `npm install`,
create `.env`) to promote a SKIP to an active check.

## Prerequisites

| Tool | Required for | Install |
|------|-------------|---------|
| PHP 8.4+ | Validators 01–05 | `winget install PHP.PHP` or system package manager |
| Composer | Validator 02 | https://getcomposer.org |
| `backend/.env` | Validators 03, 05 | `cp backend/.env.example backend/.env` then configure |
| Node.js 22+ | Validators 06–08 | https://nodejs.org |
| `frontend/node_modules` | Validators 06–08 | `cd frontend && npm install` |
| Docker Desktop | Validator 09 | https://docs.docker.com/desktop/ |

Validators 01–05 also require `composer install` to have been run inside `backend/`
(they use `backend/vendor/bin/pint` and `backend/vendor/bin/phpstan`).

## Bypass (emergency only)

```bash
git commit --no-verify   # skip pre-commit
git push --no-verify     # skip pre-push
```

Bypasses must be justified in the commit message or PR description.

## Add a new validator

1. Create `validators/NN-name.sh` with `# NAME: Display Name` on the second line
2. Exit `0` (pass), `1` (fail), or `2` (skip)
3. Add `NN-name` to the appropriate mode arrays in `guardian.sh`

## Override paths

```bash
export GUARDIAN_BACKEND_DIR=/custom/path/to/backend
export GUARDIAN_FRONTEND_DIR=/custom/path/to/frontend
export GUARDIAN_COMPOSE_FILE=/custom/docker-compose.yml
bash engineering/quality-guardian/guardian.sh full
```

## Disable colors

```bash
NO_COLOR=1 bash engineering/quality-guardian/guardian.sh pre-commit
```
