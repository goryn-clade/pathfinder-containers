# Pathfinder Containers

A Docker Compose deployment for Goryn Clade's [Pathfinder](https://github.com/goryn-clade/pathfinder/) fork, using [Traefik](https://traefik.io/) as a reverse proxy with automatic TLS via Let's Encrypt.

---

## Upgrading from v2.x

**v3.0 is a major release.** The PHP runtime, MariaDB image, cache image, reverse proxy, service names, compose file layout, several env variables, and the database schema all change.

**Read the full upgrade guide before you start:** [.claude/MIGRATION-v2-to-v3.md](.claude/MIGRATION-v2-to-v3.md).

### Breaking changes at a glance

| Area | v2.x | v3.0 |
|---|---|---|
| PHP runtime | 7.2 | 8.3 |
| MariaDB image | `bianjp/mariadb-alpine` (abandoned) | `mariadb:10.11` (official, LTS) |
| Cache | `redis:6.2.5-alpine3.14` | `valkey/valkey:8-alpine` |
| Reverse proxy | Traefik v2.3 | Traefik v3.6.1 |
| Compose file | `docker-compose.yml` | `compose.yml` (+ `compose.dev.yml`, `compose.test.yml`) |
| DB service | `pfdb` | `pf-db` |
| Cache service | `redis` | `pf-redis` |
| Socket service | `pathfinder-socket` | `pf-socket` |
| External `web` network | required | not required |
| Email / SMTP | supported | **removed** — use Slack or Discord webhooks |
| `pathfinder.ini` / `config.ini` | bind-mounted, hand-edited | baked into image, driven by `.env` |
| `plugin.ini` | bind-mounted | bind-mounted (unchanged — only ini file still mounted) |
| DB schema | v2 | adds columns to `system`, `connection`, `map`, `character`; new `map_group` table |
| ESI tokens at rest | plaintext | encrypted (libsodium `crypto_secretbox`) |

### New required env variables

These have no v2 equivalent — `.env.example` documents each:

- `SERVER_NAME` — unique-per-install identifier (cache key seed, ESI User-Agent)
- `WS_TOKEN_SECRET` — HMAC secret binding WebSocket tokens to PHP sessions. `pf-socket` refuses to start without it.
- `WS_ALLOWED_ORIGINS` — comma-separated hostnames allowed to open WebSocket connections. `pf-socket` refuses to start in production if empty.
- `TOKEN_ENCRYPTION_KEY` — 32-byte hex key for ESI token encryption at rest. Blank fails closed.
- `REDIS_PASSWORD` — enables Valkey AUTH
- `SESSION_COOKIE_SECURE` — set to `1` on HTTPS deployments
- `APP_ENV` — set to `production` to activate startup guards

Generate the random secrets with `openssl rand -hex 32`.

If you are upgrading, follow [.claude/MIGRATION-v2-to-v3.md](.claude/MIGRATION-v2-to-v3.md) — it covers backup, `.env` rewrite, MariaDB restore, schema migration, and optional bulk encryption of legacy ESI tokens.

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

2. **Clone the repository**
   ```shell
   git clone --recurse-submodules https://github.com/goryn-clade/pathfinder-containers.git
   cd pathfinder-containers
   ```

3. **Configure your environment**
   ```shell
   cp .env.example .env
   ```
   Open `.env` and fill in every value. Each variable is documented with a comment in `.env.example`. The minimum set:
   - `DOMAIN` — your public domain name
   - `SERVER_NAME` — unique identifier for this install
   - `CCP_SSO_CLIENT_ID` / `CCP_SSO_SECRET_KEY` — from step 1
   - `MYSQL_PASSWORD` / `MYSQL_ROOT_PASSWORD` — set strong passwords
   - `APP_PASSWORD` — password for the `/setup` page (HTTP Basic Auth, user: `pf`)
   - `REDIS_PASSWORD` — `openssl rand -hex 32`
   - `WS_TOKEN_SECRET` — `openssl rand -hex 32`
   - `WS_ALLOWED_ORIGINS` — your `DOMAIN`
   - `TOKEN_ENCRYPTION_KEY` — `openssl rand -hex 32`
   - `APP_ENV=production`
   - `SESSION_COOKIE_SECURE=1`
   - `PF_DEBUG=0`
   - `PF_SUPER_ADMIN_ID` — your CCP character ID (grants full admin access)
   - `PF_LOGIN_WHITELIST_CORP` / `PF_LOGIN_WHITELIST_ALLIANCE` — optional; restrict who can log in

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
   docker compose exec pf-db sh -c "unzip -p /eve_universe.sql.zip | mysql -u root -p\$MYSQL_ROOT_PASSWORD eve_universe"
   ```

7. **Verify everything works**, then confirm `PF_DEBUG=0` and `APP_ENV=production` in your `.env`.

---

## Using Traefik

Traefik is included in the compose stack and handles TLS termination via Let's Encrypt. It starts and stops with the rest of the stack.

If you run other Docker services on the same host that also need HTTPS, consider splitting Traefik into a separate compose project so you can take Pathfinder offline without affecting other containers.

---

## Development

Use `compose.dev.yml` for local development — it builds from source, skips Traefik, and exposes the app directly on port 80:

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

For step-through debugging with VSCode and Xdebug, see the `development/` directory.

---

## Acknowledgments

- [exodus4d](https://github.com/exodus4d/) for creating Pathfinder
- [techfreak](https://gitlab.com/techfreak/pathfinder-container) for the original container project
- [johnschultz](https://gitlab.com/johnschultz/pathfinder-container/) for Traefik config improvements
- [tyrheimdaleve](https://github.com/TyrHeimdalEVE/pathfinder_esi) for maintaining the `pathfinder_esi` dependency

## License

MIT — see [LICENSE.md](LICENSE.md)
