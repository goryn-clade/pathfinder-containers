# PHP release the image runs. Pin the patch version so a rebuild cannot change it
# silently. 8.5.12 includes the CVE-2026-109618 and CVE-2026-109619 fixes.
ARG PHP_VERSION=8.5.12

# Official PHP image plus the extensions Pathfinder needs. The build and runtime
# stages both start from it, so composer runs on the same PHP that serves requests.
# igbinary: F3 serializes its cache with it when it is loaded. The Alpine images had
# it (pulled in by php83-pecl-redis), so existing Redis caches need it to be read.
FROM php:${PHP_VERSION}-fpm-alpine AS base

# Pin both PECL extensions so a rebuild cannot pick up a different version.
# igbinary 3.2.17RC1: 3.2.16, the newest release without an RC suffix, does not
# build on PHP 8.5.
ARG PECL_REDIS_VERSION=6.3.0
ARG PECL_IGBINARY_VERSION=3.2.17RC1

RUN apk add --no-cache libpng icu-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpng-dev icu-dev \
    && docker-php-ext-install gd intl pdo_mysql \
    && (php -m | grep -q '^Zend OPcache$' || docker-php-ext-install opcache) \
    && pecl channel-update pecl.php.net \
    && pecl install redis-${PECL_REDIS_VERSION} && docker-php-ext-enable redis \
    && pecl install igbinary-${PECL_IGBINARY_VERSION} && docker-php-ext-enable igbinary \
    && apk del .build-deps \
    && rm -rf /tmp/pear

FROM base AS build

RUN apk add --no-cache git \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY pathfinder /app
WORKDIR /app

# Install exactly the versions in composer.lock, so every build ships what was
# tested and the vendor patches below match the files they were written for.
# To update a dependency, update composer.lock in the pathfinder repo.
RUN composer install --no-dev --optimize-autoloader --no-scripts

# PHP 8 / php-redis 6 vendor patches. Each is run as a separate command so an
# inline comment cannot accidentally break a `&&` chain. Each one greps for the
# line it changes first, so the build fails if a dependency update moves it.
# TODOs: drop once upstream fixes land in ikkez/f3-cortex dev-master and
# bcosca/fatfree-core.
RUN grep -q '\$fieldsCache,.*relation field cache' vendor/ikkez/f3-cortex/lib/db/cortex.php && \
    sed -i '/relation field cache/s/\$fieldsCache,/\$fieldsCache = [],/' vendor/ikkez/f3-cortex/lib/db/cortex.php
# PHP 8.5: ReflectionProperty::setAccessible() is deprecated (a no-op since 8.1).
RUN grep -q '\$m_ad_prop->setAccessible(' vendor/ikkez/f3-cortex/lib/db/cortex.php && \
    sed -i '/\$m_ad_prop->setAccessible(/d' vendor/ikkez/f3-cortex/lib/db/cortex.php
RUN grep -q 'list(\$rval,\$ttl)=\$tmp;' vendor/bcosca/fatfree-core/base.php && \
    sed -i 's/list(\$rval,\$ttl)=\$tmp;/list($rval,$ttl)=$tmp; $ttl=(int)$ttl;/' vendor/bcosca/fatfree-core/base.php
RUN grep -q '\$ttl=\$cached\[1\];' vendor/bcosca/fatfree-core/base.php && \
    sed -i 's/\$ttl=\$cached\[1\];/$ttl=(int)$cached[1];/' vendor/bcosca/fatfree-core/base.php
RUN grep -qF "\$ttl?['ex'=>\$ttl]:[]" vendor/bcosca/fatfree-core/base.php && \
    sed -i "s/\\\$ttl?\['ex'=>\\\$ttl\]:\[\]/(int)\$ttl>0?['ex'=>(int)\$ttl]:[]/" vendor/bcosca/fatfree-core/base.php

FROM base AS final

RUN apk update \
    && apk upgrade --no-cache \
    && apk add --no-cache \
        nginx supervisor \
        busybox-suid sudo shadow gettext bash apache2-utils logrotate ca-certificates \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && chown -R nobody:nobody /var/lib/nginx /run/nginx

# Production php.ini defaults (display_errors off etc.), as the Alpine packages had.
# The official image's docker.conf logs every FPM request to stderr; drop that so
# docker logs keeps showing only errors.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && grep -q '^access.log' /usr/local/etc/php-fpm.d/docker.conf \
    && sed -i '/^access.log/d' /usr/local/etc/php-fpm.d/docker.conf

COPY static/logrotate/pathfinder /etc/logrotate.d/pathfinder
COPY static/nginx/nginx.conf /etc/nginx/templateNginx.conf
RUN mkdir -p /etc/nginx/sites_enabled/
COPY static/nginx/site.conf  /etc/nginx/templateSite.conf

COPY static/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zzz_custom.conf
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
