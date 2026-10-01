# Safara: Laravel on Apache. Listens on $PORT (Railway sets it).
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader

FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libpq-dev libzip-dev libpng-dev libfreetype-dev libjpeg-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql pdo_pgsql pdo_sqlite zip gd bcmath opcache pcntl \
    && rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* \
    && a2enmod mpm_prefork rewrite headers \
    && rm -rf /var/lib/apt/lists/*
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri "s#/var/www/html#${APACHE_DOCUMENT_ROOT}#g" /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN php artisan package:discover --ansi \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
EXPOSE 8080
CMD ["entrypoint.sh"]
