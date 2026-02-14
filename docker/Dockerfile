FROM php:8.2-fpm

# Installation des dépendances système
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    curl \
    jq \
    && rm -rf /var/lib/apt/lists/*

# Installation des extensions PHP
RUN docker-php-ext-install \
    pdo_mysql \
    intl \
    opcache \
    zip \
    bcmath \
    soap

# Installation de Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configuration PHP
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# Configuration PHP-FPM
RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

# Création des répertoires de travail
RUN mkdir -p /var/www/html/var/log \
    && mkdir -p /var/www/html/var/cache \
    && mkdir -p /var/www/html/var/imports/sales \
    && chown -R www-data:www-data /var/www/html/var

WORKDIR /var/www/html

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
