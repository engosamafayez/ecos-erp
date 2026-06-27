# ECOS ERP — Production Deployment

## Overview

ECOS ERP uses **image-based, immutable deployment**. Every production deployment
builds fresh Docker images that contain the complete, self-sufficient application:
Laravel backend, PHP dependencies, and the React SPA. No source code or build
tools exist on the production host at runtime.

---

## Why Source Bind-Mounts Are Forbidden in Production

A Docker bind-mount replaces the container's filesystem at the mounted path with
the host's directory. When `backend/` is mounted at `/var/www/html`, it
**completely shadows** everything the image copied there during build — including
`vendor/`, `public/app`, `public/build-info`, and compiled views.

**The SPA 404 problem** was caused exactly this way:

```
docker build → copies public/app into image   ✓
docker compose up → mounts ./backend/public   ← shadows public/app
nginx → GET /app/index.html → 404             ✗
```

The fix is to never mount source code in production. Persistent runtime data
(uploaded files, logs, framework cache) is the only data that belongs in a volume.

---

## Architecture

```
docker build
  ├── Stage 1 (vendor)    Composer install → /app/vendor
  ├── Stage 2 (frontend)  npm ci + vite build → /backend/public/app   [Node 22 — build only]
  ├── Stage 3 (app)       php:8.4-fpm + vendor + public/app + build-info  →  ecos-erp/app
  └── Stage 4 (nginx)     nginx:alpine + public/app + build-info          →  ecos-erp/nginx

docker compose up
  ├── ecos-app    ← ecos-erp/app   (Laravel + PHP-FPM + Supervisor)
  ├── ecos-nginx  ← ecos-erp/nginx (nginx with SPA + build-info baked in)
  ├── ecos-mysql  ← mysql:8.4
  └── ecos-redis  ← redis:7-alpine
```

Node.js 22 is installed **only** in Stage 2 (frontend build). It is intentionally
absent from Stage 3 (runtime). This keeps the production PHP image lean and
reduces the attack surface.

Both `ecos-erp/app` and `ecos-erp/nginx` are built from the same
`docker/php/Dockerfile` using multi-stage targets. The React SPA (`public/app`)
and the build-info file (`public/build-info`) are built/generated once and copied
into **both** images at build time.

---

## Volumes

| Volume | Mount path | Purpose |
|---|---|---|
| `app-storage` | `/var/www/html/storage` | Uploaded files, sessions, framework cache, logs |
| `app-cache` | `/var/www/html/bootstrap/cache` | Laravel config/route/view caches |
| `mysql-data` | `/var/lib/mysql` | MySQL data directory |
| `redis-data` | `/data` | Redis AOF persistence |

**Rule:** anything that changes at **runtime** lives in a named volume.
Anything that changes at **deploy time** is baked into the image.

---

## Deployment Lifecycle

### Prerequisites

- Docker Engine 24+ with Compose V2
- Git repository cloned at `/opt/ecos-erp`
- `backend/.env` with `APP_DEBUG=false` and production credentials in place
- SSH access to the server

### First-time setup

```bash
# On the production server
cd /opt/ecos-erp
cp backend/.env.example backend/.env
# Edit backend/.env — set APP_DEBUG=false, DB credentials, APP_KEY, MAIL_*, QUEUE_CONNECTION, etc.
nano backend/.env

# Run the first deployment (include --migrate on first run to create tables)
chmod +x deploy.sh
./deploy.sh --migrate
```

### Regular deployment (no schema changes)

```bash
cd /opt/ecos-erp
./deploy.sh
```

`deploy.sh` does everything:

1. Validates environment (`backend/.env` exists, `APP_DEBUG` is not `true`)
2. `git pull origin main`
3. `docker compose -f docker-compose.yml build --pull --build-arg GIT_SHA=<sha> --build-arg APP_VERSION=<version>`
4. `docker compose -f docker-compose.yml up -d --remove-orphans`
5. Waits for `ecos-app` healthcheck to pass (up to 150 s)
6. `php artisan optimize`
7. `docker image prune -f`
8. Verifies all 6 health checks (see below)
9. Prints `.build-info` from inside the container

### Deployment with schema changes

```bash
cd /opt/ecos-erp
./deploy.sh --migrate
```

The `--migrate` flag runs `php artisan migrate --force` after the application
becomes healthy (step 5), before optimize. **Only use this flag when you have
confirmed the migration is safe to apply.** Schema changes are an explicit
operator decision — they are never run automatically.

---

## Health Verification

`deploy.sh` runs 6 checks after every deployment. If any check fails, the script
aborts with a diagnostic message.

| Check | What it verifies |
|---|---|
| `GET /healthz` → HTTP 200 | nginx is serving requests |
| `GET /app` → HTTP 200 | React SPA entry point is reachable |
| `GET /build-info` → HTTP 200 | Build metadata endpoint is live |
| `public/app/index.html` in `ecos-app` | SPA was baked into the app image |
| `public/app/index.html` in `ecos-nginx` | SPA was baked into the nginx image |
| `php artisan optimize` exits 0 | Laravel caches rebuilt successfully |

### Manual verification

```bash
# Which version is running?
curl http://localhost/build-info
# → {"version":"v1.2.0","commit":"abc1234","built_at":"2026-06-27T10:30:00Z","environment":"production"}

# Human-readable build info from inside the container
docker exec ecos-app cat /var/www/html/.build-info
# → Version:     v1.2.0
#   Commit:      abc1234
#   Built:       2026-06-27T10:30:00Z
#   Environment: production

# Image label (without running a container)
docker inspect ecos-erp/app --format '{{index .Config.Labels "org.opencontainers.image.revision"}}'
```

---

## Rollback Procedure

### Option A — Tag before deploying (recommended for zero-downtime rollback)

Before deploying, tag the current image as `:previous`:

```bash
docker tag ecos-erp/app:latest   ecos-erp/app:previous
docker tag ecos-erp/nginx:latest ecos-erp/nginx:previous
```

To roll back:

```bash
# Restore previous image tags
docker tag ecos-erp/app:previous   ecos-erp/app:latest
docker tag ecos-erp/nginx:previous ecos-erp/nginx:latest

# Restart from the previous image — no rebuild needed
docker compose -f docker-compose.yml up -d --no-build

# Verify the rollback
curl http://localhost/build-info
```

> If the deployment included `--migrate`, restore a database backup **before**
> restarting the previous image, otherwise the old code runs against the new schema.

### Option B — Git revert + redeploy (preferred when migrations were applied)

```bash
git revert HEAD          # creates a new commit that undoes the last one
git push origin main
./deploy.sh              # redeploy; add --migrate only if the revert includes a down migration
```

This is the safest option because the image, DB schema, and code are all
consistent after the revert commit lands.

### Option C — Redeploy a previous commit

```bash
git checkout <previous-commit-sha>
./deploy.sh
git checkout main        # return to main after rollback is confirmed
```

---

## Development Workflow

On a developer's machine, `docker-compose.override.yml` is automatically merged
with `docker-compose.yml` by Docker Compose. It adds source bind-mounts so PHP
changes are reflected without rebuilding:

```bash
# Dev: Docker Compose auto-merges the override (source bind-mounts active)
docker compose up

# Production: use only the base file (override file must not exist on server)
docker compose -f docker-compose.yml up
```

**`docker-compose.override.yml` must never be present on production servers.**
`deploy.sh` enforces this by hardcoding `-f docker-compose.yml`.

---

## Troubleshooting

### Containers won't start / stay restarting

```bash
docker logs ecos-app   # PHP-FPM startup errors, migration failures
docker logs ecos-nginx # config errors, upstream connection failures
docker logs ecos-mysql # InnoDB / auth errors
```

### healthcheck stuck

```bash
# Check raw health status
docker inspect --format '{{json .State.Health}}' ecos-app

# Manually run the healthcheck command
docker exec ecos-app bash -c \
  'SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
   cgi-fcgi -bind -connect 127.0.0.1:9000'
```

### GET /build-info returns 404

The `public/build-info` file was not copied into the nginx image. This means
Stage 4 ran before Stage 3 completed, or the ARG was not passed:

```bash
# Verify the file exists in the nginx container
docker exec ecos-nginx ls -la /var/www/html/public/build-info

# Force a clean rebuild
docker compose -f docker-compose.yml build --no-cache
```

### Migration failures

```bash
# Run migrations manually with full output
docker compose -f docker-compose.yml exec app php artisan migrate --force --verbose

# Check migration status
docker compose -f docker-compose.yml exec app php artisan migrate:status
```

### Storage permission errors

```bash
# Re-run the entrypoint directory creation manually
docker exec ecos-app bash -c 'chown -R www-data:www-data /var/www/html/storage'
```

---

## Common Operational Commands

```bash
# See all running containers and their health
docker compose -f docker-compose.yml ps

# Tail application logs (PHP-FPM + queue + scheduler)
docker compose -f docker-compose.yml logs -f app

# Open a shell in the app container
docker compose -f docker-compose.yml exec app bash

# Run any artisan command
docker compose -f docker-compose.yml exec app php artisan <command>

# Clear all Laravel caches
docker compose -f docker-compose.yml exec app php artisan optimize:clear

# Queue status
docker compose -f docker-compose.yml exec app php artisan queue:monitor redis

# Force-restart a single service
docker compose -f docker-compose.yml restart nginx
```

---

## File Layout

```
/opt/ecos-erp/
├── backend/
│   ├── .env              ← production credentials (APP_DEBUG=false, not in git)
│   └── ...
├── frontend/             ← source only; not mounted in production
├── docker/
│   ├── php/
│   │   ├── Dockerfile    ← multi-stage: vendor / frontend / app / nginx
│   │   └── entrypoint.sh
│   └── nginx/
│       └── default.conf
├── docker-compose.yml    ← production (no source mounts)
├── docker-compose.override.yml  ← dev only (source mounts; NOT on prod server)
└── deploy.sh
```

---

## Known Limitations

### User-uploaded file serving

`php artisan storage:link` creates `public/storage → storage/app/public` in the
**app** container. The **nginx** container has a separate `public/` directory
(baked into its image) that does not contain this symlink.

Requests to `/storage/...` fall through nginx to PHP-FPM, which serves them
correctly — but without nginx's efficient static-file serving.

**Future fix:** add the `app-storage` named volume to the nginx service as a
read-only mount and create the `public/storage` symlink inside the nginx image.

### Mailpit in production

`mailpit` is included in `docker-compose.yml` for staging convenience. Replace
it with a real MTA (SES, Mailgun, Postfix) before going live. All Mailpit ports
are bound to `127.0.0.1` only, so they are not publicly reachable.

### TLS / HTTPS

TLS termination is not configured. HSTS is intentionally omitted from the nginx
config until TLS is confirmed working. Add TLS at the reverse-proxy or load
balancer layer before exposing this service to the public internet.
