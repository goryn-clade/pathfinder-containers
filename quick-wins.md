# Quick Wins — pathfinder-containers security review

Changes deliverable in under one day, ordered by impact. All are config-only or one-liner code changes — no schema migration, no API breakage.

---

## Already done (during review)

| # | Change | File |
|---|--------|------|
| F-01 | `apk upgrade --no-cache` in Dockerfile — patched 6 Critical CVEs | `pathfinder.Dockerfile` |
| F-02 | `session.cookie_httponly = 1` | `static/php/php.ini` |
| F-03 | `session.cookie_samesite = Lax` | `static/php/php.ini` |
| F-12 | `fastcgi_hide_header X-Powered-By` | `static/nginx/site.conf` |
| F-13a | `Referrer-Policy` + `Permissions-Policy` headers | `static/nginx/site.conf` |
| F-13b | `server_tokens off` | `static/nginx/nginx.conf` |

---

## Remaining quick wins

### 1 · Bump websocket composer dependencies (F-04, F-05) — ~5 min

```bash
cd pathfinder-containers/websocket
composer update symfony/http-foundation guzzlehttp/psr7
git add composer.lock && git commit -m "bump symfony/http-foundation and guzzlehttp/psr7 (F-04, F-05)"
```

Fixes: CVE-2025-64500 (High), CVE-2023-29197 (Medium), CVE-2022-24775 (Medium).

---

### 2 · Fix session fixation on SSO login (F-06) — ~2 min

**File:** `pathfinder/app/Controller/Api/User.php`, inside `loginByCharacter()` (~line 73), after session keys are written and before `return`.

```php
session_regenerate_id(true);
```

Prevents a pre-planted session cookie from persisting after login.

---

### 3 · Add HSTS header + deploy pending nginx/php.ini fixes (F-20, F-11) — ~5 min

Add to the nginx `server` block in `static/nginx/site.conf`:
```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

Also set `session.cookie_secure = 1` in `static/php/php.ini` for the production stack (the dev stack on plain HTTP can keep `0`, or gate it on an env var).

Then rebuild and deploy to pfdev — this will also deliver the already-committed F-12, F-13a, F-13b fixes (X-Powered-By, Referrer-Policy, server_tokens) that are in the dev config but not yet live.

---

### 4 · Add Redis authentication (F-18)

Add to `config/redis/redis.conf`:
```
requirepass <strong-random-secret>
```

Add to `.env` / `.env.example`:
```
REDIS_PASSWORD=""
```

Update `pathfinder/app/config/pathfinder.ini` (or wherever the Redis DSN is constructed) to include the password. Valkey supports `redis://:password@host:port`.

---

### 5 · Create a least-privilege MariaDB app user (F-19) — ~15 min

Add a one-time setup step (or document in README) to create a restricted DB user instead of using root:

```sql
CREATE USER 'pf_app'@'%' IDENTIFIED BY '<strong-random-secret>';
GRANT SELECT, INSERT, UPDATE, DELETE ON pathfinder.* TO 'pf_app'@'%';
GRANT SELECT ON eve_universe.* TO 'pf_app'@'%';
GRANT SELECT ON eve_lifeblood_min.* TO 'pf_app'@'%';
FLUSH PRIVILEGES;
```

Update `.env.example`:
```
MYSQL_USER="pf_app"
MYSQL_APP_PASSWORD=""
```

The `/setup` wizard runs DDL (CREATE TABLE etc.) so it must still use root — gate that on `MYSQL_ROOT_PASSWORD` separately.

---

### 5 · Add PHP-level auth to /setup (F-09) — ~15 min

**File:** `pathfinder/app/Controller/Setup.php`, top of `init()`:

```php
$f3 = \Base::instance();
$expected = $f3->get('ENV.APP_PASSWORD');
$provided = $f3->get('GET.token') ?: $f3->get('SERVER.HTTP_X_SETUP_TOKEN');
if(!$expected || !hash_equals($expected, $provided)){
    $f3->error(401, 'Setup requires a valid token');
    return;
}
```

This adds defense-in-depth behind nginx Basic Auth. Callers pass `?token=<APP_PASSWORD>` or an `X-Setup-Token` header.

---

### 6 · Document production requirements (F-08, F-11) — ~10 min

Add a `docs/production-checklist.md` (or a section in README) stating:

- `PF_DEBUG=0` — required; `DEBUG≥1` leaks stack traces with file paths
- `session.cookie_secure = 1` in `static/php/php.ini` when TLS is terminating at nginx
- Remove or disable the `/setup` route after initial bootstrap (comment it out in `pathfinder/app/routes.ini`)

---

## Not-quick items (tracked in findings.md)

| # | Finding | Why deferred |
|---|---------|--------------|
| F-07 | WebSocket token binding | Requires HMAC signing + session-cookie validation on WS handshake — multi-file change |
| F-10 | User.php → extend AccessController | Needs class refactor; low risk today |
| F-17 | nginx rate limiting | Needs load testing to pick safe burst/rate values |
