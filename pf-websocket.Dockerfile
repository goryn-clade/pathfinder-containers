# PHP release the WebSocket server runs. Keep it in step with pathfinder.Dockerfile.
# composer:2 alone would follow the newest PHP on every rebuild.
ARG PHP_VERSION=8.5.12

FROM composer:2 AS build
COPY websocket /app
WORKDIR /app

# Install exactly the versions in composer.lock.
RUN composer install --no-dev --optimize-autoloader

FROM php:${PHP_VERSION}-cli-alpine
COPY --from=build /app /app
WORKDIR /app

ENTRYPOINT ["/usr/local/bin/php", "cmd.php"]
