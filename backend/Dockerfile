# Railway-compatible Laravel backend
# Uses PHP CLI + built-in server, no nginx
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

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    composer install --no-dev --optimize-autoloader --no-interaction

USER nobody
EXPOSE 8080
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]