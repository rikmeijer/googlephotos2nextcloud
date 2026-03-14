FROM composer:2.9 AS composer
FROM php:8.4-cli


RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev zip libmagickwand-dev ffmpeg

RUN pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-install zip

COPY ./docker/policy.xml /etc/ImageMagick-6/policy.xml

WORKDIR /app
COPY composer.json composer.lock ./

COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer --no-dev install --prefer-dist --no-interaction --no-progress

COPY src src
COPY gp2nc.php ./

WORKDIR "/photos"
ENTRYPOINT [ "php", "/app/gp2nc.php" ]
