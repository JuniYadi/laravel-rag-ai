# ===============================================
# Stage 1: Builder
# Installs Composer dependencies and builds npm assets
# ===============================================
FROM composer:2.8 AS builder

WORKDIR /app

# Install Node.js for asset building
RUN apk add --no-cache nodejs npm

# Install PHP extensions required for Composer dependencies
RUN apk add --no-cache icu-dev \
    && docker-php-ext-install intl \
    && rm -rf /var/cache/apk/*

# Copy Composer files
COPY composer.json composer.lock ./

# Install production dependencies
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --no-ansi

# Copy Node dependency manifests first for stable npm cache layer
COPY package.json package-lock.json ./

# Install frontend dependencies deterministically
RUN npm ci

# Copy application files
COPY . .

# Build frontend assets
RUN npm run build

# Clear bootstrap cache
RUN rm -rf bootstrap/cache/*.php

# ===============================================
# Stage 2: Production (using php-base)
# ===============================================
FROM ghcr.io/juniyadi/php-base:8.5

WORKDIR /var/www/html

# Enable required extensions via environment variables
ENV PHP_EXT_bcmath=1
ENV PHP_EXT_pgsql=1
ENV PHP_EXT_pdo_pgsql=1

# Trust Cloudflare proxy - enables real IP forwarding in nginx
ENV NGINX_TRUST_CLOUDFLARE=1

# Copy built application from builder
COPY --from=builder --chown=www-data:www-data /app /var/www/html

# Set permissions for storage and cache
RUN mkdir -p bootstrap/cache \
        storage/framework/views \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/logs \
        storage/app/public \
    && chmod -R 775 storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    NGINX_DOCROOT=/var/www/html/public \
    NGINX_FRONT_CONTROLLER="/index.php?\$query_string" \
    PHP_MEMORY_LIMIT=256M \
    PHP_UPLOAD_LIMIT=64M \
    APP_BOOTSTRAP_CMD="if [ \"\$RUN_MIGRATIONS\" = \"true\" ]; then php artisan migrate --force; fi && php artisan config:cache || true && php artisan route:cache || true && php artisan view:cache || true"

# Copy app-specific Supervisor programs (php-base keeps core supervisord config)
COPY docker/supervisor/app-services.conf /etc/supervisor.d/app-services.conf
COPY docker/nginx/dynamic-routes.conf /etc/nginx/snippets/dynamic-routes.conf
COPY docker/laravel-scheduler /etc/cron.d/laravel-scheduler

EXPOSE 80

# OCI image description
LABEL org.opencontainers.image.description="${DESCRIPTION:-Laravel Marketing Mail Application}"