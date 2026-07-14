FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-dev git \
    && docker-php-ext-install intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /app
