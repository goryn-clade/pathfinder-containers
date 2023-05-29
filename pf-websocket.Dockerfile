FROM composer:2.5.7
COPY websocket /app
WORKDIR /app

RUN composer install

ENTRYPOINT ["/usr/local/bin/php", "cmd.php"]
