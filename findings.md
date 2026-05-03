# Security Review Findings — pathfinder-containers

Scope: PHP 8.2/8.3 Fat-Free Framework app + MariaDB + Redis + PHP/Ratchet WebSocket, fronted by nginx, integrating with EVE Online ESI/SSO OAuth.

Severity scale: **Critical** → **High** → **Medium** → **Low** → **Info**

---

## Fixed during review

| # | Finding | Fix |
|---|---------|-----|
| F-01 | Container image: php83 8.3.9-r0 had CVE-2024-11236 (Critical RCE) and 5 further Critical CVEs in libcrypto3, libexpat, libxml2, aom-libs, sqlite-libs; ~130 High CVEs in libssl3, libpng, libcurl, musl, python3, and others | Added `apk upgrade --no-cache` to runtime stage RUN in `pathfinder.Dockerfile`; also bumped build stage from `php:8.2-fpm-alpine` to `php:8.3-fpm-alpine` |
| F-02 | Session cookies missing `HttpOnly` flag — accessible to JavaScript | Added `session.cookie_httponly = 1` to `static/php/php.ini` |
| F-03 | Session cookies missing `SameSite` attribute | Added `session.cookie_samesite = Lax` to `static/php/php.ini` |
| F-12 | `X-Powered-By: Fat-Free Framework` header disclosed on all responses | Added `fastcgi_hide_header X-Powered-By` to `static/nginx/site.conf` |
| F-13a | Missing `Referrer-Policy` and `Permissions-Policy` headers | Added both to `static/nginx/site.conf` |
| F-13b | `server_tokens on` disclosed nginx version in error responses | Set `server_tokens off` in `static/nginx/nginx.conf` |

---

## Open findings

### High

#### F-04 · Websocket dependency CVE: symfony/http-foundation PATH_INFO auth bypass
- **File:** `websocket/composer.lock`
- **CVE:** CVE-2025-64500 — incorrect PATH_INFO parsing can allow limited authorization bypass
- **Severity:** High (CVSS from advisory)
- **Affected version:** installed 5.4.x; fix in 5.4.50 / 6.4.29 / 7.3.7
- **DB impact:** Low directly; depends on whether the websocket server uses symfony/http-foundation for routing decisions. Worth bumping regardless.
- **Remediation:** `cd websocket && composer update symfony/http-foundation`

#### F-05 · Websocket dependency CVEs: guzzlehttp/psr7 header validation
- **File:** `websocket/composer.lock`
- **CVEs:** CVE-2023-29197 (header injection, Medium), CVE-2022-24775 (header parsing, Medium)
- **Severity:** Medium–High
- **Remediation:** `cd websocket && composer update guzzlehttp/psr7`

---

### Medium

#### F-06 · Session fixation — no session_regenerate_id() on SSO login
- **File:** `pathfinder/app/Controller/Api/User.php` — `loginByCharacter()`, ~line 73
- **Detail:** After a character authenticates via CCP SSO and `loginByCharacter()` sets session data, the session ID is never rotated. An attacker who can pre-plant a known session cookie (e.g. via network position or a prior XSS) can retain access after the victim logs in.
- **Remediation:** Call `session_regenerate_id(true)` inside `loginByCharacter()` after setting session keys, before returning.

#### F-07 · WebSocket token passed in plaintext WebSocket payload; no server-side binding
- **Files:** `pathfinder/app/Controller/Api/Map.php` ~line 482; `websocket/app/Component/MapUpdate.php` ~line 287
- **Detail:** `/api/Map/getAccessData` returns a `bin2hex(random_bytes(16))` token to the client over HTTP. The client then sends it in the WebSocket subscribe payload. The server validates by plain string comparison against an in-memory store. Tokens expire in 30 s and are one-time-use, which limits the window, but: (a) the token is visible in HTTP responses to any script that calls the API endpoint; (b) there is no binding between the token and the WebSocket connection's IP/origin.
- **Remediation:** Consider signing the token with an HMAC keyed on a server-side secret and binding it to the character's session ID, or validating the session cookie directly on the WebSocket handshake.

#### F-08 · Error pages leak stack trace and debug level at DEBUG=3
- **Observed:** `GET /api/User/getEveServerStatus` → HTTP 404 response body includes `Debug: 3` and an F3 stack trace with full file paths.
- **Detail:** F3 escalates all NOTICEs to 500s and renders full traces when `DEBUG ≥ 1`. `.env` sets `PF_DEBUG=3` for development; `.env.example` correctly defaults to `PF_DEBUG=0`. Risk is limited to deployments that copy `.env` directly without adjusting for production.
- **Remediation:** Document clearly that `PF_DEBUG=0` is required before any internet-facing deployment. Consider making the entrypoint refuse to start with `DEBUG≥1` if `APP_ENV=production`.

#### F-09 · /setup has no PHP-level authentication
- **File:** `pathfinder/app/Controller/Setup.php` — `init()`
- **Detail:** `init()` dispatches `createDB`, `bootstrapDB`, `importTable`, `exportTable`, `clearFiles`, `flushRedisDb`, and `invalidateCookies` with no PHP session or character check. The sole gate is nginx HTTP Basic Auth (`/etc/nginx/.setup_pass`). Any misconfiguration of nginx (missing `auth_basic` on the location, a second vhost, a direct PHP-FPM connection) exposes full DB and cache control to an unauthenticated caller.
- **Remediation:** Add an application-level shared-secret check inside `Setup::init()` — compare a request header or GET token against `$APP_PASSWORD` from env — as a defense-in-depth layer behind nginx auth.

---

### Low

#### F-10 · Three Api controllers extend Controller not AccessController — no 401 on unauthenticated calls
- **Files:** `pathfinder/app/Controller/Api/User.php`, `Api/Killboard.php`, `Api/GitHub.php`
- **Detail:** All three extend `Controller\Controller` directly, bypassing the `AccessController::beforeroute()` login check. `Killboard` and `GitHub` are intentionally public (proxy/release data). `User.php` contains `saveAccount`, `deleteAccount`, and `openIngameWindow` which all call `$this->getCharacter()` internally and silently no-op without a session — but they return HTTP 200 with empty data rather than a 401. A future method added to `User.php` without an internal `getCharacter()` guard would be silently unauthenticated.
- **Remediation:** Move `saveAccount`, `deleteAccount`, `openIngameWindow` to a subclass that extends `AccessController`, keeping only genuinely public methods (`getCookieCharacter`, `getCaptcha`, `logout`) on the unguarded class.

#### F-11 · session.cookie_secure not set
- **File:** `static/php/php.ini`
- **Detail:** Currently set to `0` because the dev stack runs on HTTP. Any production or staging deployment served over HTTPS should have this as `1` to prevent cookie transmission over plain HTTP.
- **Remediation:** Set `session.cookie_secure = 1` in production; gate on environment or document the requirement clearly.

#### F-12 · X-Powered-By: Fat-Free Framework header disclosed on all responses
- **Detail:** Observed on every curl probe response. Discloses framework name and version range to attackers.
- **Remediation:** Add `header_remove('X-Powered-By')` in the main bootstrap, or set `expose_php = Off` + suppress the F3 header in nginx with `fastcgi_hide_header X-Powered-By`.

#### F-13 · No nginx security headers
- **File:** `static/nginx/site.conf`
- **Missing headers:** `X-Frame-Options`, `Referrer-Policy`, `Content-Security-Policy` (even a minimal one), `Permissions-Policy`
- **Remediation:** Add to the nginx server block.

---

### Info

#### F-17 · No request-rate limiting on API endpoints
- **File:** `static/nginx/nginx.conf`, `pathfinder/app/routes.ini`
- **Detail:** The `0, 512` route params in routes.ini are F3's `ttl, kbps` (cache TTL and bandwidth throttle per response), not a request-rate limit. There is no `limit_req_zone` in nginx and no application-level request counter. A logged-in user can hammer all `/api/*` endpoints without restriction.
- **Remediation:** Add `limit_req_zone $binary_remote_addr zone=api:10m rate=30r/s` in nginx.conf and `limit_req zone=api burst=60 nodelay` on the API locations. Alternatively gate at the F3 level with a Redis-backed counter.

#### F-14 · Wildcard API dispatch has no controller/method allow-list
- **File:** `pathfinder/app/routes.ini` lines 17–19
- **Detail:** `/api/@controller/@action` will attempt to instantiate any class in the `Controller\Api\*` namespace and call any public method. Auth enforcement is entirely by inheritance convention (`extends AccessController`). No explicit allow-list or reflection guard (e.g. must not be a magic method, must not start with `_`).
- **Remediation:** Low urgency while the class list is small and controlled. Consider adding a bootstrap-time check that rejects calls to methods declared on `Controller` or `AccessController` themselves (prevents calling internal helpers through the wildcard).

#### F-15 · No package-lock.json in websocket/ — npm audit not possible
- **Detail:** `websocket/` contains only `composer.json`/`composer.lock` (PHP/Ratchet). The SECURITY-REVIEW-PLAN.md and plan scope references "Node WS" — corrected; the socket server is PHP. npm audit is not applicable. Composer audit is covered (see F-04, F-05).

#### F-16 · Admin role check is role-name-based, not ID-based
- **File:** `pathfinder/app/Controller/Admin.php` — `getAdminCharacter()` checks `$character->roleId->name` against `['SUPER', 'CORPORATION']`
- **Detail:** Not a vulnerability — just a design note. There is no `PF_SUPER_ADMIN_ID` environment variable. Access depends entirely on the role assigned in the database. Ensure the role assignment path itself is protected (only an existing SUPER can grant SUPER).

#### F-18 · Redis has no authentication (no requirepass)
- **File:** `config/redis/redis.conf`
- **Detail:** The Valkey/Redis configuration has no `requirepass` directive. Redis is not port-mapped to the host (no `ports:` in compose files), so it is only reachable by containers on the same Docker bridge network. Any container added to that network — including a compromised `pf` or `pf-socket` container — has full unauthenticated Redis access. Redis holds session data; an attacker with network access could enumerate or poison sessions.
- **Remediation:** Add `requirepass <strong-secret>` to `config/redis/redis.conf` and pass the password to the app via `REDIS_PASSWORD` env var (update app config to use it).

#### F-20 · Missing HSTS header on live HTTPS deployment
- **Observed on:** `https://pfdev.sa.muel.nz` (live Traefik/nginx stack)
- **Detail:** The site is HTTPS-only (HTTP issues a `308` redirect), but `Strict-Transport-Security` is absent from responses. Without HSTS, a network attacker can SSL-strip a first connection before the redirect fires, or after a browser cache flush. Also note: the live instance is running from the published `ghcr.io` image and has not yet received the nginx security-header fixes (F-12, F-13a, F-13b) or the `session.cookie_secure = 1` change (F-11) that were applied to the dev config.
- **Remediation:** Add to the nginx `server` block (or as a Traefik middleware):
  ```
  add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
  ```
  Start with a short `max-age` (e.g. 3600) to test, then ramp to 31536000. Also deploy the pending nginx/php.ini fixes from the dev branch.

#### F-19 · App connects to MariaDB as root — no least-privilege DB user
- **Files:** `.env.example` (`MYSQL_USER=root`), `compose.dev.yml` / `compose.yml` (`MYSQL_ROOT_PASSWORD: $MYSQL_PASSWORD`)
- **Detail:** The app authenticates to MariaDB as the `root` user. There is no setup step that creates a restricted application user. If the PHP app is compromised (e.g. via a future RCE), the attacker inherits full MariaDB root privileges: can read all databases (including `eve_universe`, `eve_lifeblood_min`), create/drop tables, and write arbitrary data.
- **Remediation:** Create a dedicated `pathfinder` DB user with `GRANT SELECT, INSERT, UPDATE, DELETE` on `pathfinder.*` and `eve_universe.*` only. Add `MYSQL_APP_USER` / `MYSQL_APP_PASSWORD` env vars and update the app config.

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
| trivy fs | F-04, F-05 (websocket composer.lock CVEs) |
| composer audit (main app) | Clean |
| composer audit (websocket) | F-04, F-05 |
| psalm / phpstan / checkov | Not installed |
