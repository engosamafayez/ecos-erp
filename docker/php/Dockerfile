# syntax=docker/dockerfile:1

###############################################################################
# ECOS ERP — Application image (multi-stage)
#
#   Stage 1 (vendor)   : install Composer/PHP dependencies
#   Stage 2 (frontend) : build the React/TypeScript/Vite SPA (Node 22 — build only)
#   Stage 3 (app)      : PHP 8.4 FPM runtime + Supervisor (queue/scheduler)
#                        Node is NOT installed here — it is not needed at runtime.
#   Stage 4 (nginx)    : nginx:alpine + SPA + build-info endpoint
#
# Build context is the repository root so both backend/ and frontend/ are
# available to the build.
###############################################################################

# ---------------------------------------------------------------------------
# Stage 1 — Composer dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Cache dependency resolution before copying the whole app.
COPY backend/composer.json backend/composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
    || true

COPY backend/ ./
RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-progress


# ---------------------------------------------------------------------------
# Stage 2 — Frontend (React + TypeScript + Vite) build
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS frontend

WORKDIR /app

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
# outDir in vite.config.ts is '../backend/public/app' (relative to /app),
# which resolves to /backend/public/app inside this stage.
RUN mkdir -p /backend/public/app && npm run build


# ---------------------------------------------------------------------------
# Stage 3 — Runtime: PHP 8.4 FPM + Supervisor
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS app

# Injected by deploy.sh via --build-arg flags.
# APP_VERSION: nearest git tag (e.g. v1.2.0) or short SHA fallback.
# GIT_SHA:     short commit hash for precise traceability.
ARG APP_VERSION=0.1.0
ARG GIT_SHA=unknown

LABEL org.opencontainers.image.title="ECOS ERP" \
      org.opencontainers.image.description="ECOS ERP application runtime (PHP-FPM 8.4)" \
      org.opencontainers.image.vendor="ECOS ERP Enterprise" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${GIT_SHA}"

ENV TZ=Africa/Cairo \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# --- System packages -------------------------------------------------------
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

# --- PHP extensions --------------------------------------------------------
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
    && docker-php-ext-enable redis

# --- Composer --------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- PHP / runtime configuration ------------------------------------------
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-ecos.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-ecos.conf
COPY docker/php/supervisord.conf /etc/supervisor/conf.d/ecos.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint && chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

# --- Application code & build artifacts ------------------------------------
# Source first, then overlay the resolved vendor/ and built frontend assets.
COPY backend/ /var/www/html/
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY --from=frontend /backend/public/app /var/www/html/public/app

# Storage + cache must be writable by php-fpm (www-data).
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache

# --- Build metadata ---------------------------------------------------------
# Written once at build time. Two formats, one source of truth:
#
#   .build-info              — human-readable text for operators
#                              docker exec ecos-app cat /var/www/html/.build-info
#
#   public/build-info        — JSON served by nginx at GET /build-info
#                              curl http://localhost/build-info
#
# Both files are immutable after image creation (no Git required at runtime).
RUN BUILD_TS="$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    && printf 'Version:     %s\nCommit:      %s\nBuilt:       %s\nEnvironment: production\n' \
        "${APP_VERSION}" "${GIT_SHA}" "${BUILD_TS}" \
    > /var/www/html/.build-info \
    && printf '{"version":"%s","commit":"%s","built_at":"%s","environment":"production"}\n' \
        "${APP_VERSION}" "${GIT_SHA}" "${BUILD_TS}" \
    > /var/www/html/public/build-info

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/ecos.conf", "-n"]


# ---------------------------------------------------------------------------
# Stage 4 — Nginx (production static-file server)
#
# Bakes the React SPA directly into the nginx image so no bind-mount of
# backend/public is needed in production.  The vhost config is still
# overridable via a bind-mount without rebuilding.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS nginx

# SPA — content-hashed by Vite, immutable cache-safe filenames.
COPY --from=frontend /backend/public/app /var/www/html/public/app

# Build metadata — same file as in the app stage; served at GET /build-info.
COPY --from=app /var/www/html/public/build-info /var/www/html/public/build-info

# Bake the default vhost.  docker-compose.yml may still override this with a
# bind-mount for easy config tuning without a full rebuild.
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
