# Changelog

## Unreleased

---

### Phase 2 — PHP 8 Upgrade

#### pathfinder-containers

**Dockerfile (`pathfinder.Dockerfile`)**
- Build stage: `php:7.2.34-fpm-alpine3.12` → `php:8.2-fpm-alpine`
- Runtime stage: `trafex/alpine-nginx-php7` → `trafex/php-nginx:3.6.0` (PHP 8.3, pinned)
- Removed ZMQ extension (`zeromq-dev`, `pecl install zmq`) — not used anywhere in the codebase
- Removed `redis-5.3.7` version pin — unpinned `pecl install redis` works on PHP 8.2
- All PHP package references updated `php7-*` → `php83-*`
- Removed expired DST Root CA X3 workaround (no longer needed)
- Fixed `as` → `AS` in `FROM ... AS build` (Dockerfile best practice)
- Merged consecutive `RUN` layers in the runtime stage into one

**Static config**
- `static/supervisord.conf`: `php-fpm7 -F` → `php-fpm83 -F`
- `static/entrypoint.sh`: `/etc/php7/conf.d/` → `/etc/php83/conf.d/`
- `static/php/fpm-pool.conf`: Added `listen = 127.0.0.1:9000` — `trafex/php-nginx` defaults to a Unix socket but nginx expects TCP 9000

**Infrastructure**
- `compose.yml`, `compose.dev.yml`: Replaced abandoned `bianjp/mariadb-alpine:latest` (last updated 2019, exits immediately) with `mariadb:10.11` (official, LTS until 2028)
- `compose.yml`, `compose.dev.yml`: Replaced `redis:7-alpine` with `valkey/valkey:8-alpine` — Valkey is the Linux Foundation fork of Redis maintained after Redis 7.4's license change to RSALv2/SSPL; wire-compatible drop-in replacement; `redis-server` → `valkey-server`
- Added `compose.dev.yml`: standalone development compose file — no Traefik, `pf` exposed directly on port 80, uses local `build:` instead of registry image
- `compose.yml`: Fixed `depends_on` typo (`pfdb` → `pf-db`)
- `.env.example`: Updated `PATHFINDER_SOCKET_HOST` from `pathfinder-socket` → `pf-socket` to match service name
- `static/pathfinder/environment.ini`, `.env.example`: Removed SMTP configuration vars (email logging deprecated — see pathfinder changes below)

---

#### pathfinder (submodule) — v3-phase-2

**Dependencies (`composer.json`)**
- PHP constraint: `>=7.2` → `>=8.2`
- `bcosca/fatfree-core`: `3.7.*` → `3.8.*` — 3.7.x uses `$GLOBALS += [...]` which is fatal in PHP 8.2; 3.8.x fixes this
- `goryn-clade/pathfinder_esi`: `2.1.4` → `3.0.1`
- `cache/void-adapter`: `1.0.*` → `^1.1` — 1.0.x requires PHP ^5.6||^7.0
- `firebase/php-jwt`: kept at `^6`; added `config.audit.ignore` for advisory PKSA-y2cr-5h3j-g3ys (all v6.x affected, no v7 available)
- Removed `swiftmailer/swiftmailer` (abandoned; see email logging removal below)

**Email logging removed**
- `app/Lib/Logging/AbstractLog.php`: Removed `getHandlerParamsMail()` method and `case 'mail'` from `getHandlerParams()`
- `app/Lib/Monolog.php`: Removed `'mail'` entries from `FORMATTER` and `HANDLER` constants (`SwiftMailerHandler`, `MailFormatter`)

**PHP 8.2 compatibility — controllers**
- `app/Controller/AppController.php`: Initialize `tplCharacterId = null` in `beforeroute()` — only set by `MapController` when authenticated; undefined variable is fatal warning in PHP 8 when F3's error handler is active
- `app/Controller/AppController.php`: Initialize `SESSION.SSO.ERROR = null` in `beforeroute()` if not already set — accessing a null array offset (`$SESSION['SSO']['ERROR']`) is a warning in PHP 8
- `app/Controller/Controller.php`: In the error handler path (4xx/5xx), set safe defaults for `tplBodyClass`, `tplJsView`, `tplCharacterId` if not already defined — error paths skip `AppController::beforeroute()`

**PHP 8.2 compatibility — templates**
- `public/templates/view/login.html`: Added `<set registrationStatusButton="" />` and `<set registrationStatusTitle="" />` defaults before the conditional block — previously only set when registration was disabled, leaving variables undefined when enabled
- `public/templates/modules/lazy_image.html`: Added defaults for `size` (`@size ?? 160`), `srcWebp` (`""`), `src` (`@src ?? ""`), `alt` (`@alt ?? ""`) — these are only set inside conditional blocks; accessing undefined template variables compiles to undefined PHP variables which warn in PHP 8

---

#### pathfinder (submodule) — additional fixes

**PHP 8 compatibility — DB layer**
- `app/Lib/Db/Pool.php`: `pushError()` accessed `$this->errors[$alias]` before the key existed — changed to `!isset(...) || !is_array(...)` to avoid PHP 8 undefined array key warning

---

#### pathfinder_esi — v3.0.0 → v3.0.4

**Dependencies (`composer.json`)**
- PHP constraint: `>=7.1` → `>=8.2`
- `guzzlehttp/guzzle`: `^6.0` → `^7.0`
- `caseyamcl/guzzle_retry_middleware`: `^2.3` → `^2.9`
- `cache/void-adapter`: `1.0.*` → `^1.1`

**Guzzle 6 → 7 migration**
- `app/Lib/Middleware/GuzzleCacheMiddleware.php`:
  - `\GuzzleHttp\Psr7\parse_header()` → `\GuzzleHttp\Psr7\Header::parse()`
  - `\GuzzleHttp\Psr7\stream_for()` → `\GuzzleHttp\Psr7\Utils::streamFor()`
  - `\GuzzleHttp\Promise\inspect_all()` → `\GuzzleHttp\Promise\Utils::inspectAll()`
- `app/Lib/Middleware/Cache/CacheEntry.php`:
  - `\GuzzleHttp\Psr7\parse_header()` → `\GuzzleHttp\Psr7\Header::parse()` (2 occurrences)
  - `\GuzzleHttp\Psr7\stream_for()` → `\GuzzleHttp\Psr7\Utils::streamFor()`
- `app/Lib/Middleware/Cache/Strategy/PrivateCacheStrategy.php`:
  - `\GuzzleHttp\Psr7\parse_header()` → `\GuzzleHttp\Psr7\Header::parse()` (4 occurrences)
- `app/Lib/Stream/JsonStream.php`:
  - `\GuzzleHttp\json_decode()` → `\GuzzleHttp\Utils::jsonDecode()`
- `app/Lib/WebClient.php`:
  - `\GuzzleHttp\Psr7\stream_for(\GuzzleHttp\json_encode(...))` → `\GuzzleHttp\Psr7\Utils::streamFor(\GuzzleHttp\Utils::jsonEncode(...))`

**`caseyamcl/guzzle_retry_middleware` v2.13 breaking change**
- `app/Lib/Middleware/GuzzleRetryMiddleware.php`: `__construct()` was made `final` in v2.13; refactored to override `factory()` static method instead, injecting `on_retry_callback` at factory time; `retryCallback()` and `getLogMessage()` converted to static methods (`makeRetryCallback()`, `buildLogMessage()`)

**PSR-7 v2 return type incompatibility (v3.0.2 → v3.0.3)**
- `app/Lib/Stream/JsonStream.php`: `getContents()` returns decoded JSON (`mixed`), but PSR-7 v2 (shipped with Guzzle 7) declares `StreamInterface::getContents(): string` — PHP 8.3 treats this as a fatal incompatibility; added `#[\ReturnTypeWillChange]` to the class implementation
- `app/Lib/Stream/JsonStreamInterface.php`: **removed** the `getContents()` re-declaration entirely (v3.0.3) — `#[\ReturnTypeWillChange]` only suppresses the fatal on concrete class method implementations, not on interface method re-declarations; re-declaring `getContents()` without a return type in a child interface causes a PHP 8.3 fatal compile error regardless of the attribute; leaving the declaration absent lets `JsonStream::getContents()` satisfy PSR-7 v2 via the attribute alone

**PSR-7 v2 `getContents()` fatal — correct fix (v3.0.4)**
- `app/Lib/Stream/JsonStream.php`: `getContents()` override with `mixed` return is incompatible with `StreamInterface::getContents(): string` in PSR-7 v2; `#[\ReturnTypeWillChange]` only suppresses this for PHP's own built-in interfaces, not userland ones — replaced with two methods: `getContents(): string` (raw, PSR-7 compliant) and `decode(): mixed` (JSON-decoded result)
- `app/Lib/Stream/JsonStreamInterface.php`: added `decode(): mixed` declaration; removed explanatory comment from v3.0.3 that is now superseded
- `app/Lib/Middleware/GuzzleLogMiddleware.php`: updated `getErrorMessageFromResponseBody()` to call `->decode()` instead of `->getContents()` on `JsonStream`/`JsonStreamInterface` instances
- `app/Client/AbstractApi.php`: updated both `send()` and `sendBatch()` body content extraction to call `->decode()` when body `instanceof JsonStreamInterface`, otherwise `->getContents()`

**PHP 8 undefined array key warnings (v3.0.2)**
- `app/Lib/Middleware/GuzzleLogMiddleware.php`: `mergeOptions()` accessed `$options['log_on_status']` and `$options['log_off_status']` directly — added `?? []` null-coalescing to both

---

### Phase 1 — v3.0.0

### Infrastructure
- Added `docker-compose.override.yml` for local development: disables Traefik (assigned to `disabled` profile), exposes port 80 directly on localhost, builds `pf` from local Dockerfile instead of pulling image
- Fixed `docker-compose.yml`: `pf-redis` logging options was a string instead of a mapping; corrected to `max-size: "5m"` / `max-file: "3"`

### pathfinder-containers
- Bumped Composer `2.3.10` → `2.8.8` (PR #111)
- Locked `pecl install redis` to `redis-5.3.7` (last version supporting PHP 7.2) to fix build failure on modern PECL
- Fixed `pecl install zmq` — dropped deprecated `channel://pecl.php.net/` prefix
- Added `pecl channel-update pecl.php.net` before PECL installs to suppress protocol warning
- Changed `composer install` → `composer update --no-dev --optimize-autoloader` in `pathfinder.Dockerfile` to resolve dependencies from constraints rather than stale lock file

### pathfinder (submodule)
- Merged PR #206: Update links (pathfinder.ini, footer, splash, SSO templates)
- Merged PR #224: Wormhole data fix — added missing F216 (56543), J244 (73748), J377 (73749); corrected small hole duration from 16h to 4.5h; corrected X702 and F329 mass values
- Merged PR #242: Add specific wormhole class from visual identification (C1–C5 options)
- Merged PR #226: Fix Thera routes — EVE Scout API now returns array instead of object; also adds jump mass classification and EOL detection
- Merged PR #211: Bump `ip` `2.0.0` → `2.0.1` (security)
- Merged PR #196: Bump `async` `2.6.3` → `2.6.4`
- Merged PR #195: Bump `fast-xml-parser` and `is-svg`
- Merged PR #202: Bump `monolog/monolog` `2.3.5` → `2.9.2`
- Pinned `firebase/php-jwt` to `~6.3.0` (6.4+ requires PHP ≥7.4; was incorrectly bumped to 6.10.0)
- Kept `cache/redis-adapter` at `1.1.*` (1.2.0 requires PHP ≥7.4)
- Skipped PR #209 (thera route fix superseded by PR #226)
- Skipped PR #201 (cache/redis-adapter 1.2 requires PHP ≥7.4)
- Skipped PR #198 (firebase/php-jwt 6.10 requires PHP ≥7.4)
- Extracted shared EVE Scout logic into `AbstractEveScoutController`; `SystemThera` now extends it
- Added `SystemTurnur` controller — same EVE Scout API endpoint (`/v2/public/signatures`), filtered to Turnur (`30002086`) instead of Thera (`31000005`)
- Added `global_turnur.js` frontend module mirroring `global_thera.js`; registered in `module_map.js` at position 4
- Removed `node-sass` — incompatible with Node 22; `gulp-sass` was already configured to use Dart `sass` on line 19 of `gulpfile.js`, the `sass.compiler` override on line 45 was the only thing pulling it in
- Replaced `uglify-es` (abandoned) with `gulp-terser` for JS minification; Node 22 compatible
- Updated `engines.node` from `12.x` → `>=18` in `package.json`

### pathfinder_websocket (submodule)
- Merged PR #2: Increase JSON buffer size by 50% to prevent `Buffer size exceeded` errors on large maps (70–80+ systems)