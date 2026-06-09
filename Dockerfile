FROM composer:2 AS vendor

WORKDIR /app

COPY laravel/ .
RUN composer install \
      --no-dev \
      --no-interaction \
      --prefer-dist \
      --optimize-autoloader

FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
      nginx \
      supervisor \
      libpng-dev \
      oniguruma-dev \
      libxml2-dev \
      curl-dev \
    && docker-php-ext-install \
      pdo_mysql \
      mbstring \
      xml \
      curl \
      pcntl \
    && rm -rf /var/cache/apk/*

WORKDIR /var/www

COPY laravel/ .
COPY --from=vendor /app/vendor ./vendor

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/http.d/default.conf

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]