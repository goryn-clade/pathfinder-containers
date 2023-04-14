FROM composer:2.3.10
COPY websocket /app
WORKDIR /app

RUN composer install

ENTRYPOINT ["/usr/local/bin/php", "cmd.php"]
