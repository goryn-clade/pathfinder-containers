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

# Fail-fast: WS_TOKEN_SECRET signs WebSocket access tokens. Same rule as pf-socket (websocket/cmd.php).
# Empty/short makes the HMAC trivially forgeable.
if ! printf '%s' "${WS_TOKEN_SECRET:-}" | grep -qE '^[0-9a-fA-F]{32,}$'; then
    echo "FATAL: WS_TOKEN_SECRET must be at least 32 hex characters and match pf-socket. Generate with: openssl rand -hex 32" >&2
    exit 1
fi

# Fail-fast: empty CCP_SSO_SECRET_KEY only surfaces as "invalid_client" at the first SSO login.
if [ -z "${CCP_SSO_SECRET_KEY:-}" ]; then
    echo "FATAL: CCP_SSO_SECRET_KEY must be set. Create an application at https://developers.eveonline.com/applications" >&2
    exit 1
fi

# Fail-fast: empty APP_PASSWORD would make htpasswd below accept an empty /setup password.
if [ -z "${APP_PASSWORD:-}" ]; then
    echo "FATAL: APP_PASSWORD must be set — it protects /setup and the Setup API. Generate with: openssl rand -hex 16" >&2
    exit 1
fi

# Fail-fast: PF_LOGIN_WHITELIST_* were renamed to PF_LOGIN_ALLOWLIST_* in v3.0.
# A v2 .env carried forward would leave the allowlist blank = login open to every EVE character.
for suffix in CHAR CORP ALLIANCE; do
    old="PF_LOGIN_WHITELIST_${suffix}"
    new="PF_LOGIN_ALLOWLIST_${suffix}"
    if [ -n "${!old:-}" ] && [ -z "${!new:-}" ]; then
        echo "FATAL: ${old} is set but ${new} is empty. ${old} was renamed to ${new} in v3.0 — rename it in .env. See MIGRATION-v2-to-v3.md." >&2
        exit 1
    fi
done

# Apply defaults for optional boolean flags before envsubst
: "${CCP_SSO_USE_PKCE:=1}"
export CCP_SSO_USE_PKCE
: "${PF_SESSION_SHARING:=0}"
export PF_SESSION_SHARING

envsubst '$DOMAIN $PATHFINDER_SOCKET_HOST'</etc/nginx/templateSite.conf >/etc/nginx/sites_enabled/site.conf
envsubst '$PATHFINDER_SOCKET_HOST' </etc/nginx/templateNginx.conf >/etc/nginx/nginx.conf
envsubst  </var/www/html/pathfinder/app/templateEnvironment.ini >/var/www/html/pathfinder/app/environment.ini
envsubst  </var/www/html/pathfinder/app/templateConfig.ini >/var/www/html/pathfinder/app/config.ini
envsubst  </var/www/html/pathfinder/app/templatePathfinder.ini >/var/www/html/pathfinder/app/pathfinder.ini
envsubst  </etc/zzz_custom.ini >/etc/php83/conf.d/zzz_custom.ini
htpasswd   -c -b -B  /etc/nginx/.setup_pass pf "$APP_PASSWORD"
exec "$@"
