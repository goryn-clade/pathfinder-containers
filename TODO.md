# TODO

## Active plans

- [PLAN_SSO_AUDIT.md](PLAN_SSO_AUDIT.md) — EVE SSO / OAuth audit, findings & remediation
- [PLAN_TYPE_SAFETY.md](PLAN_TYPE_SAFETY.md) — static analysis restoration + baseline ratchet

## Whitelist => Allowlist

## Readme updates 
- Review Claude changes to Readmes

## Validate eve-universe database 
Check systems like Zarzakh are in the database and in the SDE seed file. it seems database at pfdev is a good example.

## Review github actions pipeline that builds docker images


## Known bugs to fix

## deferred fixes

- **JWKS cache integrity (Redis compromise → SSO bypass)**: A1 (`01df165b`) caches the CCP JWKS in Redis for 1h to avoid a network round-trip on every login. Trade-off: an attacker with Redis write access can swap the cached JWKS for their own public key and forge JWTs that validate (aud/iss claims are attacker-controlled too). Existing mitigations: Redis password auth (`REDIS_PASSWORD`), network isolation, TLS to CCP — but the cache extends the tampering window from per-request to ≤1h. Hardening option: HMAC the cached blob server-side (key from `.env`) and verify on retrieval; on HMAC failure treat as corrupt and bust cache. Out of v3.0 scope.

- **session_regenerate_id placement (low-priority hardening)** (`e5360372`):
  `loginByCharacter()` calls `session_regenerate_id(true)` *after* the `$f3->set('SESSION.*', …)`
  writes at `app/Controller/Api/User.php:121`. The 403s observed briefly after the May 3 deploy
  were actually caused by the F-10 auth guard on `Api\User::beforeroute()` being inherited by
  `Ccp\Sso` (which extends `Api\User`), blocking the pre-authentication SSO callback — fixed
  ~7 hours later in `eab1de7d`. The regenerate-id race itself is practically unreachable under
  PHP-FPM (shutdown order: `session_write_close` fires before the response leaves the process).
  Cleanup: move `session_regenerate_id(true)` to *before* the `$f3->set()` writes so the ordering
  no longer relies on PHP-FPM's shutdown sequence.

- **f3-cortex: adopt tagged release**: `ikkez/f3-cortex` is pinned to `dev-master#47d2596` (2025-07-08) because three PHP 8.2 type-hint fixes landed after the `v1.7.8` tag. Once a `v1.7.9`+ tag is published, switch `composer.json` to `"1.7.*"` and drop the commit hash.

- **assess upgrade to php 8.4**: `trafex/php-nginx:3.8.0+` ships PHP 8.4. Currently pinned to 3.6.0 (PHP 8.3) because all higher tags jumped straight to 8.4+. Alpine package CVEs are mitigated by `apk upgrade --no-cache` in the Dockerfile. When the app is ready for PHP 8.4 compatibility work, bump the base image tag and update all `php83-*` package installs to `php84-*`.

