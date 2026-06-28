# syntax=docker/dockerfile:1
# =============================================================================
# ECOS ERP — Multi-stage production image
#
# Stage 1  composer   Resolve PHP dependencies (no scripts, no artisan)
# Stage 2  frontend   Build React/Vite SPA      (Node 22, build-only)
# Stage 3  app        PHP 8.4-FPM runtime        (no Node, no dev packages)
# Stage 4  nginx      Static + proxy server      (no PHP, no Composer)
#
# Build context: repository root
# Secrets: never baked in — injected at runtime via env_file
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
# Stage 3 — App: PHP 8.4-FPM runtime
#
# What this stage contains:
#   • PHP 8.4-FPM + extensions required by Laravel
#   • Supervisor (manages PHP-FPM, queue worker, scheduler)
#   • Composer binary (for runtime dump-autoload only — no install)
#   • Application source (backend/)
#   • Vendor from Stage 1
#   • Compiled SPA from Stage 2
#   • Build metadata files
#
# What this stage must NOT contain:
#   • Node.js or npm
#   • Dev PHP packages (laravel/pail, phpunit, etc.)
#   • Bootstrap cache (packages.php, services.php, config.php …)
#   • .env secrets
#   • Any generated or compiled artefact that changes at runtime
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

ENV TZ=Africa/Cairo \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# ---------------------------------------------------------------------------
# System packages
# Note: gnupg removed (was only needed for NodeSource GPG — Node is gone)
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
# Composer binary (phar only — no dev tooling)
# ---------------------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---------------------------------------------------------------------------
# Runtime configuration
# ---------------------------------------------------------------------------
COPY docker/php/php.ini         /usr/local/etc/php/conf.d/zz-ecos.ini
COPY docker/php/www.conf        /usr/local/etc/php-fpm.d/zz-ecos.conf
COPY docker/php/supervisord.conf /etc/supervisor/conf.d/ecos.conf
COPY docker/php/entrypoint.sh   /usr/local/bin/entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint \
    && chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Application source
# .dockerignore guarantees these are excluded from the context:
#   backend/vendor/                  (comes from Stage 1)
#   backend/bootstrap/cache/*.php    (generated at runtime into app-cache volume)
#   backend/public/app/              (comes from Stage 2)
#   backend/.env                     (injected at runtime via env_file)
# ---------------------------------------------------------------------------
COPY backend/ /var/www/html/

# Vendor from Stage 1 — optimised autoloader, no dev packages
COPY --from=composer /app/vendor /var/www/html/vendor

# Compiled SPA from Stage 2
COPY --from=frontend /backend/public/app /var/www/html/public/app

# ---------------------------------------------------------------------------
# Permissions
# storage/ and bootstrap/cache/ are the only directories php-fpm writes to.
# Both are mounted as named volumes at runtime; set base permissions on the
# image layer so the volumes inherit correct ownership on first run.
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
