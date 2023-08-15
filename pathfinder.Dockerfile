FROM php:7.2.33-fpm-alpine as build
USER root
RUN apk update \
    && apk add --no-cache libpng-dev  zeromq-dev git \
    $PHPIZE_DEPS \
    && docker-php-ext-install gd && docker-php-ext-install pdo_mysql && \
    pecl install redis && docker-php-ext-enable redis && \
    pecl install channel://pecl.php.net/zmq-1.1.3 && docker-php-ext-enable zmq && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY pathfinder /app
WORKDIR /app

RUN composer self-update 2.5.8
RUN composer install

FROM alpine:3.12

# Add application
RUN mkdir -p /var/www/html

HEALTHCHECK --timeout=10s CMD curl --silent --fail http://127.0.0.1/fpm-ping

RUN apk update && apk add --no-cache php7 php7-fpm php7-mysqli php7-json php7-openssl php7-curl \
    php7-zlib php7-xml php7-phar php7-intl php7-dom php7-xmlreader php7-ctype \
    php7-mbstring php7-gd nginx supervisor curl busybox-suid sudo php7-redis php7-pdo php7-pdo_mysql php7-openssl \
    php7-fileinfo php7-event shadow gettext bash apache2-utils logrotate ca-certificates

# fix expired DST Cert
RUN sed -i '/^mozilla\/DST_Root_CA_X3.crt$/ s/^/!/' /etc/ca-certificates.conf \
    && update-ca-certificates 

# symlink nginx logs to stdout/stderr for supervisord
RUN ln -sf /dev/stdout /var/log/nginx/access.log \
 && ln -sf /dev/stderr /var/log/nginx/error.log

COPY static/logrotate/pathfinder /etc/logrotate.d/pathfinder
COPY static/nginx/nginx.conf /etc/nginx/templateNginx.conf
# we need to create sites_enabled directory in order for entrypoint.sh being able to copy file after envsubst
RUN mkdir -p /etc/nginx/sites_enabled/
COPY static/nginx/site.conf  /etc/nginx/templateSite.conf

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

RUN chmod 0766 pathfinder/logs pathfinder/tmp/  && touch /etc/nginx/.setup_pass &&  chmod +x /entrypoint.sh
COPY static/pathfinder/routes.ini /var/www/html/pathfinder/app/
COPY static/pathfinder/environment.ini /var/www/html/pathfinder/app/templateEnvironment.ini

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]