# =============================================================================
# Staging / Coolify - CodeIgniter 4 flat layout + Vue/Vite
# =============================================================================
# Etapa 1: build frontend (Vite -> assets/dashboard)
FROM node:22-alpine AS frontend
WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci
COPY frontend/ ./
# Vite outDir ../assets/dashboard (fuera de frontend/)
COPY assets ./../assets
RUN npm run build

# Etapa 2: runtime PHP 8.4 + Apache
FROM php:8.4-apache

# Apache: rewrite + headers, DocumentRoot plano, AllowOverride All para .htaccess
RUN a2enmod rewrite headers \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && sed -i 's|DocumentRoot.*|DocumentRoot /var/www/html|' /etc/apache2/sites-available/000-default.conf

# Deps sistema para extensiones CI4 y multimedia
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libmagickwand-dev libonig-dev libxml2-dev libcurl4-openssl-dev \
        unzip curl git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) intl mysqli pdo_mysql zip gd mbstring curl dom xml \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar codigo (excluye lo del .dockerignore)
COPY . .

# Traer assets construidos (sobrescribe placeholder)
COPY --from=frontend /app/assets ./assets

# Instalar deps PHP (sin dev para imagen final; CI corre con dev)
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    && mkdir -p writable/cache writable/logs writable/session writable/uploads writable/debugbar \
    && mkdir -p /data/priv/adjuntos /data/priv/importaciones \
    && chown -R www-data:www-data writable /data \
    && chmod -R 755 writable \
    && chmod +x spark 2>/dev/null || true

# Apache config especifica para Coolify (respeta .htaccess + bloquea directorios privados)
COPY docker/apache/coolify.conf /etc/apache2/conf-available/coolify.conf
RUN a2enconf coolify

# Permisos y entrypoint PHP: conserva variables runtime con puntos antes de Apache
COPY docker/entrypoint.php /usr/local/bin/entrypoint.php

EXPOSE 80

# Persistencia: Coolify debe montar volumes en estos paths
VOLUME ["/var/www/html/writable", "/data/priv"]

HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD curl -fsS http://localhost/login >/dev/null || exit 1

ENTRYPOINT ["php", "/usr/local/bin/entrypoint.php"]
CMD ["apache2-foreground"]
