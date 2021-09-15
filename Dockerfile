FROM php:7.2.11-fpm-alpine3.7 as build

RUN apk update \
    && apk add --no-cache libpng-dev  zeromq-dev git \
    $PHPIZE_DEPS \ 
    && docker-php-ext-install gd && docker-php-ext-install pdo_mysql && pecl install redis && docker-php-ext-enable redis && pecl install channel://pecl.php.net/zmq-1.1.3 && docker-php-ext-enable zmq \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY pathfinder /app
WORKDIR /app

RUN composer self-update 1.6.3
RUN composer install

FROM trafex/alpine-nginx-php7:ba1dd422
RUN apk update && apk add --no-cache busybox-suid sudo php7-redis php7-pdo php7-pdo_mysql php7-fileinfo shadow gettext bash apache2-utils

COPY static/nginx/nginx.conf /etc/nginx/templateNginx.conf
COPY static/nginx/site.conf  /etc/nginx/sites_enabled/templateSite.conf

# Configure PHP-FPM
COPY static/php/fpm-pool.conf /etc/php7/php-fpm.d/zzz_custom.conf

COPY static/php/php.ini /etc/zzz_custom.ini
# configure cron
COPY static/crontab.txt /var/crontab.txt
# Configure supervisord
COPY static/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY static/entrypoint.sh   /

WORKDIR /var/www/html
COPY  --chown=nobody --from=build /app  pathfinder

RUN chmod 0766 pathfinder/logs pathfinder/tmp/ && rm index.php && touch /etc/nginx/.setup_pass &&  chmod +x /entrypoint.sh 
COPY static/pathfinder/routes.ini /var/www/html/pathfinder/app/
COPY static/pathfinder/environment.ini /var/www/html/pathfinder/app/templateEnvironment.ini

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
