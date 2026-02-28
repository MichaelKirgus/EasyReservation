FROM composer:2.6 AS vendor

WORKDIR /app
COPY . .
RUN composer install --no-dev --prefer-dist --optimize-autoloader

# Use a more minimal PHP-FPM image with only necessary extensions
FROM php:8.3-fpm-alpine

# Install only required system dependencies - using busybox to enable cron functionality
RUN apk add --no-cache \
    nginx \
    curl \
    busybox \
    oniguruma-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Enable cron in inittab
RUN sed -i 's/\\#cron/cron/' /etc/inittab

ARG APP_VERSION=unknown
ENV APP_VERSION=$APP_VERSION

WORKDIR /var/www
COPY app /var/www/app
COPY artisan /var/www/artisan
COPY bootstrap /var/www/bootstrap
COPY config /var/www/config
COPY database /var/www/database
COPY public /var/www/public
COPY resources /var/www/resources
COPY routes /var/www/routes
COPY composer.json /var/www/composer.json
COPY composer.lock /var/www/composer.lock
COPY --from=vendor /app/vendor /var/www/vendor

COPY nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-custom.ini
COPY entrypoint.sh /entrypoint.sh
COPY health-check.php /var/www/health-check.php
COPY prod.env /var/www/.env

RUN php artisan storage:link

RUN mkdir -p /var/www/storage/framework/views
RUN mkdir -p /var/www/storage/framework/sessions
RUN mkdir -p /var/www/storage/framework/cache/data
RUN mkdir -p /var/www/storage/app/private
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
RUN chmod -R 775 /var/www/storage /var/www/bootstrap/cache
RUN chmod +x /entrypoint.sh

EXPOSE 8080
EXPOSE 8081

CMD ["/entrypoint.sh"]
