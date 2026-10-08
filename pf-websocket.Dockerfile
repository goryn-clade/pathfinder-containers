FROM composer:2.8.8
COPY websocket /app
WORKDIR /app

RUN composer install

ENTRYPOINT ["/usr/local/bin/php", "cmd.php"]
