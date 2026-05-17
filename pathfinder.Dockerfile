FROM php:8.3-fpm-alpine AS build

RUN apk update \
    && apk add --no-cache libpng-dev git \
    $PHPIZE_DEPS \
    && docker-php-ext-install gd && docker-php-ext-install pdo_mysql && \
    pecl channel-update pecl.php.net && \
    pecl install redis && docker-php-ext-enable redis && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY pathfinder /app
WORKDIR /app

# PHP 8 / php-redis 6 vendor patches. Each is run as a separate command so an
# inline comment cannot accidentally break a `&&` chain. TODOs: drop once
# upstream fixes land in ikkez/f3-cortex dev-master and bcosca/fatfree-core.
RUN composer update --no-dev --optimize-autoloader --no-scripts
RUN echo "=== before cortex patch ===" && \
    sed -n '43,47p' vendor/ikkez/f3-cortex/lib/db/cortex.php && \
    sed -i '/relation field cache/s/\$fieldsCache,/\$fieldsCache = [],/' vendor/ikkez/f3-cortex/lib/db/cortex.php && \
    echo "=== after cortex patch ===" && \
    sed -n '43,47p' vendor/ikkez/f3-cortex/lib/db/cortex.php
RUN grep -q 'list(\$rval,\$ttl)=\$tmp;' vendor/bcosca/fatfree-core/base.php && \
        sed -i 's/list(\$rval,\$ttl)=\$tmp;/list($rval,$ttl)=$tmp; $ttl=(int)$ttl;/' vendor/bcosca/fatfree-core/base.php || true
RUN grep -q '\$ttl=\$cached\[1\];' vendor/bcosca/fatfree-core/base.php && \
        sed -i 's/\$ttl=\$cached\[1\];/$ttl=(int)$cached[1];/' vendor/bcosca/fatfree-core/base.php || true
RUN grep -qF "\$ttl?['ex'=>\$ttl]:[]" vendor/bcosca/fatfree-core/base.php && \
        sed -i "s/\\\$ttl?\['ex'=>\\\$ttl\]:\[\]/(int)\$ttl>0?['ex'=>(int)\$ttl]:[]/" vendor/bcosca/fatfree-core/base.php || true

FROM trafex/php-nginx:3.6.0

USER root

RUN apk update \
    && apk upgrade --no-cache \
    && apk add --no-cache \
        busybox-suid sudo shadow gettext bash apache2-utils logrotate ca-certificates \
        php83-redis php83-pdo php83-pdo_mysql php83-fileinfo php83-pecl-event php83-sodium \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

COPY static/logrotate/pathfinder /etc/logrotate.d/pathfinder
COPY static/nginx/nginx.conf /etc/nginx/templateNginx.conf
RUN mkdir -p /etc/nginx/sites_enabled/
COPY static/nginx/site.conf  /etc/nginx/templateSite.conf

COPY static/php/fpm-pool.conf /etc/php83/php-fpm.d/zzz_custom.conf
COPY static/php/php.ini /etc/zzz_custom.ini
COPY static/crontab.txt /var/crontab.txt
COPY static/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY static/entrypoint.sh   /

WORKDIR /var/www/html
COPY  --chown=nobody --from=build /app  pathfinder

RUN chmod 0755 pathfinder/logs pathfinder/tmp/ && rm -f index.php && touch /etc/nginx/.setup_pass && chmod +x /entrypoint.sh
COPY static/pathfinder/routes.ini /var/www/html/pathfinder/app/
COPY static/pathfinder/environment.ini /var/www/html/pathfinder/app/templateEnvironment.ini
COPY static/pathfinder/config.ini /var/www/html/pathfinder/app/templateConfig.ini
COPY static/pathfinder/pathfinder.ini /var/www/html/pathfinder/app/templatePathfinder.ini
COPY static/scripts/rotate-token-key.php /usr/local/bin/rotate-token-key.php
COPY static/scripts/migrate-tokens.php /usr/local/bin/migrate-tokens.php

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
