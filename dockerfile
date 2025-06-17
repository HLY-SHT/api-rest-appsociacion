FROM php:8.1-apache

# Instalar extensiones y utilidades
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      zip unzip git libpq-dev \
 && docker-php-ext-install pdo pdo_pgsql \
 && a2enmod rewrite

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copiar código fuente y composer.json
WORKDIR /var/www/html
COPY . /var/www/html/
# (Opcional) si incluyes composer.json en la raíz:
# COPY composer.json composer.lock /var/www/html/

# Instalar dependencias PHP
RUN composer install --no-dev --optimize-autoloader

# Asegurar permisos
RUN chown -R www-data:www-data /var/www/html
