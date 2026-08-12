# Swasthya backend image (DEPLOYMENT.md §15, STAGING.md §2).
# Runtime: PHP 8.3-FPM + nginx in one image (Render builds this Dockerfile).
# Non-root (www-data), pinned alpine base, postgresql-client included so the
# predeploy bootstrap (roles.sql / migrate / grants.sql) can run psql.
#
# Build context: backend/ (dockerContext in render.yaml).
# Two stages: vendor (composer, no dev) -> runtime (fpm + nginx).

# ---- Stage 1: Composer dependencies (no dev packages) ----
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

# ---- Stage 2: Runtime image ----
FROM php:8.3-fpm-alpine
LABEL org.opencontainers.image.title="swasthya-api" \
      org.opencontainers.image.description="Swasthya Laravel API (staging/production image)" \
      org.opencontainers.image.source="https://github.com/b4snet/swasthya-nepal"

# Build deps for PHP extensions, runtime packages, then extensions.
RUN set -eux; \
    apk add --no-cache \
      nginx \
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

# Application files (vendor from stage 1).
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html

# Runtime configuration. Remove the image's default www pool first so this
# single [www] pool does not collide with it.
RUN rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-swasthya.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Storage must be writable at runtime (Laravel cache/logs/sessions).
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache

# 80 = nginx (public); 9000 = php-fpm (internal, not exposed).
EXPOSE 80

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

USER www-data
ENTRYPOINT ["docker-entrypoint"]
