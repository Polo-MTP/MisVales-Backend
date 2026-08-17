# syntax=docker/dockerfile:1

# ---- base: dependencias del SO y extensiones de PHP, comunes a ambos ambientes ----
FROM php:8.3-fpm AS base

ARG user=laravel
ARG uid=1000

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN useradd -G www-data,root -u $uid -d /home/$user $user \
    && mkdir -p /home/$user/.composer \
    && chown -R $user:$user /home/$user

WORKDIR /var/www

# ---- development: código montado por volumen (docker-compose.yml), dependencias con --dev ----
FROM base AS development

COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

USER $user

CMD ["php-fpm"]

# ---- production: código copiado a la imagen (inmutable), sin dependencias de dev ----
FROM base AS production

COPY docker/php/production.ini /usr/local/etc/php/conf.d/production.ini

COPY --chown=$user:www-data . /var/www

USER $user

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && composer clear-cache

CMD ["php-fpm"]
