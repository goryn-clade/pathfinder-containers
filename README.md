# Pathfinder Containers

[![Release](https://img.shields.io/github/v/release/goryn-clade/pathfinder-containers?style=flat-square&label=release)](https://github.com/goryn-clade/pathfinder-containers/releases/latest)
[![Images](https://img.shields.io/github/actions/workflow/status/goryn-clade/pathfinder-containers/publish-docker.yaml?style=flat-square&label=images)](https://github.com/goryn-clade/pathfinder-containers/actions/workflows/publish-docker.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE.md)
[![Discord](https://img.shields.io/badge/discord-join-5865F2?style=flat-square&logo=discord&logoColor=white)](https://discord.gg/zG733etVbj)

[![PHP 8.5.12](https://img.shields.io/badge/php-8.5.12-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MariaDB 10.11](https://img.shields.io/badge/mariadb-10.11-003545?style=flat-square&logo=mariadb&logoColor=white)](https://mariadb.org/)
[![Valkey 8](https://img.shields.io/badge/valkey-8-6983FF?style=flat-square)](https://valkey.io/)
[![Traefik 3.6.1](https://img.shields.io/badge/traefik-3.6.1-24A1C1?style=flat-square&logo=traefikproxy&logoColor=white)](https://traefik.io/)

A Docker Compose deployment for Goryn Clade's [Pathfinder](https://github.com/goryn-clade/pathfinder/) fork, using [Traefik](https://traefik.io/) as a reverse proxy with automatic TLS via Let's Encrypt.

---

## Upgrading from v2.x

**v3.0 is a major release.** The PHP runtime, MariaDB image, cache image, reverse proxy, service names, compose file layout, several env variables, and the database schema all change.

**Read the full upgrade guide before you start:** [MIGRATION-v2-to-v3.md](MIGRATION-v2-to-v3.md).

### Breaking changes at a glance

| Area | v2.x | v3.0 |
|---|---|---|
| PHP runtime | 7.2 | 8.5 |
| MariaDB image | `bianjp/mariadb-alpine` (abandoned) | `mariadb:10.11` (official, LTS) |
| Cache | `redis:6.2.5-alpine3.14` | `valkey/valkey:8-alpine` |
| Reverse proxy | Traefik v2.3 | Traefik v3.6.1 |
| Compose file | `docker-compose.yml` | `compose.yml` |
| DB service | `pfdb` | `pf-db` |
| Cache service | `redis` | `pf-redis` |
| Socket service | `pathfinder-socket` | `pf-socket` |
| External `web` network | required | not required |
| Email / SMTP | supported | **removed** - use Slack or Discord webhooks |
| `pathfinder.ini` / `config.ini` | bind-mounted, hand-edited | baked into image, driven by `.env` |
| `plugin.ini` | bind-mounted | bind-mounted (unchanged - only ini file still mounted) |
| DB schema | v2 | adds columns to `system`, `connection`, `map`, `character`; new `map_group` table |
| ESI tokens at rest | plaintext | encrypted (libsodium `crypto_secretbox`) |

### New env variables in v3

`.env.example` documents each. The first group are genuinely new (no v2 equivalent); the second group existed in v2's bind-mounted `pathfinder.ini` / `environment.ini` and are now driven through `.env`.

**Net-new (no v2 equivalent):**

- `SERVER_NAME` - unique-per-install identifier (cache key seed, ESI User-Agent)
- `WS_TOKEN_SECRET` - HMAC secret binding WebSocket tokens to PHP sessions. `pf-socket` refuses to start without it.
- `WS_ALLOWED_ORIGINS` - comma-separated hostnames allowed to open WebSocket connections. `pf-socket` refuses to start in production if empty.
- `TOKEN_ENCRYPTION_KEY` - 32-byte hex key for ESI token encryption at rest. Blank fails closed.
- `REDIS_PASSWORD` - enables Valkey AUTH
- `SESSION_COOKIE_SECURE` - set to `1` on HTTPS deployments
- `APP_ENV` - set to `production` to activate startup guards
- `PF_SETUP_ENABLED` - gates the `/setup` route; set to `1` only during bootstrap, then back to `0`
- `CCP_SSO_USE_PKCE` - optional kill-switch (default on); set to `0` if CCP changes break the PKCE flow

**Moved from `pathfinder.ini` / `environment.ini` (now driven via `.env`):**

- `PF_DEBUG` - F3 debug/error detail level (was `[ENVIRONMENT]/DEBUG`)
- `PF_INSTALL_NAME` - display name shown in UI (was `[PATHFINDER]/NAME`)
- `PF_REGISTRATION_STATUS` - `1` = open, `0` = locked (was `[PATHFINDER.REGISTRATION]/STATUS`)
- `PF_SUPER_ADMIN_ID` - CCP character ID of the super admin
- `PF_SESSION_SHARING` - `1` = characters in the same session share map access, `0` = off (was `[PATHFINDER]/SESSION_SHARING`)
- `PF_LOGIN_ALLOWLIST_CHAR` / `_CORP` / `_ALLIANCE` - login restriction lists (was `[PATHFINDER.LOGIN]/CHARACTER` / `/CORPORATION` / `/ALLIANCE`; renamed from "whitelist")

Generate the random secrets with `openssl rand -hex 32`.

If you are upgrading, follow [MIGRATION-v2-to-v3.md](MIGRATION-v2-to-v3.md) - it covers backup, `.env` rewrite, MariaDB restore, schema migration, and optional bulk encryption of legacy ESI tokens.

---

## Installation (fresh install)

### Prerequisites

- [Docker Engine](https://docs.docker.com/engine/install/) 24+
- [Docker Compose plugin](https://docs.docker.com/compose/install/) v2 (`docker compose`, not `docker-compose`)
- A public domain name pointed at your server
- Ports 80 and 443 open on your server

### Steps

1. **Create a CCP SSO application**
   - Go to the [EVE Online Developer Portal](https://developers.eveonline.com/)
   - Sign in → "MANAGE APPLICATIONS" → "CREATE NEW APPLICATION"
   - Set "CONNECTION TYPE" to "Authentication & API Access"
   - Add the following permissions:
     - `esi-location.read_online.v1`
     - `esi-location.read_location.v1`
     - `esi-location.read_ship_type.v1`
     - `esi-ui.write_waypoint.v1`
     - `esi-ui.open_window.v1`
     - `esi-universe.read_structures.v1`
     - `esi-corporations.read_corporation_membership.v1`
     - `esi-clones.read_clones.v1`
     - `esi-characters.read_corporation_roles.v1`
     - `esi-search.search_structures.v1`
   - Set "CALLBACK URL" to `https://[YOUR_DOMAIN]/sso/callbackAuthorization`

2. **Clone the latest release**
   ```shell
   git clone --branch v3.0.1 --recurse-submodules https://github.com/goryn-clade/pathfinder-containers.git
   cd pathfinder-containers
   ```
   `master` holds work for the next release and can be ahead of the published `:latest` images. Clone a release tag so the config matches the images.

3. **Configure your environment**
   ```shell
   cp .env.example .env
   ```
   Open `.env` and fill in every value. Each variable is documented with a comment in `.env.example`. The minimum set:

   *Identity & TLS:*
   - `DOMAIN` - your public domain name
   - `SERVER_NAME` - unique identifier for this install (used in cache keys and ESI User-Agent)
   - `LE_EMAIL` - email for Let's Encrypt certificate expiry notifications

   *Secrets (generate each with `openssl rand -hex 32`):*
   - `MYSQL_PASSWORD` - MariaDB root password
   - `APP_PASSWORD` - password for the `/setup` page (HTTP Basic Auth, user: `pf`)
   - `REDIS_PASSWORD` - enables Valkey AUTH
   - `WS_TOKEN_SECRET` - HMAC secret for WebSocket tokens
   - `TOKEN_ENCRYPTION_KEY` - encrypts ESI tokens at rest

   *CCP SSO (from step 1):*
   - `CCP_SSO_CLIENT_ID` / `CCP_SSO_SECRET_KEY`

   *Bootstrap & runtime flags:*
   - `PF_SETUP_ENABLED=1` - **required for first boot** so the `/setup` wizard renders; set back to `0` after step 5
   - `APP_ENV=production`
   - `SESSION_COOKIE_SECURE=1`
   - `PF_DEBUG=0`
   - `WS_ALLOWED_ORIGINS` - your `DOMAIN` (comma-separated if multiple)

   *Access control:*
   - `PF_SUPER_ADMIN_ID` - your CCP character ID (grants full admin access)
   - `PF_LOGIN_ALLOWLIST_CORP` / `PF_LOGIN_ALLOWLIST_ALLIANCE` - optional; restrict who can log in

4. **Start the stack**
   ```shell
   docker compose up -d
   ```

5. **Run the setup wizard**
   - Browse to `https://[YOUR_DOMAIN]/setup`
   - Log in with username `pf` and the `APP_PASSWORD` you set
   - In the Database section, click **"Create database"** for both `pathfinder` and `eve_universe`
   - Once the page reloads, click **"Setup tables"** then **"Fix columns/keys"**

6. **Import the EVE universe data**
   ```shell
   docker compose exec -T pf-db sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" eve_universe' < pathfinder/export/sql/eve_universe.sql
   ```

7. **Lock down the install.** Set `PF_SETUP_ENABLED=0` in `.env`, confirm `PF_DEBUG=0` and `APP_ENV=production`, then recreate the app container:
   ```shell
   docker compose up -d --force-recreate pf
   ```

---

## Using Traefik

Traefik is included in the compose stack and handles TLS termination via Let's Encrypt. It starts and stops with the rest of the stack.

If you run other Docker services on the same host that also need HTTPS, consider splitting Traefik into a separate compose project so you can take Pathfinder offline without affecting other containers.

---

## Running behind your own reverse proxy

If you already run nginx, Caddy, Apache or another reverse proxy, you can use it instead of the bundled Traefik. Make these changes in `compose.yml`:

1. Delete the whole `traefik` service.
2. In the `pf` service, delete the `labels:` block (the `traefik.*` lines).
3. In the `pf` service, publish port 80 on a local address only:
   ```yaml
   ports:
     - "127.0.0.1:8000:80"
   ```
4. Point your proxy at `http://127.0.0.1:8000`. It must:
   - allow WebSocket upgrades. Live map updates use `/ws/map/update`.
   - send the `Host`, `X-Forwarded-For` and `X-Forwarded-Proto` headers
   - serve HTTPS. Keep `SESSION_COOKIE_SECURE=1` in `.env`.
   - use the same public hostname as `DOMAIN` and `WS_ALLOWED_ORIGINS` in `.env`. Pathfinder builds its links and the SSO callback from `X-Forwarded-Proto` and this hostname.
5. Start the stack with `docker compose up -d`. If the old `traefik` container is still running, add `--remove-orphans`.

`127.0.0.1:8000` works when your proxy runs directly on the host. If your proxy runs in a Docker container, `127.0.0.1` is that container itself. Skip step 3. Instead, add `pf` to the proxy's Docker network and point the proxy at `http://pathfinder:80`.

An nginx example. The `map` block goes in the `http` context:

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

location / {
    proxy_pass http://127.0.0.1:8000;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection $connection_upgrade;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 8h;
}
```

`LE_EMAIL` is not used without Traefik. Your proxy handles certificates.

These are edits to a file from the release. When you upgrade to a new release, make them again.

---

## Changing PHP settings

You can change PHP settings such as `memory_limit` without rebuilding the image. Mount your own ini file into the `pf` container. PHP reads it after the built-in `zzz_custom.ini`, so your values win.

1. Create `config/php/zzzz-local.ini` with the settings you want to change:
   ```shell
   mkdir -p config/php
   echo 'memory_limit = 512M' > config/php/zzzz-local.ini
   ```
2. In `compose.yml`, add this line under `volumes:` in the `pf` service:
   ```yaml
   - ./config/php/zzzz-local.ini:/usr/local/etc/php/conf.d/zzzz-local.ini:ro
   ```
3. Recreate the container:
   ```shell
   docker compose up -d --force-recreate pf
   ```
4. Check the value that PHP-FPM (the PHP process that serves Pathfinder) uses:
   ```shell
   docker exec pathfinder php-fpm -i | grep memory_limit
   ```
   The result shows `memory_limit => 512M => 512M`.

Create the file before step 3. If it does not exist, Docker creates an empty folder in its place.

After later edits to the file, run `docker compose restart pf`.

---

## Passwords that contain `$`

Docker Compose treats `$` in `.env` as the start of a variable name. `MYSQL_PASSWORD="ab$cd"` becomes `ab`, and Compose warns that the `cd` variable is not set. This applies to every value in `.env`, not only `MYSQL_PASSWORD`.

Use one of these:

- Put the value in single quotes. Compose keeps it exactly as written: `MYSQL_PASSWORD='ab$cd'`
- Or write each `$` as `$$`, with or without double quotes: `MYSQL_PASSWORD="ab$$cd"`

Do not combine them. In single quotes, `$$` stays as two characters.

Passwords made with `openssl rand -hex 32` contain only `0-9` and `a-f`, so they avoid the problem. Use them where you can. Quoting fixes `$` only: the container also writes the passwords into config files and connection strings, where characters such as `,` `"` `;` `:` `@` `&` and `#` can break them.

---

## Development

Use `compose.dev.yml` for local development - it builds from source, skips Traefik, and exposes the app directly on port 80:

```shell
cp .env.example .env
# fill in CCP SSO credentials, set DOMAIN=localhost

docker compose -f compose.dev.yml build --no-cache pf
docker compose -f compose.dev.yml up -d

# Watch logs
docker logs -f pathfinder

# Rebuild after code changes
docker compose -f compose.dev.yml build --no-cache pf && docker compose -f compose.dev.yml up -d --force-recreate pf
```
---

## Support

For help with installing, upgrading or running Pathfinder, join the Discord server: <https://discord.gg/zG733etVbj>

Report bugs in the [issue tracker](https://github.com/goryn-clade/pathfinder/issues).

---

## Acknowledgments

- [exodus4d](https://github.com/exodus4d/) for creating Pathfinder
- [techfreak](https://gitlab.com/techfreak/pathfinder-container) for the original container project
- [tyrheimdaleve](https://github.com/TyrHeimdalEVE/pathfinder_esi) for maintaining the `pathfinder_esi` dependency

## License

MIT - see [LICENSE.md](LICENSE.md)
