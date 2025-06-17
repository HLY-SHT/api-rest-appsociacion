FROM php:8.1-apache

# Instalar extensiones y utilidades y habilitar módulos
RUN apt-get update \
 && apt-get install -y --no-install-recommends zip unzip git libpq-dev \
 && docker-php-ext-install pdo pdo_pgsql \
 && a2enmod rewrite headers \
 && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Copiar la configuración de CORS
COPY cors.conf /etc/apache2/conf-available/cors.conf
RUN a2enconf cors

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copiar código fuente y configuración de rutas
WORKDIR /var/www/html
COPY . /var/www/html/

# Instalar dependencias PHP
RUN composer install --no-dev --optimize-autoloader

# Asegurar permisos
RUN chown -R www-data:www-data /var/www/html
