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

RUN ["php", "-r", "copy('https://getcomposer.org/installer', 'composer-setup.php');"]
RUN ["php", "-r", "if (hash_file('sha384', 'composer-setup.php') === 'ed0feb545ba87161262f2d45a633e34f591ebb3381f2e0063c345ebea4d228dd0043083717770234ec00c5a9f9593792') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"]
RUN ["php", "composer-setup.php"]
RUN ["php", "-r", "unlink('composer-setup.php');"]
RUN ["php", "composer.phar", "--no-dev", "install"]

WORKDIR "/photos"
ENTRYPOINT [ "php", "/app/gp2nc.php" ]

