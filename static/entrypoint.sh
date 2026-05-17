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

# Warn loudly if debug mode is enabled on a production deployment
if [ "${APP_ENV:-}" = "production" ] && [ "${PF_DEBUG:-0}" -gt 0 ]; then
    echo "WARNING: PF_DEBUG=${PF_DEBUG} is set with APP_ENV=production — stack traces will be exposed to users. Set PF_DEBUG=0." >&2
fi

# Fail-fast: TOKEN_ENCRYPTION_KEY must be 64 hex chars (32 bytes) for libsodium crypto_secretbox.
# Absent/invalid means every ESI token op throws and SSO breaks silently for users.
if [ -z "${TOKEN_ENCRYPTION_KEY:-}" ]; then
    echo "FATAL: TOKEN_ENCRYPTION_KEY must be set. Generate with: openssl rand -hex 32" >&2
    exit 1
fi
if ! printf '%s' "$TOKEN_ENCRYPTION_KEY" | grep -qE '^[0-9a-fA-F]{64}$'; then
    echo "FATAL: TOKEN_ENCRYPTION_KEY must be exactly 64 hex characters (32 bytes). Generate with: openssl rand -hex 32" >&2
    exit 1
fi

# Apply defaults for optional boolean flags before envsubst
: "${CCP_SSO_USE_PKCE:=1}"
export CCP_SSO_USE_PKCE

envsubst '$DOMAIN'</etc/nginx/templateSite.conf >/etc/nginx/sites_enabled/site.conf
envsubst '$PATHFINDER_SOCKET_HOST' </etc/nginx/templateNginx.conf >/etc/nginx/nginx.conf
envsubst  </var/www/html/pathfinder/app/templateEnvironment.ini >/var/www/html/pathfinder/app/environment.ini
envsubst  </var/www/html/pathfinder/app/templateConfig.ini >/var/www/html/pathfinder/app/config.ini
envsubst  </var/www/html/pathfinder/app/templatePathfinder.ini >/var/www/html/pathfinder/app/pathfinder.ini
envsubst  </etc/zzz_custom.ini >/etc/php83/conf.d/zzz_custom.ini
htpasswd   -c -b -B  /etc/nginx/.setup_pass pf "$APP_PASSWORD"
exec "$@"
