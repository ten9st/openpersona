FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    nginx \
    && docker-php-ext-install pdo pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY infra/backend/nginx.conf /etc/nginx/conf.d/default.conf

WORKDIR /app

EXPOSE 8000