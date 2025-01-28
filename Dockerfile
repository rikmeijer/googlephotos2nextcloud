FROM php:8.4-cli
COPY . /app
WORKDIR /photos
ENTRYPOINT [ "php", "/app/gp2nc.php" ]

