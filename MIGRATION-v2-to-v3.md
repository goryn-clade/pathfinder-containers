# Migration guide — v2.x → v3.0 (Goryn Clade fork)

This guide walks operators of an existing Pathfinder deployment (the
`goryn-clade/pathfinder-containers:master` v2.x stack) through upgrading to
the `v3-fixes-and-features` branch.

v3.0 is a major release. The PHP runtime, MariaDB image, Redis image,
service names, compose file layout, env variables, and several DB tables
all change. Read the whole guide before you start; **back up your database
volume first** (step 1).

For the rationale behind individual changes, see [CHANGELOG.md](CHANGELOG.md).

---

## Breaking changes at a glance

| Area | v2.x (master) | v3.0 (v3-fixes-and-features) |
|---|---|---|
| PHP runtime | 7.2 | 8.3 |
| MariaDB image | `bianjp/mariadb-alpine:latest` (abandoned) | `mariadb:10.11` (official, LTS) |
| Cache | `redis:6.2.5-alpine3.14` | `valkey/valkey:8-alpine` |
| Reverse proxy | Traefik v2.3 | Traefik v3.6.1 |
| Compose file | `docker-compose.yml` | `compose.yml` (+ `compose.dev.yml`, `compose.test.yml`) |
| DB service name | `pfdb` | `pf-db` |
| Redis service name | `redis` (container `redis`) | `pf-redis` |
| Socket service name | `pathfinder-socket` (container `socket`) | `pf-socket` |
| Web network | external `web` network required | not required |
| Email/SMTP | supported | **removed** |
| `pathfinder.ini` | bind-mounted, hand-edited | baked into image, driven by `.env` |
| `config.ini` | bind-mounted | baked into image |
| `redis.conf` | not used | shared `config/redis/redis.conf` (see [MIGRATION-config-static-cleanup.md](MIGRATION-config-static-cleanup.md)) |
| pathfinder DB schema | v2 schema | adds `system.groupId`, `system.securityClass`, `connection.nominalLifespan`, `map.allowUnknownSystems`, `map.granularK162`, `map.allowGroups`, `character.affiliationUpdated`, new `map_group` table |

If you are happy with the defaults, the upgrade is essentially:
**back up → pull → rewrite `.env` → recreate stack → rerun `/setup` table migration → review map settings.**

---

## 1. Back up before doing anything

The MariaDB image is changing from `bianjp/mariadb-alpine` (Alpine, MariaDB
10.4 era) to the official `mariadb:10.11`. The on-disk file format is
forward-compatible in practice, but a clean dump/restore is strongly
recommended — it avoids any chance of the new server refusing to start on
the old data dir, and gives you a known-good restore point.

```bash
# from your existing deployment (still on v2.x / master)
docker compose exec pfdb sh -c \
  "mysqldump -u root -p\$MYSQL_ROOT_PASSWORD --all-databases --routines --triggers --events" \
  > pathfinder-backup-$(date +%Y%m%d).sql

# also snapshot the redis_data volume in case you want to roll back
docker run --rm -v pathfinder_redis_data:/data -v "$PWD":/backup alpine \
  tar czf /backup/redis_data-$(date +%Y%m%d).tgz -C /data .
```

Confirm the dump file is non-empty and contains your `pathfinder` and
`eve_universe` databases before continuing.

> **Note:** the v2 service was named `pfdb`; the env var was
> `MYSQL_ROOT_PASSWORD` inside the container. Adjust if your `.env` uses a
> different name.

Stop the v2 stack:

```bash
docker compose down
```

Do **not** delete the `db_data` or `redis_data` volumes yet. They are your
fallback if anything goes wrong.

---

## 2. Pull the v3 branch

```bash
git fetch origin
git checkout v3-fixes-and-features
git submodule update --init --recursive
```

You should now see `compose.yml`, `compose.dev.yml`, `compose.test.yml`,
`MIGRATION-config-static-cleanup.md`, and a refreshed `.env.example`.

The legacy `docker-compose.yml` is renamed to `_docker-compose.yml` (kept
for reference only). All v3 commands use the new `docker compose`
(plugin) form, never the legacy `docker-compose` binary.

---

## 3. Rewrite your `.env`

The variable set has changed substantially. The simplest path is to start
from `.env.example` and copy your existing secrets into it:

```bash
cp .env .env.v2.bak
cp .env.example .env
$EDITOR .env
```

### Removed variables

Delete these from your old `.env`; they have no effect in v3:

```
SMTP_HOST  SMTP_PORT  SMTP_SCHEME  SMTP_USER  SMTP_PASS  SMTP_FROM  SMTP_ERROR
```

Email notifications (rally pokes, error mail, account mail) have been
removed entirely along with the abandoned Swiftmailer dependency. Use the
Slack or Discord webhook integrations instead — they cover the same
notification flows.

### Renamed defaults

| v2 default | v3 default | Notes |
|---|---|---|
| `MYSQL_HOST="mariadb"` | `MYSQL_HOST="pf-db"` | Match the new service name |
| `REDIS_HOST="redis"` | `REDIS_HOST="pf-redis"` | Match the new service name |
| `PATHFINDER_SOCKET_HOST="pathfinder-socket"` | `PATHFINDER_SOCKET_HOST="pf-socket"` | Match the new service name |

If you keep your old hostnames, update them — there is no compose alias
fallback in v3.

### New required-or-recommended variables

| Variable | Purpose | Recommended value |
|---|---|---|
| `SERVER_NAME` | Cache key seed (md5) and ESI User-Agent. Should be unique per install. | e.g. `pathfinder-yourcorp` |
| `SESSION_COOKIE_SECURE` | `1` on HTTPS deployments, `0` for plain HTTP dev | `1` for prod |
| `REDIS_PASSWORD` | Enables Valkey AUTH. Leave blank only if Redis is strictly Docker-network-internal. | `openssl rand -hex 32` |
| `WS_TOKEN_SECRET` | HMAC secret binding WebSocket access tokens to PHP sessions. **Must match in `pf` and `pf-socket` containers** (both read it from `.env`). **`pf-socket` refuses to start** if this is absent or shorter than 32 hex characters. | `openssl rand -hex 32` |
| `WS_ALLOWED_ORIGINS` | Comma-separated hostnames that browsers are allowed to open WebSocket connections from (matched against the HTTP `Origin` header). Example: `pathfinder.example.com,maps.example.com`. **`pf-socket` refuses to start if this is empty when `APP_ENV=production`**. In non-production environments `localhost` and `127.0.0.1` are always auto-allowed regardless. | your app's public hostname(s) |
| `TOKEN_ENCRYPTION_KEY` | 32-byte hex key (64 chars) used to encrypt ESI access/refresh tokens at rest with libsodium `crypto_secretbox`. Required: blank fails closed (all token reads return empty, users forced through SSO). See [Key rotation](#key-rotation-token_encryption_key) below before changing this value on a running deployment. | `openssl rand -hex 32` |
| `APP_ENV` | Set to `production` to activate startup guards: `pf-socket` refuses to start if `WS_ALLOWED_ORIGINS` is empty; `pf` warns loudly if `PF_DEBUG > 0`. | `production` for prod |
| `PF_DEBUG` | F3 debug/error level. **Must be `0`** on internet-facing deployments — higher values expose stack traces, and the SQL-injection redaction in `Controller::showError` is gated on `DEBUG < 1`. | `0` for prod |
| `PF_INSTALL_NAME` | Display name in UI/title bar. Replaces hand-editing `[PATHFINDER] NAME` in `pathfinder.ini`. | e.g. `Pathfinder — Goryn Clade` |
| `PF_REGISTRATION_STATUS` | `1` open registration, `0` locked. Replaces hand-editing `[PATHFINDER.REGISTRATION] STATUS`. | per your policy |
| `PF_SUPER_ADMIN_ID` | CCP character ID granted SUPER admin. Replaces `[PATHFINDER.ROLES] CHARACTER.0.ID`. | your character ID |
| `PF_LOGIN_ALLOWLIST_CHAR` | Comma-separated CCP character IDs (blank = no restriction). **Renamed from `PF_LOGIN_WHITELIST_CHAR`** — update your `.env` if you carried this from v2. | per your policy |
| `PF_LOGIN_ALLOWLIST_CORP` | Comma-separated CCP corp IDs. **Renamed from `PF_LOGIN_WHITELIST_CORP`**. | per your policy |
| `PF_LOGIN_ALLOWLIST_ALLIANCE` | Comma-separated CCP alliance IDs. **Renamed from `PF_LOGIN_WHITELIST_ALLIANCE`**. | per your policy |
| `CCP_SSO_USE_PKCE` | Kill-switch for PKCE on the CCP SSO authorization flow. Enabled by default; set to `0` only if upstream CCP changes break the PKCE handshake. | `1` (default) |

`pathfinder.ini` and `config.ini` are no longer bind-mounted — every
deployment-specific value flows from `.env` through `envsubst` at container
start. If you previously hand-edited those files, your edits will be lost;
port them into the relevant `PF_*` env var or, for values not yet exposed,
edit `static/pathfinder/pathfinder.ini` and rebuild the image. See
[MIGRATION-config-static-cleanup.md](MIGRATION-config-static-cleanup.md)
for the layout-cleanup details.

`plugin.ini` is the only ini file still bind-mounted at runtime.

### Security hardening you inherit automatically

v3 ships a substantial set of SSO, session, and WebSocket hardening changes. None require operator action beyond setting the env vars above, but you should be aware of them:

- **CCP SSO**: PKCE (RFC 7636) on the authorization flow, JWT `iss`/`aud` verified on every callback, per-tab SSO state map (previous v2 single-slot state could collide across tabs), JWKS cached in F3 for 1h with `kid`-miss invalidation, `random_bytes` CSPRNG everywhere, refresh tokens persisted after refresh, secret/code redaction in SSO error logs.
- **Sessions / cookies**: remember-me cookie is rotated on each use, selector widened from 12 → 16 bytes, session is destroyed and the remember-me cookie cleared on logout.
- **ESI tokens at rest**: encrypted with libsodium `crypto_secretbox`. Requires the `sodium` PHP extension (declared in `composer.json` as `ext-sodium`; bundled in the v3 image — only relevant if you build your own runtime).
- **WebSocket server**: origin allow-list (`WS_ALLOWED_ORIGINS`), HMAC-bound tokens (`WS_TOKEN_SECRET`), concurrent connection cap of 5000, WS frames over 64 KB rejected, outbound frames have `characterIds` stripped before broadcast, log file paths locked to an allowed root.

---

## 4. Restore the database into the new MariaDB

Bring up only the database service first so you can import cleanly:

```bash
docker compose up -d pf-db
docker compose logs -f pf-db   # wait for "ready for connections", Ctrl-C to detach
```

If you took the dump in step 1, restore it now:

```bash
docker compose exec -T pf-db sh -c \
  "mysql -u root -p\$MYSQL_ROOT_PASSWORD" < pathfinder-backup-YYYYMMDD.sql
```

Reimport the EVE universe dump as well — v3 ships an updated
`eve_universe.sql.zip` with Zarzakh, Pochven/Trailblazer, and frigate
wormhole lifetime fixes baked in:

```bash
docker compose exec pf-db sh -c \
  "unzip -p /eve_universe.sql.zip | mysql -u root -p\$MYSQL_ROOT_PASSWORD eve_universe"
```

> **If you skip the dump/restore and reuse the `db_data` volume directly:**
> the official `mariadb:10.11` server will usually start fine on the old
> data dir, but check `docker compose logs pf-db` for upgrade warnings and
> run `docker compose exec pf-db mariadb-upgrade -u root -p$MYSQL_PASSWORD`
> if prompted. The dump/restore path is still recommended.

---

## 5. Bring up the rest of the stack

```bash
docker compose up -d
docker compose ps
docker compose logs -f pf
```

Watch for:
- `entrypoint.sh` warnings about `PF_DEBUG > 0` in production (fix `.env`).
- Redis AUTH errors on `pf` startup (mismatched `REDIS_PASSWORD`).
- `FATAL: TOKEN_ENCRYPTION_KEY must be a 64-char hex string` — `pf` exiting immediately at entrypoint; generate one with `openssl rand -hex 32` and recreate.
- `FATAL: WS_TOKEN_SECRET must be at least 32 hex characters` — `pf-socket` exiting immediately; set a valid secret and recreate.
- `FATAL: WS_ALLOWED_ORIGINS must not be empty in production` — set `WS_ALLOWED_ORIGINS` to your app's public hostname(s) and recreate.

---

## 6. Run the schema migration via `/setup`

v3 adds several DB columns and one new table. The setup wizard handles all
of them.

| Where | Column / table | Notes |
|---|---|---|
| `system` | `groupId` (nullable INT) | links a system to a `map_group`; SET NULL on group delete |
| `system` | `securityClass` (VARCHAR) | feeds the "unknown system" placeholder |
| `system.systemId` | now nullable | for unknown systems |
| `connection` | `nominalLifespan` (nullable INT seconds, NULL = 24h) | per-phase EOL expiry |
| `connection.type` whitelist | adds `wh_eol1`, `wh_eol2`, `wh_eol3` | old `wh_eol` rows auto-map to `wh_eol1` on read |
| `map` | `allowUnknownSystems` (BOOL, default 0) | enables `???` placeholders |
| `map` | `granularK162` (BOOL, default 0) | per-class K162 sig dropdown |
| `map` | `allowGroups` (BOOL, **default 1**) | grouping on by default |
| `character` | `affiliationUpdated` (nullable timestamp) | per-login affiliation refresh gate |
| new table | `map_group` | subgraph containers for system grouping |

Steps:

1. Browse to `https://[YOUR_DOMAIN]/setup` (HTTP Basic Auth: user `pf`,
   password from `APP_PASSWORD`).
2. In the **Database** section click **Setup tables**, then
   **Fix columns/keys**. Cortex auto-migrates new columns; you should see
   green ticks for `system`, `connection`, `map`, `character`, and
   `map_group`.
3. If `map_group` is missing from the schema list, hard-reload the setup
   page — the schema list is built from `Setup.php`, which now includes
   `MapGroupModel`.

No data migration scripts are needed beyond this — every new column has a
safe default, and old `wh_eol` rows are translated on read by
`ConnectionModel::getData()`.

### Encrypt existing ESI tokens (recommended)

v3 encrypts ESI access/refresh tokens at rest. Brand-new logins after
deploy land encrypted automatically. Pre-existing rows from your v2
database still hold plaintext tokens until the relevant character next
hits the token-refresh path — which, for inactive characters, may never
happen on its own.

To encrypt every legacy row in one pass:

```bash
docker compose run --rm \
  -e TOKEN_ENCRYPTION_KEY="$(grep ^TOKEN_ENCRYPTION_KEY= .env | cut -d= -f2 | tr -d '"')" \
  pf php /usr/local/bin/migrate-tokens.php --dry-run

docker compose run --rm \
  -e TOKEN_ENCRYPTION_KEY="$(grep ^TOKEN_ENCRYPTION_KEY= .env | cut -d= -f2 | tr -d '"')" \
  pf php /usr/local/bin/migrate-tokens.php
```

The script reports `total / migrated / already_encrypted / empty`. It is
idempotent — already-encrypted rows (`v1:` prefix) are skipped, so it can
be re-run safely.

---

## 7. Review map settings

Three new map-level toggles need a one-time review per existing map.
Defaults are conservative, but `allowGroups` defaults to **on** for new and
existing maps (the column default is `1`).

For each map, open **Map settings** (right-click the map tab → Settings)
and review:

| Setting | Default | What it does |
|---|---|---|
| **Allow Unknown systems** | off | Adds an "Unknown" checkbox to the add-system dialog and a security-class picker; lets you place `???` placeholder nodes for unscanned destinations before jumping. |
| **Granular K162** | off | Replaces the grouped K162 incoming-WH labels (C1/2/3, C4/5, C6, …) with per-class options (C1, C2, …, C6, H, L, 0.0, Thera, Pochven, drifter holes C14–C18). |
| **Allow Groups** | **on** | Enables right-click "Add group" on the map and per-system "Add to group" actions. Disable if you want to hide the feature for a given map. |

Also worth re-checking on each map after upgrade:
- **Track Abyssal jumps** — unchanged behaviour, just verify the value
  survived the upgrade.
- **Slack / Discord webhooks** — Discord webhooks now use native embed
  format. URLs ending in `/slack` are no longer needed; the app strips the
  suffix automatically, but a freshly entered Discord webhook URL should
  point at the bare webhook (`https://discord.com/api/webhooks/.../...`).

---

## 8. Verify

```bash
# App config picked up your .env values
docker exec pathfinder cat /var/www/html/pathfinder/app/pathfinder.ini \
  | grep -E '^NAME|^STATUS|^CHARACTER\.0\.ID'

# Valkey is loading the shared conf and AUTH is on
docker exec $(docker compose ps -q pf-redis) valkey-cli -a "$REDIS_PASSWORD" CONFIG GET maxmemory
# → maxmemory / 67108864    (default 0 means redis.conf was not loaded)

# WebSocket server started cleanly
docker compose logs pf-socket | grep -E "start (WebSocket|Socket) server"
# → two lines: "start WebSocket server…" and "start Socket server…"

# PHP version (should be 8.3) and sodium extension loaded
docker exec pathfinder php -v
docker exec pathfinder php -m | grep -i sodium   # → sodium
```

Smoke test: log in, open an existing map, paste a signature, jump systems,
check that connections render with the new EOL phase pills (`Ph.1 / Ph.2 /
Ph.3`) where applicable. Confirm `docker logs pathfinder` is clean of 500s.

---

## Key rotation (`TOKEN_ENCRYPTION_KEY`)

v3 encrypts ESI access and refresh tokens at rest with libsodium
`crypto_secretbox`. The key lives in `TOKEN_ENCRYPTION_KEY`. The first time
a token is read after deploy, any legacy plaintext row decrypts as a
passthrough and is re-stored encrypted on the next refresh — no operator
action required.

Replacing `TOKEN_ENCRYPTION_KEY` on a running deployment **without** first
re-encrypting the DB will invalidate every stored token. The app handles
this gracefully (decryption fails, users are sent through SSO again), but
every active user will be forced to re-login.

To rotate without that disruption:

```bash
# 1. Generate the new key
NEW=$(openssl rand -hex 32)
echo "new TOKEN_ENCRYPTION_KEY: $NEW"

# 2. Scan only (no writes) with both keys present
docker compose run --rm \
  -e OLD_TOKEN_ENCRYPTION_KEY="$(grep ^TOKEN_ENCRYPTION_KEY= .env | cut -d= -f2 | tr -d '"')" \
  -e NEW_TOKEN_ENCRYPTION_KEY="$NEW" \
  pf php /usr/local/bin/rotate-token-key.php --dry-run

# 3. Re-encrypt
docker compose run --rm \
  -e OLD_TOKEN_ENCRYPTION_KEY="$(grep ^TOKEN_ENCRYPTION_KEY= .env | cut -d= -f2 | tr -d '"')" \
  -e NEW_TOKEN_ENCRYPTION_KEY="$NEW" \
  pf php /usr/local/bin/rotate-token-key.php

# 4. Update .env, restart
sed -i.bak "s|^TOKEN_ENCRYPTION_KEY=.*|TOKEN_ENCRYPTION_KEY=\"$NEW\"|" .env
docker compose up -d --force-recreate pf
```

The script reports `total / rotated / skipped / failed`. A non-zero
`failed` count means some rows couldn't be decrypted with the old key
(corrupt blob, or the row was already encrypted with the new key from a
prior partial run) — those users will be sent through SSO on next access.
The script source lives at `static/scripts/rotate-token-key.php`.

---

## Rotating other secrets

### WS_TOKEN_SECRET

`WS_TOKEN_SECRET` is the HMAC key that binds WebSocket access tokens to PHP sessions. Rotate it if you believe it has leaked.

```bash
# 1. Generate a new secret
NEW_WS=$(openssl rand -hex 32)
echo "new WS_TOKEN_SECRET: $NEW_WS"

# 2. Update .env
sed -i.bak "s|^WS_TOKEN_SECRET=.*|WS_TOKEN_SECRET=\"$NEW_WS\"|" .env

# 3. Restart BOTH pf and pf-socket — they must share the same key
docker compose up -d --force-recreate pf pf-socket
```

Effect: all in-flight WebSocket sessions become invalid immediately. The browser-side client reconnects automatically within a few seconds; users will not notice unless they were in the middle of a map action.

### CCP_SSO_SECRET_KEY

`CCP_SSO_SECRET_KEY` is the OAuth 2.0 client secret issued by CCP. Rotating it requires a matching change at the CCP developer portal.

Steps:
1. Log in at https://developers.eveonline.com/applications and select your application.
2. Regenerate (or delete and re-add) the secret key. Copy the new value.
3. Update `.env`:
   ```
   CCP_SSO_SECRET_KEY="<new-value>"
   ```
4. Restart `pf`:
   ```bash
   docker compose up -d --force-recreate pf
   ```

Effect: any SSO login in progress at the moment of the restart will fail with `invalid_client` at CCP's token endpoint; the user retries. Stored ESI refresh tokens are **not** affected — they are issued by CCP's auth server separately and remain valid until revoked or expired.

---

## Rollback

If the upgrade goes badly and you need to roll back to v2.x:

```bash
docker compose down
git checkout master
git submodule update --init --recursive

# restore the v2 .env you backed up in step 3
mv .env.v2.bak .env

# restore the v2 database dump into the v2 db image
docker compose -f docker-compose.yml up -d pfdb
docker compose -f docker-compose.yml exec -T pfdb sh -c \
  "mysql -u root -p\$MYSQL_ROOT_PASSWORD" < pathfinder-backup-YYYYMMDD.sql
docker compose -f docker-compose.yml up -d
```

The new v3 columns (`groupId`, `securityClass`, `nominalLifespan`,
`allowUnknownSystems`, `granularK162`, `allowGroups`, `affiliationUpdated`)
and the `map_group` table are additive — they will linger on a v2 schema
without harm if you choose not to restore the dump, but the cleanest
rollback path is the dump-restore above.

---

## FAQ

**Q: Will my `redis_data` volume work with Valkey?**
A: Yes. Valkey 8 reads Redis 6/7 RDB and AOF files. No conversion needed.

**Q: I had a `web` external Docker network for Traefik. Do I still need it?**
A: No. v3's `compose.yml` does not declare a `web` network — Traefik runs
inside the project network. If you split Traefik into its own compose
project (recommended for multi-app hosts), recreate a shared network and
add it to both projects.

**Q: My existing `pathfinder.ini` has values not exposed as `PF_*` env vars.**
A: Edit `static/pathfinder/pathfinder.ini` directly and rebuild the image
(`docker compose build pf`). The bind-mount-override pattern was removed
to keep dev/test/prod consistent — see
[MIGRATION-config-static-cleanup.md](MIGRATION-config-static-cleanup.md).
If a value is broadly useful, open an issue to expose it as an env var.

**Q: Do I need to clear caches?**
A: Compiled F3 templates in `pathfinder/tmp/` are inside the image, so
they're already fresh after `docker compose pull` / `build`. The Redis
cache is fine to keep — keys are namespaced by `SERVER_NAME`'s md5 so
stale entries are ignored, and Valkey's first-run AOF replay is
transparent.

**Q: What happened to email rally pokes?**
A: Removed with the rest of SMTP. Use the Discord or Slack rally webhook
per map (`SEND_RALLY_DISCORD_ENABLED` / `SEND_RALLY_SLACK_ENABLED` in
`pathfinder.ini`).
