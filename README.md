# Pathfinder Containers

[![Docker Image Master Branch](https://github.com/goryn-clade/pathfinder-containers/actions/workflows/docker-image.yml/badge.svg?branch=master)](https://github.com/goryn-clade/pathfinder-containers/actions/workflows/docker-image.yml)

A Docker Compose deployment for Goryn Clade's [Pathfinder](https://github.com/goryn-clade/pathfinder/) fork, using [Traefik](https://traefik.io/) as a reverse proxy with automatic TLS via Let's Encrypt.

---

## v3.0 Breaking Changes

If you are upgrading from v2.x, note the following changes:

- **Redis → Valkey**: The `redis:7-alpine` image has been replaced with `valkey/valkey:8-alpine`. Valkey is the Linux Foundation fork of Redis, wire-compatible but under an open-source licence. No data migration required.
- **MariaDB image**: Replaced the abandoned `bianjp/mariadb-alpine` with the official `mariadb:10.11` (LTS until 2028).
- **Service renamed**: `pfdb` → `pf-db`. Update any scripts or manual `docker exec` commands.
- **Compose file renamed**: `docker-compose.yml` → `compose.yml`. Use `docker compose` (Compose v2 plugin) rather than the legacy `docker-compose` CLI.
- **SMTP removed**: Email notification support has been removed. Remove any `SMTP_*` variables from your `.env`.
- **Configuration via `.env` only**: Deployment-specific settings (install name, super admin ID, login whitelists, debug level) are now set in `.env`. You no longer need to edit any `.ini` files.
- **`plugin.ini` is the only mounted config file**: `config.ini` and `pathfinder.ini` are now baked into the image. Only `config/pathfinder/plugin.ini` is volume-mounted (for custom module configuration).

### Upgrade steps

1. Export your database before upgrading:
   ```shell
   docker compose exec pf-db mysqldump -u root -p$MYSQL_PASSWORD pathfinder > pathfinder_backup.sql
   ```
2. Pull the new image and recreate containers:
   ```shell
   docker compose pull && docker compose up -d --force-recreate
   ```
3. If the MariaDB data volume was created by the old `bianjp` image, you may need to import your backup into the new container. Check `docker compose logs pf-db` for any startup errors.

---

## Installation

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
   Open `.env` and fill in every value. Each variable is documented with a comment in `.env.example`. The key ones are:
   - `DOMAIN` — your public domain name
   - `CCP_SSO_CLIENT_ID` / `CCP_SSO_SECRET_KEY` — from step 1
   - `MYSQL_PASSWORD` — set a strong password
   - `APP_PASSWORD` — password for the `/setup` page (HTTP Basic Auth, user: `pf`)
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

7. **Verify everything works**, then ensure `PF_DEBUG` is set to `0` in your `.env` for production.

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
