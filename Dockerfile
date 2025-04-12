FROM php:8.4-cli

RUN ["apt", "update"]
RUN ["apt-get", "install", "-y", "libzip-dev", "zip", "libmagickwand-dev"]

RUN ["docker-php-ext-install", "exif"]
RUN ["pecl", "install", "imagick"]
RUN ["docker-php-ext-install", "imagick"]
RUN ["docker-php-ext-install", "zip"]

WORKDIR "/app"
COPY ["composer.json", "composer.lock", "gp2nc.php", "."]
COPY ["src", "src"]

RUN ["php", "-r", "copy('https://getcomposer.org/installer', 'composer-setup.php');"]
RUN ["php", "-r", "if (hash_file('sha384', 'composer-setup.php') === 'dac665fdc30fdd8ec78b38b9800061b4150413ff2e3b6f88543c636f7cd84f6db9189d43a81e5503cda447da73c7e5b6') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"]
RUN ["php", "composer-setup.php"]
RUN ["php", "-r", "unlink('composer-setup.php');"]
RUN ["php", "composer.phar", "--no-dev", "install"]

WORKDIR "/photos"
ENTRYPOINT [ "php", "/app/gp2nc.php" ]

