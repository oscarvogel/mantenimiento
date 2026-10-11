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

# Fotos de tablero: permitir uploads de cámara por encima del default de PHP.
# La aplicación limita evidencia a 8 MB y el frontend la optimiza antes de subir.
RUN printf "upload_max_filesize=12M\npost_max_size=14M\nmax_file_uploads=10\n" \
    > /usr/local/etc/php/conf.d/mantenimiento-uploads.ini

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
RUN set -eu; \
    for intento in 1 2 3; do \
        if composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress; then \
            break; \
        fi; \
        if [ "$intento" -eq 3 ]; then \
            echo "No se pudieron instalar las dependencias PHP tras 3 intentos." >&2; \
            exit 1; \
        fi; \
        rm -rf vendor; \
        sleep $((intento * 5)); \
    done; \
    test -f vendor/autoload.php; \
    test -f vendor/codeigniter4/framework/system/Boot.php; \
    mkdir -p writable/cache writable/logs writable/session writable/uploads writable/debugbar \
    && mkdir -p /data/priv/adjuntos /data/priv/importaciones \
    && chown -R www-data:www-data writable /data \
    && chmod -R 755 writable \
    && chmod +x spark

# Apache config especifica para Coolify (respeta .htaccess + bloquea directorios privados)
COPY docker/apache/coolify.conf /etc/apache2/conf-available/coolify.conf
RUN a2enconf coolify

# Permisos y entrypoint PHP: conserva variables runtime con puntos antes de Apache
COPY docker/entrypoint.php /usr/local/bin/entrypoint.php

EXPOSE 80

# Persistencia: Coolify debe montar volumes en estos paths
VOLUME ["/var/www/html/writable", "/data/priv"]

HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD test -f /var/www/html/vendor/codeigniter4/framework/system/Boot.php && curl -fsS http://localhost/login >/dev/null || exit 1

ENTRYPOINT ["php", "/usr/local/bin/entrypoint.php"]
CMD ["apache2-foreground"]
