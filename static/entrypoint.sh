#!/usr/bin/env bash
set -e
crontab /var/crontab.txt
envsubst '$DOMAIN' </etc/nginx/templateSite.conf >/etc/nginx/sites_enabled/site.conf
envsubst '$CONTAINER_NAME' </etc/nginx/templateNginx.conf >/etc/nginx/nginx.conf
envsubst  </var/www/html/pathfinder/app/templateEnvironment.ini >/var/www/html/pathfinder/app/environment.ini
envsubst  </var/www/html/pathfinder/app/templateConfig.ini >/var/www/html/pathfinder/app/config.ini
envsubst  </etc/zzz_custom.ini >/etc/php7/conf.d/zzz_custom.ini
htpasswd   -c -b -B  /etc/nginx/.setup_pass pf "$APP_PASSWORD"
exec "$@"
