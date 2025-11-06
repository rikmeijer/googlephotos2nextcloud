FROM php:8.4-cli

RUN ["apt", "update"]
RUN ["apt-get", "install", "-y", "libzip-dev", "zip", "libmagickwand-dev", "ffmpeg"]

RUN ["pecl", "install", "imagick"]
RUN ["docker-php-ext-enable", "imagick"]
RUN ["docker-php-ext-install", "zip"]

COPY ["./docker/policy.xml", "/etc/ImageMagick-6/policy.xml"]

WORKDIR "/app"
COPY ["composer.json", "composer.lock", "gp2nc.php", "."]
COPY ["src", "src"]

COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN ["composer", "--no-dev", "install"]

WORKDIR "/photos"
ENTRYPOINT [ "php", "/app/gp2nc.php" ]

