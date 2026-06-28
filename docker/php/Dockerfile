# syntax=docker/dockerfile:1
# =============================================================================
# ECOS ERP — Multi-stage production image
#
# Stage 1  composer   Resolve PHP dependencies (no scripts, no artisan)
# Stage 2  frontend   Build React/Vite SPA      (Node 22, build-only)
# Stage 3  app        PHP 8.4-FPM runtime        (no Node, no Composer, no dev)
# Stage 4  nginx      Static + proxy server      (no PHP, no Composer)
#
# IMMUTABILITY GUARANTEE (TASK-INFRA-002):
#   bootstrap/cache is fully generated here during docker build.
#   The runtime container never runs:
#     composer install / dump-autoload
#     artisan package:discover
#     artisan optimize / config:cache / route:cache / event:cache / view:cache
#   config:cache is intentionally excluded — it bakes env values into the image.
#   view:cache is intentionally excluded — storage/ is a runtime named volume.
# =============================================================================


# -----------------------------------------------------------------------------
# Stage 1 — Composer: resolve and optimise PHP autoloader
#
# Rules enforced here:
#   --no-dev         never install dev dependencies in the image
#   --no-scripts     never execute post-autoload-dump (artisan / package:discover)
#                    Those commands need a running app context; this stage is a
#                    pure dependency resolver.
#   --no-autoloader  first pass: download only, do not generate class maps yet
#
# The class map is generated in a second step AFTER the application source is
# available so that --optimize can scan all PSR-4 namespaces correctly.
# -----------------------------------------------------------------------------
FROM composer:2 AS composer

WORKDIR /app

# Layer 1: dependency resolution (invalidated only when lock file changes)
COPY backend/composer.json backend/composer.lock ./
RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-autoloader \
        --no-scripts \
        --no-interaction

# Layer 2: generate optimised class map (invalidated when source changes)
COPY backend/ ./
RUN composer dump-autoload \
        --no-dev \
        --optimize \
        --no-scripts


# -----------------------------------------------------------------------------
# Stage 2 — Frontend: build the React / TypeScript / Vite SPA
#
# Node 22 is present ONLY in this stage.
# It is intentionally absent from Stage 3 (runtime).
#
# Output:  /backend/public/app   (Vite outDir is '../backend/public/app'
#          relative to the frontend/ working directory = /app)
# -----------------------------------------------------------------------------
FROM node:22-bookworm-slim AS frontend

WORKDIR /app

# Layer 1: install dependencies (cached until package-lock.json changes)
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --prefer-offline

# Layer 2: build (invalidated when any source file changes)
COPY frontend/ ./
RUN mkdir -p /backend/public/app && npm run build


# -----------------------------------------------------------------------------
# Stage 3 — App: PHP 8.4-FPM runtime (IMMUTABLE)
#
# This stage:
#   • Installs PHP 8.4-FPM + Supervisor (no Node, no Composer binary)
#   • Copies vendor from Stage 1 (no dev packages)
#   • Copies application source + compiled SPA
#   • Runs package:discover, route:cache, event:cache during BUILD
#     → bootstrap/cache is baked into the image
#   • Deletes the build-time .env so no secrets survive
#   • Verifies packages.php contains no dev packages
#
# At runtime the entrypoint only performs:
#   wait mysql → storage dirs → permissions → storage:link → APP_KEY
#   → [optional migrate] → [optional seed] → exec supervisord
# -----------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS app

# ---------------------------------------------------------------------------
# Build arguments — injected by deploy.sh
# ---------------------------------------------------------------------------
ARG APP_VERSION=0.1.0
ARG GIT_SHA=unknown
ARG BUILD_TIME

LABEL org.opencontainers.image.title="ECOS ERP" \
      org.opencontainers.image.description="ECOS ERP — PHP 8.4-FPM application runtime" \
      org.opencontainers.image.vendor="ECOS ERP Enterprise" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${GIT_SHA}"

ENV TZ=Africa/Cairo

# ---------------------------------------------------------------------------
# System packages
# Note: gnupg removed (not needed — NodeSource is gone)
#       composer removed from runtime (all composer work is done in build stages)
# ---------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        ca-certificates \
        unzip \
        zip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libicu-dev \
        libxml2-dev \
        supervisor \
        default-mysql-client \
        libfcgi-bin \
    && rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------------------
# PHP extensions
# ---------------------------------------------------------------------------
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        bcmath \
        gd \
        zip \
        intl \
        pcntl \
        exif \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

# ---------------------------------------------------------------------------
# Runtime configuration
# ---------------------------------------------------------------------------
COPY docker/php/php.ini          /usr/local/etc/php/conf.d/zz-ecos.ini
COPY docker/php/www.conf         /usr/local/etc/php-fpm.d/zz-ecos.conf
COPY docker/php/supervisord.conf /etc/supervisor/conf.d/ecos.conf
COPY docker/php/entrypoint.sh    /usr/local/bin/entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint \
    && chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Application source
# .dockerignore guarantees these are excluded from the context:
#   backend/vendor/                  (comes from Stage 1)
#   backend/bootstrap/cache/*.php    (regenerated during THIS build — see below)
#   backend/public/app/              (comes from Stage 2)
#   backend/.env                     (injected at runtime via env_file)
# ---------------------------------------------------------------------------
COPY backend/ /var/www/html/

# Vendor from Stage 1 — optimised autoloader, no dev packages
COPY --from=composer /app/vendor /var/www/html/vendor

# Compiled SPA from Stage 2
COPY --from=frontend /backend/public/app /var/www/html/public/app

# ---------------------------------------------------------------------------
# Build-time Laravel cache generation (TASK-INFRA-002 Rules 1-3, 8-9)
#
# All cache files are generated NOW and baked into this image layer.
# The runtime container never regenerates them.
#
# Commands run:
#   package:discover  → bootstrap/cache/packages.php   (service providers + facades)
#   route:cache       → bootstrap/cache/routes-v7.php  (serialised route collection)
#   event:cache       → bootstrap/cache/events.scoped.php
#
# config:cache is deliberately excluded:
#   It would bake the placeholder .env values into the image. At runtime, when
#   the real .env is loaded, Laravel would still read the stale baked-in config.
#   Config must be resolved from the live env_file at boot time.
#
# view:cache is deliberately excluded:
#   Compiled views go into storage/framework/views/, which is a named volume at
#   runtime. Baking them here would create files that the volume mount shadows.
#   Blade templates compile lazily on first request and persist in the volume.
#
# The build-time .env is created for the sole purpose of booting the artisan
# CLI (APP_KEY is required) and is deleted before the layer is committed.
# No real credentials appear in this file — all external drivers are set to
# non-connecting modes (CACHE_STORE=array, SESSION_DRIVER=array, etc.).
# ---------------------------------------------------------------------------
RUN set -e \
    && rm -f bootstrap/cache/*.php \
    && APP_KEY="base64:$(openssl rand -base64 32)" \
    && { \
        echo "APP_NAME=ECOS-ERP"; \
        echo "APP_ENV=production"; \
        echo "APP_KEY=${APP_KEY}"; \
        echo "APP_DEBUG=false"; \
        echo "APP_URL=http://localhost"; \
        echo "DB_CONNECTION=mysql"; \
        echo "DB_HOST=127.0.0.1"; \
        echo "DB_PORT=3306"; \
        echo "DB_DATABASE=ecos_erp"; \
        echo "DB_USERNAME=ecos"; \
        echo "DB_PASSWORD=build-placeholder"; \
        echo "CACHE_STORE=array"; \
        echo "SESSION_DRIVER=array"; \
        echo "QUEUE_CONNECTION=sync"; \
        echo "REDIS_HOST=127.0.0.1"; \
        echo "MAIL_MAILER=log"; \
    } > .env \
    # Rule 8: if package:discover fails, the build fails immediately.
    && php artisan package:discover --ansi \
    && php artisan route:cache \
    && php artisan event:cache \
    # Remove the build-time placeholder .env — real env comes from env_file at runtime.
    && rm .env \
    # Rule 9: verify no dev-only packages baked into packages.php.
    && if grep -qi 'pail\|sail\|collision\|phpunit' bootstrap/cache/packages.php 2>/dev/null; then \
        printf '\n\033[0;31mERROR: dev package found in bootstrap/cache/packages.php:\033[0m\n' >&2; \
        grep -i 'pail\|sail\|collision\|phpunit' bootstrap/cache/packages.php >&2; \
        exit 1; \
    fi \
    && printf '\n\033[0;32m[build] Bootstrap cache generated:\033[0m\n' \
    && ls -la bootstrap/cache/

# ---------------------------------------------------------------------------
# Permissions
# Only storage/ requires runtime write access.
# bootstrap/cache/ is baked in — owned by www-data for defensive writeability
# but no volume is mounted over it (see docker-compose.yml).
# ---------------------------------------------------------------------------
RUN chown -R www-data:www-data \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache \
    && chmod -R ug+rwX \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache

# ---------------------------------------------------------------------------
# Build metadata — baked once at build time, two representations:
#
#   .build-info          human-readable text (operator: docker exec … cat)
#   public/build-info    JSON served by nginx at GET /build-info
#                        Also consumed by HealthController for /api/health
# ---------------------------------------------------------------------------
RUN BUILD_TS="${BUILD_TIME:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}" \
    && printf 'Version:     %s\nCommit:      %s\nBuilt:       %s\nEnvironment: production\n' \
        "${APP_VERSION}" "${GIT_SHA}" "${BUILD_TS}" \
    > /var/www/html/.build-info \
    && printf '{"version":"%s","commit":"%s","built_at":"%s","environment":"production"}\n' \
        "${APP_VERSION}" "${GIT_SHA}" "${BUILD_TS}" \
    > /var/www/html/public/build-info

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/ecos.conf", "-n"]


# -----------------------------------------------------------------------------
# Stage 4 — Nginx: static file server + PHP-FPM proxy
#
# Contains ONLY:
#   • nginx:1.27-alpine base
#   • Compiled SPA (public/app/) from Stage 2
#   • Build-info JSON from Stage 3
#   • Vhost configuration
#
# No PHP. No Composer. No Node. No application source.
# -----------------------------------------------------------------------------
FROM nginx:1.27-alpine AS nginx

# SPA — Vite content-hashed assets, safe to cache forever
COPY --from=frontend /backend/public/app      /var/www/html/public/app

# Build metadata — served as static JSON at GET /build-info
COPY --from=app /var/www/html/public/build-info /var/www/html/public/build-info

# Vhost — bind-mountable in development without rebuilding the image
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
