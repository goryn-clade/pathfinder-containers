# Security Review Findings — pathfinder-containers

Scope: PHP 8.2/8.3 Fat-Free Framework app + MariaDB + Redis + PHP/Ratchet WebSocket, fronted by nginx, integrating with EVE Online ESI/SSO OAuth.

Severity scale: **Critical** → **High** → **Medium** → **Low** → **Info**

---

## Fixed

| # | Severity | Finding | Fix |
|---|----------|---------|-----|
| F-01 | Critical | Container image: php83 8.3.9-r0 had CVE-2024-11236 (RCE) and 5 further Critical CVEs in libcrypto3, libexpat, libxml2, aom-libs, sqlite-libs; ~130 High CVEs | Added `apk upgrade --no-cache` to runtime stage in `pathfinder.Dockerfile`; bumped build stage to `php:8.3-fpm-alpine` |
| F-02 | Low | Session cookies missing `HttpOnly` flag | Added `session.cookie_httponly = 1` to `static/php/php.ini` |
| F-03 | Low | Session cookies missing `SameSite` attribute | Added `session.cookie_samesite = Lax` to `static/php/php.ini` |
| F-04 | High | CVE-2025-64500 — symfony/http-foundation PATH_INFO auth bypass in websocket | Bumped to 5.4.50 via `composer update` in `websocket/` |
| F-05 | Medium | CVE-2023-29197 / CVE-2022-24775 — guzzlehttp/psr7 header injection / parsing in websocket | Bumped to 1.9.1 via `composer update` in `websocket/` |
| F-06 | Medium | Session fixation — no `session_regenerate_id()` on SSO login | Added `session_regenerate_id(true)` in `loginByCharacter()` in `pathfinder/app/Controller/Api/User.php` |
| F-09 | Medium | `/setup` had no PHP-level authentication — sole gate was nginx Basic Auth | Added `APP_PASSWORD` token check before any action dispatch in `Setup::init()` |
| F-12 | Low | `X-Powered-By: Fat-Free Framework` header disclosed on all responses | Added `fastcgi_hide_header X-Powered-By` to `static/nginx/site.conf` |
| F-13a | Low | Missing `Referrer-Policy` and `Permissions-Policy` headers | Added both to `static/nginx/site.conf` |
| F-13b | Low | `server_tokens on` disclosed nginx version in error responses | Set `server_tokens off` in `static/nginx/nginx.conf` |
| F-18 | Low | Redis had no authentication (`requirepass` not set) | Added optional `REDIS_PASSWORD` env var; entrypoint builds auth DSN conditionally; `--requirepass` passed to pf-redis in compose files |
| F-20 | Low | Missing HSTS header on HTTPS deployment | Added `Strict-Transport-Security: max-age=31536000; includeSubDomains` to `static/nginx/site.conf` |
| F-08 | Low | Error pages leak stack trace at DEBUG≥1 | Added startup warning in `entrypoint.sh` when `APP_ENV=production` and `PF_DEBUG≥1`; added `APP_ENV` to `.env.example` with production note |
| F-10 | Low | Three Api controllers extend Controller — no 401 on unauthenticated calls to `User.php` | Added `beforeroute()` override to `User.php` with public-method allow-list; non-listed methods now call `logoutCharacter()` and return 401 |
| F-11 | Low | `session.cookie_secure` not set for HTTPS deployments | Added `SESSION_COOKIE_SECURE` env var (default `0`) to `.env.example`; referenced `${SESSION_COOKIE_SECURE}` in `static/php/php.ini` |
| F-17 | Low | No request-rate limiting on API endpoints | Added `limit_req_zone` to `static/nginx/nginx.conf`; added `/api/` location with `limit_req zone=api burst=60 nodelay` to `static/nginx/site.conf` |

---

## Open findings

### Medium

#### F-07 · WebSocket token has no server-side session binding *(priority 5)*
- **Files:** `pathfinder/app/Controller/Api/Map.php` ~line 482; `websocket/app/Component/MapUpdate.php` ~line 287
- **Detail:** `/api/Map/getAccessData` returns a `bin2hex(random_bytes(16))` token to the client. The client sends it in the WebSocket subscribe payload; the server validates by plain string comparison against an in-memory store. Tokens expire in 30 s and are one-time-use, which limits the window, but a token leaked in that window (logs, browser history, unencrypted dev channel) is replayable from any IP with no session binding.
- **Remediation:** Two-part fix. (1) HMAC-sign the token keyed on a shared secret (`WS_TOKEN_SECRET` env var read by both PHP and the WS server). (2) Bind token issuance to the character's PHP session ID so the WS server can validate it. Requires coordinated changes across `Map.php`, `MapUpdate.php`, and the JS subscribe payload.
- **Effort:** ~3–4 hr cross-repo. **Risk:** High — incorrect implementation stops live map updates entirely; needs end-to-end test with multiple characters before shipping.

#### F-19 · App connects to MariaDB as root — no least-privilege DB user *(priority 6)*
- **Files:** `.env.example` (`MYSQL_USER=root`), `compose.dev.yml` / `compose.yml` (`MYSQL_ROOT_PASSWORD: $MYSQL_PASSWORD`)
- **Detail:** The app authenticates to MariaDB as `root`. If the PHP app is compromised, the attacker inherits full MariaDB root privileges: can read all databases (including `eve_universe`, `eve_lifeblood_min`), create/drop tables, and write arbitrary data.
- **Remediation:** Introduce two credential pairs: `MYSQL_USER`/`MYSQL_PASSWORD` (restricted `pf_app` user, used by the app at runtime) and `MYSQL_SETUP_USER`/`MYSQL_SETUP_PASSWORD` (root, used only by `/setup` for DDL). SQL to run once after initial schema bootstrap:
  ```sql
  CREATE USER 'pf_app'@'%' IDENTIFIED BY '<strong-random-secret>';
  GRANT SELECT, INSERT, UPDATE, DELETE ON pathfinder.* TO 'pf_app'@'%';
  GRANT SELECT ON eve_universe.* TO 'pf_app'@'%';
  GRANT SELECT ON eve_lifeblood_min.* TO 'pf_app'@'%';
  FLUSH PRIVILEGES;
  ```
  Before implementing, audit all code paths that issue `CREATE`/`DROP`/`ALTER` (cron jobs, map import, any `pathfinder_esi` calls) to ensure they're all funnelled through `/setup` and not the runtime connection.
- **Effort:** ~1 day (audit + DB user creation + entrypoint + setup changes + migration docs). **Risk:** High — wrong grants cause runtime crashes; needs fresh-install AND upgrade-from-v2 test.

---

### Info

#### F-14 · Wildcard API dispatch has no controller/method allow-list *(priority 7 — optional)*
- **File:** `pathfinder/app/routes.ini` lines 17–19
- **Detail:** `/api/@controller/@action` will attempt to instantiate any class in `Controller\Api\*` and call any public method. Auth is enforced entirely by inheritance convention (`extends AccessController`). No explicit allow-list or reflection guard against magic methods or internal helpers.
- **Remediation:** Low urgency while the class list is small and controlled. If desired: add a bootstrap-time routing hook that rejects calls where `ReflectionMethod::getDeclaringClass()` returns `Controller` or `AccessController` (i.e. the method wasn't overridden in the concrete class). Test the full API surface before shipping.

#### F-15 · websocket/ is PHP/Ratchet — npm audit not applicable
- **Detail:** `websocket/` contains only `composer.json`/`composer.lock`. The socket server is PHP, not Node. npm audit is N/A; composer audit is covered (F-04, F-05 now fixed).

#### F-16 · Admin role check is role-name-based, not ID-based
- **File:** `pathfinder/app/Controller/Admin.php` — `getAdminCharacter()` checks `$character->roleId->name` against `['SUPER', 'CORPORATION']`
- **Detail:** Design note, not a vulnerability. SUPER role is assigned only via `PATHFINDER.ROLES.CHARACTER` config (hardcoded character IDs) — no runtime API can grant it. Ensure only an existing SUPER can grant SUPER via the DB.

---

## Container scan summary (trivy — HIGH/CRITICAL only)

All resolved by `apk upgrade --no-cache` in `pathfinder.Dockerfile` (F-01). Key CVEs:

| CVE | Package | Severity | Fix version |
|-----|---------|----------|-------------|
| CVE-2024-11236 | php83 8.3.9-r0 | Critical | 8.3.14-r0 |
| CVE-2025-15467 | libcrypto3/libssl3 3.3.1-r0 | Critical | 3.3.6-r0 |
| CVE-2026-31789 | libcrypto3/libssl3 3.3.1-r0 | Critical | 3.3.7-r0 |
| CVE-2024-45491/45492 | libexpat 2.6.2-r0 | Critical | 2.6.3-r0 |
| CVE-2024-56171 | libxml2 2.12.7-r0 | Critical | 2.12.7-r1 |
| CVE-2025-6965 | sqlite-libs 3.45.3-r1 | Critical | 3.45.3-r3 |
| CVE-2024-5171 | aom-libs 3.9.0-r0 | Critical | 3.9.1-r0 |

---

## Static analysis tools run

| Tool | Result |
|------|--------|
| semgrep (p/php, p/owasp-top-ten, p/security-audit) | 0 findings (31 rules — login required for full registry) |
| hadolint | 6 findings on pathfinder.Dockerfile: DL3002 (USER root), DL3018 ×2 (unpinned apk), DL4006 (pipefail), SC2086, SC2016 |
| trivy image | See container scan summary above |
| trivy fs | F-04, F-05 (websocket composer.lock CVEs — now fixed) |
| composer audit (main app) | Clean |
| composer audit (websocket) | Clean (F-04, F-05 fixed) |
| psalm / phpstan / checkov | Not installed |
