# Pathfinder v3.0.1 — release notes

v3.0.1 is the first final release of Pathfinder v3. It moves Pathfinder from PHP 7.2 to PHP 8.5, brings in years of community fixes and features, and adds the results of a security audit, corrected wormhole data and stability fixes.

v3.0.0 was only released as betas. Its changes are listed below because they ship for the first time in v3.0.1.

If you run v2, upgrade straight to v3.0.1. Follow [`MIGRATION-v2-to-v3.md`](MIGRATION-v2-to-v3.md).

---

## For server admins

### Breaking changes — read before you upgrade

- **Platform:** PHP 7.2 → 8.5.12. MariaDB → official `mariadb:10.11`. Redis → Valkey 8 (the service is still called `pf-redis`).
- **Config:** `config.ini` and `pathfinder.ini` are baked into the image. Settings move to `.env` (`PF_*` variables).
- **Renamed:** `PF_LOGIN_WHITELIST_*` → `PF_LOGIN_ALLOWLIST_*`. There is no fallback.
- **New required secrets:** `WS_TOKEN_SECRET`, `TOKEN_ENCRYPTION_KEY`, `CCP_SSO_SECRET_KEY`, `APP_PASSWORD` and `MYSQL_PASSWORD`. The container refuses to start without them, or when an old whitelist variable is set but its allowlist is empty.
- **Shut v2's database down cleanly before you upgrade.** `docker compose down` kills v2's MariaDB instead of stopping it, and MariaDB 10.11 then refuses to start on the volume ("Upgrade after a crash is not supported"). `MIGRATION-v2-to-v3.md` step 1b shows how to stop it properly.
- **Removed:** SMTP and rally email, `EXPIRE_CONNECTIONS_EOL` and `EXPIRE_CONNECTIONS_WH` (replaced by `EXPIRE_CONNECTIONS_NOMINAL_DEFAULT`).
- **Discord webhooks:** remove any `/slack` suffix from the URL.
- **Use the new `compose.yml`.** It keeps the Redis password out of `ps` and pins the image versions.

### After you upgrade

1. Open `/setup` and run the **Wormholes**, **Ships** and **Structures** imports. They now always fetch fresh data from ESI.
2. Run the **Wormhole statics** import, then **Build the systems index**. The statics import removes statics that are no longer valid.
3. Flush Redis.
4. Set `PF_SETUP_ENABLED=0` when you have finished with `/setup`.

### New options

`PF_SESSION_SHARING`, `PF_SETUP_ENABLED`, `SESSION_COOKIE_SECURE` and `CCP_SSO_USE_PKCE`. See `.env.example`.

### Security

- **PHP 8.5.12** includes the fixes for CVE-2026-109618 and CVE-2026-109619 (remote code execution in PHP itself). The image now builds on the official `php:8.5.12-fpm-alpine` image, so PHP updates no longer wait for Alpine packages. The WebSocket server is pinned to the same PHP release.
- ESI tokens are encrypted at rest. `migrate-tokens.php` encrypts old rows, and `rotate-token-key.php` changes the key.
- `/setup` is protected properly. `APP_PASSWORD` is only the Basic Auth password and never appears in a page.
- WebSocket tokens are signed and bound to the session.
- `/admin` actions use POST with a token, which blocks cross-site requests.
- Map rights are checked by the server, not only in the browser. A connection, log entry or structure can no longer be read or changed through another map or corporation.
- Text that users enter (system descriptions, signature notes, structure names, aliases, jump logs) can no longer run scripts in other users' browsers.
- nginx runs only `index.php`, routes are case-sensitive, `/api/` is rate-limited per real client IP, and security headers are added. The killboard proxy needs a login and has its own rate limit.
- Webhook URLs are no longer sent to every map client, and failed webhook calls don't log the URL.
- `/setup` actions can only clear the app's own folders and flush the app's own Redis.
- SQL injection in route search fixed. The session id is regenerated on login.
- Dependencies updated, including Guzzle, psr7, Monolog, ReactPHP and Ratchet's Symfony components. `composer audit` is clean for the app and the WebSocket server.

### Other changes

- **PHP-FPM uses `pm = dynamic`.** It keeps 2–6 workers ready, which stops the `updateUserData` 504 timeouts. Expect about 30 MB of memory per idle worker.
- PHP dependencies install exactly from `composer.lock`, so every build is the same.
- nginx follows a restarted `pf-socket` without a restart of `pf`.
- `pf-redis` survives a container restart.
- `pathfinder_esi` is at `v3.0.1`.
- New maintainer tools: `export/tools/refresh_universe_types.php` refreshes the seed data from ESI, and `export/tools/build_system_static.php` refreshes statics from Tripwire.

---

## For mappers

### New

- **Three-phase EOL.** Connections show Aging (1–4 h), Expiring (0–1 h) or Zombie (past end) instead of one "EOL" state. Route search can filter each phase.
- **Mass class from the system.** New connections pick the right size from the endpoint class (C1 → Medium, C13 → Small). Setting the signature type (for example M001) updates the connection.
- **Unknown systems (`???`).** Maps with "Allow Unknown systems" can place a placeholder before you jump. The `+` in a signature's "Leads to" popover creates one with the class filled in.
- **Granular K162.** An optional map setting lists K162s per destination class.
- **Map groups (beta).** Group containers to organise systems on the map. Turn them on in map settings with "Enable Groups (beta)".
- **Drifter holes (C14–C18)** with their signatures and friendly names. **Turnur** in route search, next to Thera.
- **Discord embeds** for rally pings and map webhooks.
- **Killstream** moved to zKillboard's R2Z2 feed (the old WebSocket was shut down), with an NPC-kills filter.
- **Light and high-contrast themes** now cover the map canvas.

### Fixed

- **Wormhole data matches the game again.** 45 of 130 wormhole types had a wrong class, lifetime, mass or signature strength. J244 statics showed blank. New types R327 and O753 are added.
- **Statics updated from Tripwire:** 34 added, 4 removed. This includes Turnur, the drifter systems, and several K346 and Y790 corrections.
- **Ships:** 30 ships had a wrong mass or volume (for example the Paladin), and 9 newer ships were missing. This affects the jumped-mass log.
- **Fewer 504 timeouts** while the map polls for updates.
- The map log loads again.
- Deleting several systems at once no longer fails.
- Pasting several structures saved only the last one.
- The first signature paste of a session no longer lags for 10 seconds.
- New and deleted maps add and remove their tabs straight away.
- Your corporation and alliance update after you change corporation.
- Routes through Zarzakh and other special systems no longer fail.
- A short gateway timeout retries instead of logging you out.
- Closing a tab, or using a character in a corporation with no alliance, no longer causes errors.
- Thera and Turnur connections no longer fail when EVE-Scout is unreachable.
- The login page explains why character verification failed.
- Many PHP 8 crashes across maps, signatures, routes, killboard and rally.

### Changed

- **Map sharing and map type:** only the map owner can change them. Guests on a shared map can still edit settings, but they can't remove the owner's access.
- **Corp and alliance sharing flags** need the corp's map-share right (corp flag) or a Corporation or Super role (alliance flag).
- A map name like `@everyone` no longer pings in Discord.
- The account email field is gone.
- Existing EOL connections show as Aging.

---

Report problems at <https://github.com/goryn-clade/pathfinder/issues>.
