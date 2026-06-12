# syntax=docker/dockerfile:1
#
# stocs-auth — production image (PHP + Apache, headless API).
# Built for linux/amd64 (DigitalOcean App Platform). Serves public/ on :8080.

FROM composer:2 AS composer

FROM php:8.4-apache-bookworm AS app

# PHP extensions via the well-maintained installer (handles apt dev libs for us).
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
      pdo_mysql mbstring bcmath intl zip opcache pcntl exif

# Apache: serve Laravel's public/ dir, rewrite on, listen on 8080 (App Platform http_port).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN set -eux; \
    sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf; \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf; \
    sed -ri 's!Listen 80$!Listen 8080!' /etc/apache2/ports.conf; \
    sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:8080>!' /etc/apache2/sites-available/000-default.conf; \
    a2enmod rewrite

# Production PHP + opcache tuning.
RUN { \
      echo "expose_php=Off"; \
      echo "upload_max_filesize=32M"; \
      echo "post_max_size=32M"; \
      echo "memory_limit=512M"; \
      echo "opcache.enable=1"; \
      echo "opcache.validate_timestamps=0"; \
      echo "opcache.max_accelerated_files=20000"; \
      echo "opcache.memory_consumption=192"; \
      echo "opcache.interned_strings_buffer=16"; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# Composer deps first for layer caching.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN set -eux; \
    composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction; \
    mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache

COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8080
CMD ["start.sh"]
