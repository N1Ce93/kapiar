FROM php:8.4-fpm-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache tzdata libzip

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers libzip-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install zip \
    && apk del .build-deps

RUN docker-php-ext-install pdo pdo_mysql mysqli pcntl

WORKDIR /app

CMD ["php-fpm"]
