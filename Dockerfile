# syntax=docker/dockerfile:1
#
# One production image for every role: web (the default command), background jobs (queue:work),
# the scheduler (schedule:work) and migrations (migrate --database=owner, once per deploy, before the
# others). compose.production.yaml has the exact commands; docs/hosting.md explains them.
#
# Build: docker build --build-arg APP_VERSION="$(git describe --tags --always)" -t procurement-hyperspace .
#
# Every setting comes from environment variables when the container starts; the image holds no secrets.
#
# FrankenPHP (PHP inside the Caddy web server, MIT licence) runs in classic mode: each request starts
# from a clean PHP state, like php-fpm. Worker mode (Octane) would keep the app in memory between
# requests, and the app keeps per-request state such as the open company.

ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.5-trixie@sha256:0b21a2960d1a2e146d283f1e3ee7ef64b3c27781e372f13457f1c41d2419be75
ARG COMPOSER_IMAGE=composer:2@sha256:50ea4d33fdcde1adb0f045547295faa1548b33872e5f789dae5b02ce2fb5de93
ARG NODE_IMAGE=node:24-trixie-slim@sha256:af02d57b57719b9657a45199a79264a9437940cd55b3c7b3fc99615654f124a0

FROM ${COMPOSER_IMAGE} AS composer
FROM ${NODE_IMAGE} AS node

# --- PHP with the extensions the app needs (OPcache is built into PHP 8.5) ---
FROM ${FRANKENPHP_IMAGE} AS php
RUN install-php-extensions pdo_pgsql redis intl zip pcntl bcmath
WORKDIR /app

# --- PHP packages, without the development tools ---
FROM php AS vendor
COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer check-platform-reqs --no-dev

# --- Frontend. The Wayfinder plugin runs `php artisan wayfinder:generate`, so the build needs PHP too ---
FROM php AS assets
ARG PNPM_VERSION=12.16.1
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && npm install --global --no-fund --no-audit "pnpm@${PNPM_VERSION}"
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./
RUN pnpm install --frozen-lockfile
COPY --from=vendor /app /app
RUN pnpm run build

# --- The image that runs ---
FROM php AS app

LABEL org.opencontainers.image.title="Procurement Hyperspace" \
      org.opencontainers.image.description="Open-source supplier relationship management and project procurement register" \
      org.opencontainers.image.licenses="AGPL-3.0-only" \
      org.opencontainers.image.source="https://github.com/joni-dot/laravel-procurement-hyperspace"

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && groupadd --system --gid 10001 app \
    && useradd --system --uid 10001 --gid app --home-dir /nonexistent --no-create-home --shell /usr/sbin/nologin app \
    # Only the ports above 1024 are used, so the web server needs no extra rights.
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /config/psysh \
    && chown -R app:app /data/caddy /config/caddy /config/psysh

COPY docker/app/php.ini "$PHP_INI_DIR/conf.d/zz-hyperspace.ini"
COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/app/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --chmod=755 docker/app/healthcheck.sh /usr/local/bin/app-healthcheck

# The code belongs to root and can't be changed by the app; only storage/ and bootstrap/cache are writable.
COPY --from=vendor /app/app ./app
COPY --from=vendor /app/bootstrap ./bootstrap
COPY --from=vendor /app/config ./config
COPY --from=vendor /app/database ./database
COPY --from=vendor /app/lang ./lang
COPY --from=vendor /app/public ./public
COPY --from=vendor /app/resources/views ./resources/views
COPY --from=vendor /app/routes ./routes
COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /app/artisan /app/composer.json /app/LICENSE /app/THIRD-PARTY-NOTICES.md ./
COPY --from=assets /app/public/build ./public/build
COPY --from=vendor --chown=app:app /app/storage ./storage
RUN chown app:app bootstrap/cache

# Which version this image is (a git tag or commit), for error reports and the logs.
ARG APP_VERSION=
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=json \
    PORT=8080 \
    APP_VERSION=${APP_VERSION}

USER app
EXPOSE 8080

# Checked every second while starting, so a deploy waits no longer than needed.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --start-interval=1s --retries=3 CMD ["app-healthcheck"]

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
