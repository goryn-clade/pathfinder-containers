# TODO


## Readme updates 
- Review Claude changes to Readmes

## Simplify config/ vs static/ layout

**Intended design:**
- `static/` — files baked into the image by the Dockerfile (`COPY`). Either templates that `entrypoint.sh` processes via `envsubst` (instance values come from `.env`), or truly static files identical across all deployments.
- `config/` — files bind-mounted at runtime by compose, mounted **consistently across all three compose files**. Contains instance-specific values that can't be expressed as env vars.

**Current problems:**

- `config/pathfinder/config.ini` is byte-for-byte identical to `static/pathfinder/config.ini` — redundant.
- `config/pathfinder/pathfinder.ini` (hardcoded values) is mounted in `compose.dev.yml` only, overriding the env-var template. Dev therefore behaves differently from test and prod.
- `static/redis/redis.conf` is never COPYed into the image — it is only ever bind-mounted by compose. It belongs in `config/`, not `static/`. It is also inconsistently applied: mounted in `compose.dev.yml` and `compose.test.yml` but not `compose.yml` (prod uses an inline `--appendonly yes` flag instead).

**Changes:**

1. **Delete `config/pathfinder/config.ini`** and remove its mount from `compose.dev.yml`. The static template is identical and already handles this.

2. **Delete `config/pathfinder/pathfinder.ini`** and remove its mount from `compose.dev.yml`. Confirm `.env` / `.env.example` cover all vars used in `static/pathfinder/pathfinder.ini` (`$PF_INSTALL_NAME`, `$PF_REGISTRATION_STATUS`, `$PF_LOGIN_WHITELIST_CHAR`, `$PF_LOGIN_WHITELIST_CORP`, `$PF_LOGIN_WHITELIST_ALLIANCE`, `$PF_SUPER_ADMIN_ID`, `$PF_DEBUG`). All three envs then use the same env-var template.

3. **Move `static/redis/redis.conf` → `config/redis/redis.conf`**. Add a consistent mount to all three compose files (`./config/redis/redis.conf:/etc/redis/redis.conf:ro`) and drop the inline `--appendonly yes` from `compose.yml` (the conf file already enables appendonly).

**End state — files mounted by all three compose files:**
```
config/
  pathfinder/plugin.ini    → /var/www/html/pathfinder/app/plugin.ini
  redis/redis.conf         → /etc/redis/redis.conf
```

## Known bugs / deferred fixes

- ~~**Map deletion tab doesn't disappear immediately**~~ Fixed: `updateCurrentMapData` no longer re-inserts a recently-deleted map (WS/poll race guard added in `util.js`).

- **f3-cortex: adopt tagged release**: `ikkez/f3-cortex` is pinned to `dev-master#47d2596` (2025-07-08) because three PHP 8.2 type-hint fixes landed after the `v1.7.8` tag. Once a `v1.7.9`+ tag is published, switch `composer.json` to `"1.7.*"` and drop the commit hash.


