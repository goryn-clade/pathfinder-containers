# Changelog

## Unreleased

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
