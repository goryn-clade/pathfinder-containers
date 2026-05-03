#!/usr/bin/env bash
set -e
crontab /var/crontab.txt

# Build Redis DSN/session-path with optional password (empty REDIS_PASSWORD = no auth)
if [ -n "${REDIS_PASSWORD:-}" ]; then
    export REDIS_CACHE_DSN="redis=${REDIS_HOST}:${REDIS_PORT}:0:${REDIS_PASSWORD}"
    export REDIS_SESSION_PATH="tcp://${REDIS_HOST}:${REDIS_PORT}?auth=${REDIS_PASSWORD}"
else
    export REDIS_CACHE_DSN="redis=${REDIS_HOST}:${REDIS_PORT}"
    export REDIS_SESSION_PATH="tcp://${REDIS_HOST}:${REDIS_PORT}"
fi

envsubst '$DOMAIN' </etc/nginx/templateSite.conf >/etc/nginx/sites_enabled/site.conf
envsubst '$PATHFINDER_SOCKET_HOST' </etc/nginx/templateNginx.conf >/etc/nginx/nginx.conf
envsubst  </var/www/html/pathfinder/app/templateEnvironment.ini >/var/www/html/pathfinder/app/environment.ini
envsubst  </var/www/html/pathfinder/app/templateConfig.ini >/var/www/html/pathfinder/app/config.ini
envsubst  </var/www/html/pathfinder/app/templatePathfinder.ini >/var/www/html/pathfinder/app/pathfinder.ini
envsubst  </etc/zzz_custom.ini >/etc/php83/conf.d/zzz_custom.ini
htpasswd   -c -b -B  /etc/nginx/.setup_pass pf "$APP_PASSWORD"
exec "$@"
