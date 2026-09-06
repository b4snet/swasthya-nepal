# Railway-compatible Laravel backend
# Uses PHP's built-in server (no nginx), works with Railway's $PORT
FROM php:8.3-cli-alpine

RUN apk add --no-cache \
    postgresql-client \
    icu-dev \
    libpq-dev \
    oniguruma-dev \
    zlib-dev \
    libzip-dev && \
    docker-php-ext-install -j"$(nproc)" \
    pdo_pgsql \
    pgsql \
    bcmath \
    intl \
    zip \
    opcache

WORKDIR /var/www/html
COPY --chown=nobody:nobody . .

RUN composer install --no-dev --optimize-autoloader 2>/dev/null || true

USER nobody
EXPOSE 8080
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]