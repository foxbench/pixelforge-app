# syntax=docker/dockerfile:1

# ---- 1. PHP dependencies (production only) ------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS base
RUN install-php-extensions pdo_sqlite pcntl
WORKDIR /app

FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---- 2. Front end build -------------------------------------------------------------------
# Vite needs vendor/ because the route helper (ziggy) is imported from there.
FROM node:22-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=vendor /app/vendor ./vendor
COPY vite.config.ts tsconfig.json tailwind.config.js components.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- 3. Runtime image ---------------------------------------------------------------------
FROM base AS runtime
COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && rm /usr/bin/composer \
    && cp .env.example .env.build && rm .env.build \
    && chmod +x docker/entrypoint.sh

# Plain HTTP on port 80: TLS is terminated by the reverse proxy in front of this container.
ENV SERVER_NAME=":80" \
    APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    QUEUE_CONNECTION=database \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    LOG_CHANNEL=stderr

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
