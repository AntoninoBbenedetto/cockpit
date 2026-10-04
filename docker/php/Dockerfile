FROM php:8.4-fpm-alpine

RUN apk add --no-cache git unzip icu-dev libzip-dev postgresql-dev \
    && docker-php-ext-install intl zip bcmath pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG APP_UID=1000
ARG APP_GID=1000
RUN addgroup -g ${APP_GID} app && adduser -D -u ${APP_UID} -G app app

USER app
WORKDIR /var/www/html
