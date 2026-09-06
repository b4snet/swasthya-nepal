# Railway-compatible Laravel image
# Uses PHP's built-in server (no nginx) — works on Railway's non-root containers
# Build context: backend/

# ---- Stage 1: Composer dependencies ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev \
      --no-interaction \
      --prefer-dist \
      --no-progress \
      --no-scripts \
      --no-autoloader
COPY . .
RUN composer install \
      --no-dev \
      --no-interaction \
      --prefer-dist \
      --no-progress \
      --optimize-autoloader

# ---- Stage 2: Runtime ----
FROM php:8.3-cli-alpine

# Install PHP extensions
RUN set -eux; \
    apk add --no-cache \
      postgresql-client \
      icu-dev \
      libpq-dev \
      oniguruma-dev \
      zlib-dev \
      libzip-dev \
      $PHPIZE_DEPS; \
    docker-php-ext-install -j"$(nproc)" \
      pdo_pgsql \
      pgsql \
      bcmath \
      intl \
      zip \
      opcache; \
    apk del $PHPIZE_DEPS; \
    rm -rf /var/cache/apk/*

# Application files
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html

# Runtime config
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Storage writable
RUN chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache

# Railway uses PORT env var (default 8080)
EXPOSE 8080

# Entrypoint: run bootstrap if flagged, else start PHP server
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]