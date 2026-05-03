# Changelog

## Unreleased

---

### Security hardening

A full security review was performed across the container stack, covering CVE scanning, static analysis, PHP session config, nginx headers, and application auth flow. All findings are tracked in `findings.md`.

#### pathfinder-containers

**Container image / OS packages**
- `pathfinder.Dockerfile`: Added `apk upgrade --no-cache` to runtime stage; bumped build stage to `php:8.3-fpm-alpine` — resolves CVE-2024-11236 (PHP RCE, Critical) and ~135 further Critical/High CVEs in libcrypto3, libexpat, libxml2, aom-libs, sqlite-libs

**nginx hardening**
- `static/nginx/nginx.conf`: `server_tokens off` — suppresses nginx version in error responses; added `limit_req_zone $binary_remote_addr zone=api:10m rate=30r/s` for API rate limiting
- `static/nginx/site.conf`: Added `/api/` location with `limit_req zone=api burst=60 nodelay`; added security headers: `Strict-Transport-Security`, `Referrer-Policy`, `Permissions-Policy`; `fastcgi_hide_header X-Powered-By` — suppresses Fat-Free Framework version disclosure

**PHP session cookie flags**
- `static/php/php.ini`: `session.cookie_httponly = 1`, `session.cookie_samesite = Lax`, `session.cookie_secure = ${SESSION_COOKIE_SECURE}`
- `.env.example`: Added `SESSION_COOKIE_SECURE` (default `0`; set to `1` on HTTPS deployments) and `APP_ENV` (set to `production` to activate startup guards)

**Redis authentication**
- `static/entrypoint.sh`: Builds `REDIS_CACHE_DSN` and `REDIS_SESSION_PATH` conditionally — includes `auth` parameter only when `REDIS_PASSWORD` is set, avoiding empty-password AUTH errors
- `static/php/php.ini`: Session save path now references `${REDIS_SESSION_PATH}` so Redis auth is included
- `static/pathfinder/config.ini`: Cache DSN now references `${REDIS_CACHE_DSN}`
- `compose.yml`, `compose.dev.yml`: `--requirepass "${REDIS_PASSWORD}"` passed to `pf-redis`; empty string disables auth in Valkey
- `.env.example`: Added `REDIS_PASSWORD` with guidance

**Production debug guard**
- `static/entrypoint.sh`: Warns to stderr when `APP_ENV=production` and `PF_DEBUG≥1` — prevents accidental stack-trace exposure on internet-facing deployments

#### pathfinder (submodule)

**Session fixation**
- `app/Controller/Api/User.php`: `session_regenerate_id(true)` called in `loginByCharacter()` after session data is written — prevents session fixation via pre-planted session cookie

**`/setup` defence-in-depth**
- `app/Controller/Setup.php`: Added `APP_PASSWORD` token check at the start of `init()` for all requests with an `action` param — second gate behind nginx Basic Auth; protects against Direct Request attacks and misconfigured reverse proxies

**User controller auth guard**
- `app/Controller/Api/User.php`: Added `beforeroute()` override with `PUBLIC_METHODS` allow-list (`getCookieCharacter`, `getCaptcha`, `logout`); all other methods now call `logoutCharacter()` and return 401 when no session exists — previously silently returned HTTP 200 with empty data

#### websocket (submodule)

**Dependency CVEs**
- `composer.lock`: Bumped `symfony/http-foundation` 5.3.6 → 5.4.50 — resolves CVE-2025-64500 (PATH_INFO auth bypass, High)
- `composer.lock`: Bumped `guzzlehttp/psr7` 1.8.2 → 1.9.1 — resolves CVE-2023-29197 (header injection, Medium) and CVE-2022-24775 (header parsing, Medium)

---

### Fix: 10s lag on initial signature paste (issue #53)

- `app/Lib/Logging/Handler/SocketHandler.php`: Connection and write timeouts reduced from Monolog's 10s default to 2s. Write failures now caught — on `RuntimeException` the socket is immediately marked unavailable in the F3 cache so subsequent log writes within the same request skip the socket handler rather than each timing out independently.

---

### config/ vs static/ layout cleanup

- Drop redundant `config/pathfinder/config.ini` (byte-identical to static template) and its dev-only compose mount
- Drop `config/pathfinder/pathfinder.ini` dev-only override; dev now uses the same envsubst-rendered template as test and prod
- Move `static/redis/redis.conf` → `config/redis/redis.conf` and apply it consistently across all three compose files; prod no longer uses an inline `--appendonly yes` flag

---

### Map deletion tab persistence fix

- **JS** (`util.js`): `deleteCurrentMapData` now stamps a 5-second TTL in a `recentlyDeletedMapIds` guard. `updateCurrentMapData` and `setCurrentMapData` skip any map id in that set, preventing in-flight `mapUpdate` WebSocket messages or the periodic poll from resurrecting the just-deleted map before the tab removal completes.

---

### R2Z2 killboard poller hardening

- **JS**: all `fetch()` calls in the poll loop now use `AbortController` with explicit timeouts (20s for r2z2, 10s for sequence), preventing opaque `NetworkError` on hung connections
- **JS**: `AbortError` from page unload or `stopPoller()` is silently discarded rather than logged as an error
- **JS**: error logging now includes `seqId` and `e.name` for easier diagnosis
- **JS**: exponential backoff on consecutive poll errors (10s → 20s → 40s → 60s max); after 3 consecutive failures the sequence is resynced from `/api/Killboard/sequence`
- **PHP**: added `connect_timeout: 3` to Guzzle client in both `sequence()` and `r2z2()` to fail fast on DNS/TCP issues
- **PHP**: `r2z2()` now catches `\Throwable` (not just `GuzzleException`), logs via `error_log`, and returns **204** on upstream failure so the JS poll loop continues smoothly
- **PHP**: `sequence()` now catches `\Throwable` and logs upstream failures

---

### Default connection size from system class and wormhole type

New wormhole connections now get a default jump-mass class based on endpoint system security, and the class auto-updates when a signature's wormhole type is set.

- Connections with a **C1** endpoint default to **Medium** (`wh_jump_mass_m`); connections with a **C13** endpoint default to **Small** (`wh_jump_mass_s`). Most restrictive endpoint wins when both sides are constrained.
- When a wormhole signature linked to a connection is given a wormhole type (e.g. M001), the connection's mass class is updated automatically. K162 and typeless signatures are no-ops.
- `ConnectionModel`: added `jumpMassTypeFromMass()`, `setJumpMassType()`, `applyMassFromWormholeTypeId()`, `defaultMassFromEndpoints()`; `setAutoScopeAndType()` now appends the inferred default mass class.
- `SystemSignatureModel`: `afterInsertEvent`/`afterUpdateEvent` call `syncConnectionMass()` to push the wormhole type's mass class onto the linked connection. `beforeUpdateEvent` captures the pre-save typeId via mapper `initial` values to handle the case where the client clears typeId when linking a connection.
- `system_signature.js`: `syncConnectionMassType()` method re-renders the connection mass pill immediately after a signature typeId or connectionId save, without requiring a page refresh. Falls back to DataTables row cache for typeId when the server PATCH response returns `typeId=0` (happens when linking a connection in the same request that clears the type dropdown).

### Connection context menu reorder

"Preserve mass" moved below "change scope" in the right-click connection menu, grouping the structural options (scope, preserve mass) together after the status submenus.

---

### EOL phase states (wh_eol1 / wh_eol2 / wh_eol3)

Replaced the single `wh_eol` connection type with three distinct phases matching the game's lifecycle. Naming uses "Phase" prefix to stay audibly and visually distinct from the mass-status "Stage" terminology.

| Phase | Label | Time remaining | Map line |
|---|---|---|---|
| `wh_eol1` | Phase 1 (aging) | 1–4h | light pink |
| `wh_eol2` | Phase 2 (expiring) | 0–1h | pink (was EOL) |
| `wh_eol3` | Phase 3 (zombie) | past natural end | dark pink |

**Backend**
- `ConnectionModel`: added `wh_eol1/2/3` to type whitelist; `set_type()` resets `eolUpdated` on each phase transition (not just initial EOL); new nullable `nominalLifespan` INT column (seconds, NULL = 24h default).
- `Cron/MapUpdate`: `deleteEolConnections` expiry is now per-phase: Ph1 4h+20%, Ph2 1h+20%, Ph3 20% of nominal lifespan. `deleteExpiredConnections` uses `nominalLifespan × 1.2` instead of a fixed constant.
- `AbstractEveScoutController`: auto-detected EOL now maps `estimatedEol` hours to the correct phase type.
- Config: `EXPIRE_CONNECTIONS_EOL` + `EXPIRE_CONNECTIONS_WH` replaced by `EXPIRE_CONNECTIONS_NOMINAL_DEFAULT` (86400s).
- Old `wh_eol` DB rows are backwards-compatible: `getData()` maps them to `wh_eol1` on read.

**Frontend**
- Context menu: "toggle EOL" → "EOL phase" submenu with Phase 1/2/3 options; icons progress hourglass-start → half → end.
- Map overlay: shows phase label (`Ph.1 / Ph.2 / Ph.3`) alongside elapsed time.
- CSS: three distinct shades of pink/dark-pink for the map line and sidebar pill.

**Note**: `nominalLifespan` is not yet auto-populated from linked signatures; all connections default to 24h until a follow-up adds that sync.

---

### Fixes and features

#### pathfinder-containers

**Deployment simplification — bake ini files into image**
- `static/pathfinder/config.ini`, `static/pathfinder/pathfinder.ini`: Moved from `config/pathfinder/` into `static/pathfinder/` — now `COPY`'d into the image at build time alongside `environment.ini`
- `pathfinder.Dockerfile`: Added `COPY` lines for `templateConfig.ini` and `templatePathfinder.ini`
- `compose.yml`, `compose.test.yml`: Removed volume mounts for `config.ini` and `pathfinder.ini` — only `plugin.ini` remains mounted at runtime
- `.env.example`: Added `SERVER_NAME` with explanation (used as cache key seed and ESI User-Agent); all deployment-specific variables are now documented
- All deployment-specific values in `pathfinder.ini` (`PF_INSTALL_NAME`, `PF_REGISTRATION_STATUS`, `PF_SUPER_ADMIN_ID`, `PF_LOGIN_WHITELIST_*`) and `environment.ini` (`PF_DEBUG`) are now supplied via `.env` only — operators no longer need to edit any ini files

**Traefik v3.6.1**
- `compose.yml`, `compose.test.yml`: Bumped Traefik `v3.6` → `v3.6.1` — required for Docker API version auto-negotiation
- `compose.yml`, `compose.test.yml`: Dropped exposed port `8080` (dashboard) — not needed on internet-facing hosts; removed `--api.insecure=true` flag
- `compose.yml`, `compose.test.yml`: Removed redundant per-router HTTP→HTTPS redirect middleware labels — entrypoint-level redirect (`redirections.entryPoint`) already handles it; adopted unquoted label style

#### pathfinder (submodule)

**Static data: SQL patch files consolidated into eve_universe.sql**
- `export/sql/eve_universe.sql`: All patch files merged into the canonical dump — Zarzakh region/constellation/system/stargates/types appended as executable SQL (runs while `FOREIGN_KEY_CHECKS=0` is still active); `pochven_and_trailblazer.sql` and `wormhole_lifespan_fix.sql` were already baked into previous dump rows.
- `export/sql/zarzakh.sql`, `pochven_and_trailblazer.sql`, `wormhole_lifespan_fix.sql`: Deleted — no longer needed.
- `export/sql/eve_universe.sql.zip`: Regenerated.

**Static data: wormhole lifetime and attribute fixes**
- `export/sql/wormhole_lifespan_fix.sql`: Expanded to cover all identified issues — frigate WHs (A009/C008/E004/G008/Q003/Z006/M001/L005) corrected from 16h → 4.5h; Pochven exits (R081/U372/X450) corrected from 16h → 12h; C729 variant 56562 corrected (lifetime + mass values); missing attributes inserted for J377, J492, I078, L687, O546, F216 (56543). Confirmed against SDE 2025-07-07 and jambeeno.com/holes.
- `export/csv/wormhole.csv`: Added C729, F216, I078, J377, J492, L687, O546, R081, U372, X450.
- `export/sql/eve_universe.sql.zip`: Refreshed from running database with all fixes applied.

**Feature: Unknown system node placeholder**

Maps with "Allow Unknown systems" enabled can now add `???` placeholder nodes for unscanned wormhole destinations before jumping through. Addresses [#188](https://github.com/goryn-clade/pathfinder/issues/188) and [#159](https://github.com/goryn-clade/pathfinder/issues/159).

- `app/Model/Pathfinder/SystemModel.php`: `systemId` made nullable; new `securityClass` VARCHAR field added (Cortex auto-migrates); `validate_systemId()` accepts null; `getStaticSystemData()` synthesises a stub (`name='???'`, `security=securityClass`) when `systemId` is null — single chokepoint blocking all ESI calls; `getStructuresData()` returns `[]` for unknown systems; `getData()` exposes `isUnknown` and `securityClass`
- `app/Model/Pathfinder/MapModel.php`: New `allowUnknownSystems` DT_BOOL field (default 0); `getNewSystem()` accepts `?int $systemId, ?string $securityClass` — skips CCP lookup for null; user position tracking skips null systemId
- `app/Model/Pathfinder/ConnectionModel.php`: `setAutoScopeAndType()` skips `searchRoute()` when either endpoint's `systemId` is null; defaults to `wh` scope
- `app/Controller/Api/Rest/System.php`: `put()` accepts null `systemId` when `allowUnknownSystems` is enabled; always hard-deletes unknown systems (bypasses persistent-alias/signature retention checks)
- `app/Controller/Api/Map.php`: All three `searchRoute()` call sites guarded — skip route calculation when either systemId is null
- `app/Controller/Api/Rest/Route.php`: `setDynamicJumpData()` skips connections involving null systemIds when building jump graph
- `js/app/module_map.js`: `pf:renderSystemModules` suppresses all scope modules for unknown systems; `pf:updateSystemModules` returns early for unknown systems
- `js/app/map/map.js`: Unknown nodes render with a security-class-coloured dashed border, `$gray-light` name text, and `???` label; `pf-system-unknown` class applied; `parseInt(data.systemId)` null-safe in two locations; "Set destination" skipped for unknown systems
- `js/app/map/system.js`: "Unknown" checkbox in add-system dialog (right column, below Rally point); toggles between CCP system select and security class picker; region label suppressed for unknown systems
- `js/app/ui/module/system_signature.js`: When a signature's connection is changed away from an unknown system, a confirm prompt offers to delete the now-orphaned unknown node and its connection
- `js/app/ui/dialog/map_settings.js`: `allowUnknownSystems` checkbox wired alongside `trackAbyssalJumps`
- `public/templates/dialog/map.html`: "Allow Unknown systems" checkbox added to map settings
- `public/templates/dialog/system.html`: "Unknown" checkbox (right column) and security class `<select>` (C1–C6, C12, C13, C17, H, L, 0.0, T) added
- `sass/layout/_map.scss`: `.pf-system-unknown` — dashed border and `$gray-light` name text for unknown nodes

**Feature: Themes — extended coverage**
- `sass/_themes.scss`: Map canvas background (`pf-map-tab-content-area-map`, `.pf-map`) now themed in both light and high-contrast modes via `--pf-map-bg`; system nodes (`pf-system`, `pf-system-head-name`, `pf-system-head-expand`, `pf-system-body`) themed via `--pf-system-bg/border/text`; DataTables rows themed via `--pf-table-row` / `--pf-table-row-alt` (covers both Bootstrap `.table-striped` and DataTables own stripe/hover)

**Feature: Alliance map enhancements**
- `app/Controller/Admin.php`: `afterroute()` now initialises `registrationStatusButton` and `registrationStatusTitle` — prevents PHP 8 NOTICE when `/admin/login` renders `sso.html` without those variables set; `initMaps()` now queries `AllianceMapModel` for SUPER admins and shows alliance maps in `/admin/maps` grouped by alliance name; empty-state notification updated to cover both map types; `tplLogged` now always initialised to `false` in `beforeroute()` — prevents PHP 8 undefined-variable NOTICE when character is not an admin
- `app/Controller/Api/Rest/Map.php`: Alliance map deletion now restricted to SUPER role — any other character gets a 401; mirrors the existing `map_delete` right check for corporation maps
- `public/templates/admin/maps.html`: Added "Alliance maps" section below corp maps table, rendered only when alliance maps exist
- `public/templates/admin/notification.html`: Guard `@notification->title` access with a null check — prevents PHP 8 NOTICE when no notification is set

**Fix: Pasting multiple structures only persists the last one**
- `app/Model/Pathfinder/AbstractPathfinderModel.php`: `reset()` now calls `parent::reset()` — previously only cleared `$fieldChanges`, leaving the Cortex mapper state intact. Cortex's `getRelInstance()` returns a Registry-cached singleton for each relation type, so `saveStructure()` was reusing the same `CorporationStructureModel` instance with its `_id` still set from the previous iteration. Iterations 2 and 3 were doing `UPDATE` on the existing junction row instead of `INSERT`, leaving only one `corporation_structure` record (for the last structure). `getStructuresData()` uses an EXISTS subquery on that junction table, so only the last structure was returned by `updateUserData`, causing the JS to delete the others.

**Security: Upgrade firebase/php-jwt v6 → v7 (CVE-2025-45769)**
- `composer.json`, `composer.lock`: Bumped `firebase/php-jwt` from `v6.11.1` to `v7.0.5` — resolves CVE-2025-45769 (weak encryption, low severity). No API changes required in `Sso.php` — `JWT::decode()` and `JWK::parseKeySet()` signatures are unchanged; v7 adds only stricter numeric validation on `iat`/`nbf`/`exp` claims and a 2048-bit RSA key minimum.

**Fix: Signature type dropdown not opening on arrow click**
- `js/app/ui/form_element.js`: `initSignatureTypeSelect` now suppresses the spurious `select2:opening` that fires during `_bindAdapters` init (via a one-shot `preventDefault` handler), preventing the dropdown from being in an already-open state when the user clicks the ▼ arrow; also guards against accidental item selection on the `mouseup` that immediately follows a `mousedown`-triggered open

**Fix: MapUpdate cron crashes with TypeError on scalar INI values**
- `app/Lib/Config.php`: `getMapsDefaultConfig()` now guards with `is_array()` before calling `arrayChangeKeyCaseRecursive()` — scalar lookups like `getMapsDefaultConfig('private.lifetime')` were passing an `int` to a PHP 8 strict `array` type hint, causing a TypeError 500 on every cron execution

**Fix: 504 gateway timeout causes logout instead of retry**
- `static/nginx/site.conf`: `fastcgi_read_timeout` reduced from 600s to 40s for all PHP; `/setup` location block retains the 600s override for long-running `buildIndex` operations
- `static/php/fpm-pool.conf`: Added `request_terminate_timeout = 35s` (was erroneously placed in `php.ini` where it is silently ignored as a PHP-FPM pool directive)
- `static/php/php.ini`: Removed misplaced `request_terminate_timeout`; added `max_execution_time = 30`
- `js/app/mappage.js`: `handleAjaxErrorResponse` now treats HTTP 504 and network-abort (status 0) as transient — shows a "Connection timeout — retrying…" notification, sets program status offline, and reschedules both map and user update pings after 10 s instead of triggering `pf:shutdown`/logout

**Security: SQL injection fix in Route API + error text redaction**
- `app/Controller/Api/Rest/Route.php`: `excludeTypes` parameter was concatenated unsanitized into a REGEXP SQL clause, enabling error-based SQL injection via `ExtractValue()`. Values are now validated against `ConnectionModel::getConnectionTypeWhitelist()` before use — any value not in the whitelist is silently dropped.
- `app/Model/Pathfinder/ConnectionModel.php`: Exposed `$connectionTypeWhitelist` via new `public static getConnectionTypeWhitelist()` accessor so Route.php can reference the canonical list without duplication.
- `app/Controller/Controller.php`: `showError()` now redacts the raw DB error text when `DEBUG < 1`, returning a generic `"An internal error occurred"` message instead. Previously only the stack trace was suppressed; the DB error string (which provides the oracle for error-based exfiltration) was still returned to the client. Set `PF_DEBUG=0` in production `.env` to activate.

**Feature: Granular K162 signature options**
- `js/app/init.js`: Replaced `incomingWormholes` with `incomingWormholesGeneric` (grouped labels: C1/2/3, C4/5, C6, H, L, 0.0, Thera, Trig, Drifter) and `incomingWormholesSpecific` (per-class: C1–C6, H, L, 0.0, Thera, T Pochven, C14–C18 drifter holes)
- `app/Model/Pathfinder/MapModel.php`: New `granularK162` DT_BOOL field (default 0); exposed in `getData()`
- `js/app/ui/dialog/map_settings.js`: `granularK162` checkbox wired alongside `allowUnknownSystems`
- `public/templates/dialog/map.html`: "Granular K162" checkbox added to map settings
- `js/app/ui/module/system_signature.js`: Incoming WH optgroup now built outside the sig-type cache; `getSignatureTypeOptions()` accepts `granularK162` flag and selects the appropriate source list; `getSignatureTypeOptionsBySystem()` passes the flag through

**Feature: Drifter hole wormhole sig types and auto-alias**
- `js/app/conf/signature_type.js`: Added `drifterWH` wormhole group (Z647/D382/O477/Y683/N062/R474 — C1–C6) to all five drifter hole classes (C14–C18); previously only combat sites were defined
- `app/Model/Pathfinder/SystemModel.php`: `afterInsertEvent()` now auto-sets the alias for the five drifter hole systems on first add to a map (31000001 → Sentinel MZ, 31000002 → Liberated Barbican, 31000003 → Sanctified Vidette, 31000004 → Conflux Eyrie, 31000006 → Azdaja Redoubt)

**Feature: Native Discord embeds**
- `app/Lib/Logging/Handler/AbstractWebhookHandler.php`: Added `getPostData()` override hook; added `getAttachmentColorInt()` helper; added cURL error/timeout logging to `write()`; removed unused `$response` variable
- `app/Lib/Logging/Handler/DiscordMapWebhookHandler.php`: Full rewrite — native Discord `embeds` payload with author avatar, colour-coded title, code-block description, and optional extra fields; bypasses Slack compat path entirely
- `app/Lib/Logging/Handler/DiscordRallyWebhookHandler.php`: Full rewrite — native Discord embed with System/Region/Security/Wormhole/Effect/TrueSec/Alias/Description/Map Link fields; removes the erroneous `Markdown\n` prefix bug
- `app/Model/Pathfinder/MapModel.php`: `getDiscordWebHookConfig()` — removed `/slack` suffix from webhook URL (was routing through Slack compat endpoint)
- `public/templates/dialog/system_rally.html`: Removed mail poke checkbox and status indicator (SMTP removed in Phase 2)

**Enhancement: Maintainer info update**
- `public/templates/dialog/credit.html`: Removed URL row (`pathfinder-w.space`), Media row (YouTube), donation heading, Patreon/PayPal buttons, and "By Exodus 4D" attribution; updated Repository link to `goryn-clade/pathfinder`; License row retained
- `public/templates/view/login.html`: Replaced personal "About Me" content with community-focused text pointing to the goryn-clade repo; updated footer copyright to "Goryn Clade"; removed YouTube from footer social links; updated all `exodus4d/pathfinder` issue tracker links to `goryn-clade/pathfinder`; cleaned up first-person FAQ answers

**Enhancement: Account settings simplification**
- `app/Model/Pathfinder/UserModel.php`: Removed `email` field and `validate_email()` — email column stays in DB but is no longer read or written
- `app/Controller/Api/User.php`: Removed captcha requirement from `saveAccount()` — username now saves directly on submit; captcha still required for account deletion
- `public/templates/dialog/settings.html`: Removed email display/edit rows and captcha image/input from the Account tab
- `js/app/ui/dialog/account_settings.js`: Removed captcha config, `show.bs.modal` captcha init, and captcha refresh calls from save/error handlers

**Fix: README typo**
- `README.md`: Corrected `goryn-clayde` → `goryn-clade` in issue tracker link

---

#### pathfinder_esi — v3.0.13 → v3.0.14

- v3.0.14: Added `getCharactersAffiliationRequest()` — new public-facing callable (`getCharactersAffiliation`) for per-login affiliation refresh, reusing the existing `/v1/characters/affiliation/` POST endpoint and `Affiliation` mapper

#### pathfinder (submodule)

**Fix: Character corporation not updating after corp change**

Root cause: `updateCharacter()` set `corporationId` via a FK assignment that silently failed when the new corporation row didn't yet exist in the local DB. `AbstractModel::save()` swallows `DatabaseException`, so the FK violation was never surfaced. The character's `lastLogin`/`updated` timestamps still advanced (from a separate `touch('lastLogin')` call in `loginByCharacter()`), masking the failure.

- `app/Model/Pathfinder/CharacterModel.php`: Added `affiliationUpdated` nullable timestamp column (requires migration); added `updateAffiliation()` — fetches corp/alliance from ESI via the new `getCharactersAffiliation` endpoint, applies a 1-hour TTL gate, guards against FK constraint failure by verifying the corporation model is valid before assigning it to the character, logs all failure paths to `sso.log`; removed now-orphaned `corporationId`/`allianceId` assignments from `updateFromESI()`
- `app/Controller/Ccp/Sso.php`: Removed affiliation lookup from `getCharacterData()` (now only fetches basic character data); removed corp/alliance assignment from `updateCharacter()`; wired `updateAffiliation()` into SSO callback (after `updateCharacter()`) and character-switch path (after `updateFromESI()`), both before `isAuthorized()` check
- `app/Controller/Controller.php`: Wired `updateAffiliation()` into cookie login path after character reload, before `isAuthorized()` check
- `composer.json`, `composer.lock`: Bumped `goryn-clade/pathfinder_esi` `3.0.13` → `3.0.14`

**Fix: Crash on route calculation for systems absent from local DB (e.g. Zarzakh)**
- `app/Controller/Api/Rest/Route.php`: `getSystemInfoBySystemId()` now returns `null` when the system is not in `$nameArray` — previously accessed `$this->nameArray[$systemId]` without a null guard, causing two PHP 8 NOTICEs (escalated to 500) for systems like Zarzakh (30100000) that are absent from the DB dump

**Fix: TypeError crash in system route module when server returns no route data**
- `js/app/ui/module/system_route.js`: `.then()` handler now guards with `Array.isArray(payload.data.routesData)` before invoking the callback — previously a 200 response with no `routesData` key caused a TypeError that fell into `.catch()` as a plain `Error` object; `.catch()` handler now guards `payload.data.jqXHR` with a ternary — prevents crash when `payload` is a plain `Error` with no `jqXHR` property

**Fix: TypeError in `updateMapByCharacter` when location data is empty**
- `app/Controller/Api/Map.php`: Added `isset($currentPosition['systemId'])` guard before comparing `$targetSystemId` — `$currentPosition` comes from `$newSystemPositions['location'] ?? []` and may be empty, causing an undefined array key notice escalated to 500

#### pathfinder_esi — v3.0.7 → v3.0.12

- v3.0.7: Removed `getStatusRequest()` and `meta.status` spec entry — `https://esi.evetech.net/status.json` no longer exists; CCP removed per-route health reporting
- v3.0.8–v3.0.12: Fixed PHP 8 `$body->error` access across all 39 ESI response handlers in `Esi.php` — PHP 8 warns on property access on non-objects (arrays return E_WARNING) and on missing properties on stdClass (E_WARNING); guarded with `is_object($body) && ($body->error ?? null)` pattern; also guarded bare `= $body->error` assignments with `?? null`

#### pathfinder (submodule)

**PHP 8 undefined array key fixes — live testing (browser smoke tests)**

F3 escalates all PHP NOTICEs to HTTP 500. The fixes below were discovered via live browser testing and resolve undefined array key warnings that are fatal under PHP 8.

- `app/Model/Pathfinder/CharacterModel.php`: `isOnline()` — `$onlineData['online']` → `?? false`; `updateLog()` — `$additionalOptions['markUpdated']` → `?? false`; `addLogHistoryEntry()` — `$historyLog['system']['id']` and `$historyLogPrev['system']['id/name/alias']` all guarded with `?? null`
- `app/Controller/Api/Map.php` (`updateMapByCharacter`): `$newSystemPositions['defaults']` and `['location']` → `?? []`
- `app/Model/Pathfinder/ConnectionModel.php`: `getData()` and `beforeInsertEvent()` — `json_decode($this->get('type', true) ?? 'null')` to guard null passed to `json_decode`; `set_endpoints()` — `$endpointsData['source']` and `['target']` → `?? []`; `setEndpointData()` — `$endpointData['types']` → `?? []`
- `app/Model/Pathfinder/SystemSignatureModel.php` (`set_connectionId`): widened type declaration to `ConnectionModel|int|null` — nullable connection passed from `ConnectionModel::setEndpointData()`
- `app/Controller/Api/Rest/Connection.php`: `$addData`/`$filterData` top-level keys → `?? []`; `scope`, `type`, `disableAutoScope` option keys guarded
- `app/Controller/Api/Rest/System.php`: `$systemData['statusId']` → `?? 0`
- `app/Controller/Api/Rest/Signature.php`: `deleteOld`, `deleteConnection` (×2) → `?? false`
- `app/Controller/Api/Rest/SignatureHistory.php`: `stamp` → `?? ''`
- `app/Controller/Api/Rest/Structure.php`: `$requestData['id']` → `?? 0`; `$structureData['name']` → `?? ''`; `$structureData['systemId']` → `?? 0`
- `app/Controller/Api/Rest/SystemGraph.php`: `$requestData['systemIds']` → `?? []`
- `app/Controller/Api/Rest/Log.php`: `$requestData['connectionId']` → `?? 0`
- `app/Controller/Api/Rest/Map.php`: `mapCharacters`, `mapCorporations`, `mapAlliances` keys → `?? []`
- `app/Controller/Api/Rest/SystemSearch.php`: `$requestData['page']` → `?? 1`
- `app/Controller/Api/Rest/AbstractEveScoutController.php`: `wh_exits_outward` → `?? false`; `wh_type` → `?? ''` (×2); `estimatedEol` → `?? 0`; `jumpMass` → `?? ''`; `eveScoutSignature['name']` → `(?? null) ?: null` (×2)
- `app/Controller/Api/Rest/Route.php`: `$routeData['wormholesTurnur']` → `?? false` in `post()` filter passthrough
- `app/Controller/Api/System.php`: `$destData` → `?? []`; `clearOtherWaypoints`/`first` → `?? false`; `$response['error']` → `?? ''`; `$rallyData['systemId']` → `?? 0`; rally poke flags → `?? '0'`; `$rallyData['message']` → `?? ''`
- `app/Controller/Api/User.php`: `$data['cookie']` → `?? ''`; `$data['deleteCookie']` → `?? false`; `$data['targetId']` → `?? 0`; `$response['error']` → `?? ''`
- `app/Controller/Api/Setup.php` (`buildIndex`/`clearIndex`): `type`, `countAll`, `count`, `offset` keys → `?? ''/0`
- `app/Controller/Api/GitHub.php`: `$release['name']` → `?? ''`; `$release['body']` → `?? ''`
- `app/Cron/CcpSystemsUpdate.php`: `$params['offset']` / `['length']` → `?? 0`
- `app/Cron/Universe.php`: `$params['type']` → `?? ''`; `$params['offset']` / `['length']` → `?? 0`

**Feature: Turnur connections in route search**

- `app/Controller/Api/Rest/Route.php`: `setTheraJumpData()` was including all EVE Scout connections without filtering; refactored into shared `buildEveScoutJumpData(int $hubSystemId, string $cacheKey): array` that filters by source/target system ID; `setTheraJumpData()` now delegates to it with `THERA_SYSTEM_ID = 31000005`; added `setTurnurJumpData()` using same helper with `TURNUR_SYSTEM_ID = 30002086`; both called in `searchRouteCustom()` and `searchRouteESI()`; added `wormholesTurnur` to `post()` filter passthrough
- `public/templates/dialog/route.html`: added Turnur checkbox (`#form_connections_turnur`, `name="wormholesTurnur"`) alongside Thera checkbox
- `public/templates/dialog/route_settings.html`: added Turnur checkbox and `$(document).ready` initialisation from `routeSettings.wormholesTurnur`
- `js/app/ui/module/system_route.js`: added `wormholesTurnur` to rowData fallback, routeData passthrough, routeSettingsData parsing, and routeDialogData parsing; Turnur checkbox enabled/disabled and checked/unchecked in `setDialogObserver()` alongside Thera

**Feature: Migrate killstream from deprecated WebSocket to R2Z2 HTTP polling API**

The zKillboard WebSocket (`wss://zkillboard.com/websocket/`) was shut down. The live killstream now uses the R2Z2 HTTP polling API (`r2z2.zkillboard.com/ephemeral/`).

- `app/pathfinder.ini` / `config/pathfinder/pathfinder.ini`: added `ZKILLBOARD_R2Z2 = https://r2z2.zkillboard.com/ephemeral` config key
- `app/Controller/Api/Killboard.php` (new): PHP proxy controller to work around CORS restrictions on R2Z2; `sequence()` proxies `/sequence.json` with 5s F3 cache; `r2z2()` proxies `/{sequenceId}.json`, returning 204 (not 404) for not-yet-available sequences to suppress log noise
- `static/nginx/site.conf`: added `location ^~ /api/Killboard/r2z2/` block with `access_log off` to suppress high-frequency polling requests from nginx access logs
- `app/Model/Pathfinder/UserModel.php`: fixed three PHP 8 undefined array key errors in `getSessionCharacter()` — `$currentSessionUser['ID']` → `?? null`; `$sessionCharacters[0]` → `reset($sessionCharacters)`; `$data['ID']` → `?? 0`
- `js/app/ui/module/system_killboard.js`: replaced `initWebSocket()` with R2Z2 poller — `initPoller()`, `pollNext()`, `stopPoller()`, `adaptR2z2Response()`; one static poller shared across all module instances; 6s back-off on 204 (no kill yet); stale-sequence resync after 5 consecutive 204s; tab-visibility pause with deduplicated `visibilitychange` listener; `pollInFlight` guard prevents concurrent fetches; `adaptR2z2Response()` flattens R2Z2's `{esi, zkb}` envelope to the flat format `cacheWsResponse()` and `onWsMessage()` expect
- `js/app/ui/module/system_killboard.js`: removed `/npc/0/` filter from historical kills REST API URL — NPC kills now included in both historical and live streams
- `js/app/ui/module/system_killboard.js`: added "NPC kills" checkbox to stream filter options panel; defaults to enabled; `filterKillmailByStreams()` extended to accept `zkbData` and gate on `zkbData.npc`; same filter applied to historical kills in `showKills()` before the ESI fetch
- `js/app/ui/module/system_killboard.js`: NPC attacker portrait now shows EVE default portrait (`characters/1/portrait`) instead of broken `src="#"` when `character_id` is 0
- `js/app/ui/module/system_killboard.js`: live stream kills from systems not on any map (e.g. 'all' stream) now show the system name — `onWsMessage()` is async and falls back to an ESI `/universe/systems/{id}/` lookup with permanent in-memory cache when `MapUtil.getSystemData()` returns nothing
- `js/app/worker/map.js`: guarded both `socket.send()` call sites with `if(socket)` null check — prevents crash when a `ws:send` or `sw:closePort` message arrives after the map WebSocket has closed and reset to null
- `websocket/cmd.php`: debug level 2 (default) now excludes `E_DEPRECATED` alongside `E_NOTICE` — suppresses constant Ratchet 0.4.3 dynamic property warnings on PHP 8.2 (`httpHeadersReceived`, `httpRequest`, `WebSocket`, `resourceId`, `remoteAddress` on `React\Socket\Connection`)

- `app/Controller/Controller.php` (`getEveServerStatus`): removed `getStatus` ESI call — always errored (404); ESI API panel now shows static OK/green
- `js/app/ui/dialog/map_settings.js`: new map tab now appears immediately after creation — PUT success handler injects map into `currentMapData` cache and calls `updateMapModule` when no tab exists yet
- `js/app/ui/dialog/map_settings.js`: map deletion now removes the tab immediately — DELETE success handler calls `deleteCurrentMapData` + `updateMapModule`
- `js/app/module_map.js`: fixed `updateMapModule` tab removal check — `Array.find` returns `undefined` (not `false`) for missing maps; `!== false` check was keeping deleted tabs; changed to truthiness check so both `undefined` and `false` trigger tab removal
- `js/app/setup.js`: fixed WebSocket health check showing "CONNECTION FAILED" after a successful connection — `onclose` always fires after `onmessage` and was unconditionally overwriting the status; added `connected` flag set on valid response, `onclose` now skips the failure update when already connected
- `app/Controller/Setup.php`: removed SMTP_* vars from `$environmentVars` — email logging was removed in Phase 2; entries were showing as blank/missing in Settings > Configuration
- `app/Controller/Setup.php`: added Docker environment detection (`/.dockerenv`); Server > Environment variables panel now shows a "Container environment detected" banner and marks all build tool checks as "not required" / green when running in a container
- Removed swiftmailer entirely: `Config::getSMTPConfig()`, `isValidSMTPConfig()`, `getNotificationMail()` removed from `Config.php`; `isMailSendEnabled()` / `getSMTPConfig()` removed from `MapModel`, `UserModel`; mail poke block removed from `SystemModel`; `sendDeleteMail()` / `afterEraseEvent` mail call removed from `UserModel`; `send_rally_mail_enabled` label removed from `Setup.php`; `swiftmailer/swiftmailer` removed from `composer-dev.json`; SMTP_* vars removed from `app/environment.ini`, `development/environment.development.ini`, `development/env_upgrade.sh`

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

**PHP 8.2 compatibility — static analysis (PHPStan level 8)**
- Added comprehensive `@property` PHPDoc declarations to `app/Model/AbstractModel.php` covering 39 common ORM properties (e.g., `$_id`, `$name`, `$typeId`, `$characterId`, `$mapId`, `$systemId`, etc.) — resolves ~576 of 642 "undefined property" PHPStan errors
- Fixed 147+ malformed `@var` PHPDoc comments across 50 files: corrected format from `@var $var Type` (invalid) to `@var Type $var` (valid)
- Added 23+ null checks across controllers to guard method calls on nullable model objects returned by factory methods (`getCharacter()`, `getUser()`, `getCorporation()`, `getAlliance()`, `getDB()`, etc.)
- Fixed 6 "Cannot call method" errors in `AbstractModel.php`: added null checks for `getTableModifier()` calls and DateTime validation
- Fixed 5 remaining "Cannot call method" errors in Model classes: CharacterModel, CronModel, UserModel, StructureModel with DateTime/model object null checks
- Changed `AbstractModel::getNew()` return type from `?self` to `self` — method always returns instance or throws exception, never null
- Added 101+ missing parameter type hints (10.4% of 970 total):
  - Phase 1: `AbstractModel` (17), `MapModel` (8+), `Route.php` (10+), `CharacterModel` (11), `SystemModel` (8), `ConnectionModel` (7), `SystemSignatureModel` (7), `AbstractWebhookHandler` (11), `CorporationModel` (7), `Util.php` (7)
  - Phase 2a: `TypeModel` (5), `AbstractUniverseModel` (6), `PriorityCacheStore` (3), `Controller` (4), `CharacterLogModel` (3), `SystemModel` (Universe, 4), `Config` (3), `Api/Rest/System` (3), `Api/Rest/Map` (3)
  - Phase 2b: `Api/Rest/Route` (7), `Pathfinder/SystemModel` (2), `ConnectionModel` (3 — setEndpointData generic type added)

**Remaining PHP 8 static analysis issues (PHPStan level 8)**
- 7 "Cannot call method on nullable" errors remaining (mostly edge cases in Rest/Log.php, Rest/Map.php, User.php where assignments in conditionals still register as nullable)
- ~869 "missing parameter type" errors remaining (101+ fixed in phase 1-2a-2b, mostly in vendor code and additional Model/Controller files)
- ~1,000 "Class not found" errors for Fat-Free Framework classes (`Base`, `Template`, `Log`, etc.) — would require stubs or F3 type definitions
- ~626 "missing return type" errors across Model/Controller methods
- ~729 "missing iterable value type" errors (array properties/parameters without generic type parameters)

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

### Phase 3 — PHP 8.2 Static Analysis

#### pathfinder-containers

**Tooling**
- Added `squizlabs/php_codesniffer` + `phpcompatibility/php-compatibility` to Composer dev deps — purpose-built cross-version compat checker
- Added `.phpcs.xml` — PHPCompatibility ruleset targeting PHP 8.2, scanning `pathfinder/app` and `websocket/app`, excluding vendor/tmp; `phpcs` exits 0 (0 errors, 1 benign warning about a PHP 7.4 method signature change in phpcompatibility itself)
- Updated `rector.php` — activated `withPhpVersion(PHP_82)`, `withPhpSets(php82: true)` across both app paths; previously had all coverage levels at 0 / no target
- Added `phpstan-baseline.neon` (tracked) — 2481 pre-existing level-8 errors baselined to separate compat hits from debt; started at ~4015 before this phase's fixes
- Added `phpstan-bootstrap.php` (tracked, not active) — kept for reference; `scanFiles` approach replaced `bootstrapFiles` to avoid F3's AutoloadSourceLocator crash
- Added `PHP8_STATIC_ANALYSIS.md` — 7-phase execution runbook with STOP checkpoints and exact commands
- Updated `phpstan.neon` — wired in baseline, switched from `bootstrapFiles` to `scanFiles` for F3/Monolog/Ratchet vendor files (avoids react/promise `case x;` syntax causing F3 to throw a fatal via `set_error_handler`)

**Dockerfile fix**
- `pathfinder.Dockerfile`: Added post-composer `sed` to initialize `$fieldsCache = []` in f3-cortex's class declaration — `array_key_exists($key, null)` is TypeError in PHP 8 but returned false silently in PHP 7; fixes `/setup` 500 on `CronModel->getData()`

#### pathfinder (submodule)

**Rector PHP 8.2 rewrites (91 files, 406 insertions, 580 deletions)**
- String class name literals → `::class` constants in all ORM `fieldConf` relation arrays (`belongs-to-one`, `has-many`)
- Closures → arrow functions throughout Controllers and Lib/
- Switch → match expressions where branches fall through cleanly
- Constructor property promotion on value-object / non-ORM classes
- `$this->client::class` in `AbstractClient::__call()` (new class-on-expression syntax)

**Implicit nullable parameter fixes**
- `MapModel::save(?CharacterModel $characterModel = null)` — added `?` prefix
- `AbstractMapTrackingModel::save(?CharacterModel $characterModel = null)` — added `?` prefix
- `Config::inDownTimeRange(?\DateTime $dateCheck = null)` — added `?` prefix
- `AbstractLog::addHandler(string, ?string, ?\stdClass)` — added `?` prefix on optional params
- `LogInterface::addHandler(string, ?string, ?\stdClass)` — matching interface declaration

**PHP 8 compatibility fixes**
- `Config::parseSocketUrl()`: added `is_string($socketUrl)` guard before `parse_url()` calls
- `Setup::getSessionConfig()`: added `is_string($sessionSavePath)` guard before `parse_url()` call
- `Sso::getCcpJwkData()`: added missing `return []` in failure branch (PHPStan dead-code hit)
- `AbstractWebhookHandler`: removed dead `CURLOPT_SAFE_UPLOAD` block (constant removed in PHP 8.0)
- `ReverseSplFileObject`: added explicit return types on all 5 `Iterator` methods (`rewind(): void`, `current(): string`, `key(): int`, `next(): void`, `valid(): bool`) — PHP 8.1 `method.tentativeReturnType` rule

#### websocket (submodule)

- `composer.json`: `"php-64bit": ">=7.1"` → `">=8.2"`
- `Payload::jsonSerialize(): mixed` — added return type for PHP 8.1 `JsonSerializable` covariance
- Rector PHP 8.2 rewrites (5 files): constructor promotion on `Store`, `TcpSocket`; arrow functions in `MapUpdate`, `LogFileHandler`, `AbstractMessageComponent`

---

### Phase 4 — Runtime smoke-test fixes (PHP 8 / browser testing)

#### pathfinder-containers

**Dockerfile (`pathfinder.Dockerfile`)**
- Added `sed` patch for `pathfinder_esi/app/Client/Ccp/Sso/Sso.php`: `if(!$body->error)` → `if(!($body->error ?? null))` — `$body` is null on network error; PHP 8 E_WARNING on null property access is fatal via F3
- Added `sed` patch for `pathfinder_esi/app/Client/EveScout/EveScout.php`: `if(!$body->error)` → `if(!isset($body->error))` — EVE Scout API v2 returns a JSON array on success; PHP 8 rejects property access on arrays

**Static config**
- `static/php/php.ini`: Added `session.gc_maxlifetime = 86400` and `session.cookie_lifetime = 86400` — default 1440s (24 min) was expiring sessions, causing 403 on idle
- `static/pathfinder/environment.ini`: `DEBUG = 0` → `DEBUG = 3` — enables full stack traces in dev

**Dependencies**
- `pathfinder/composer.json`: Re-added explicit `"bcosca/fatfree-core": "3.9.*"` — was dropped during PHP 8 upgrade; 3.9.2 is already installed transitively but pinning prevents uncontrolled upgrades

#### pathfinder (submodule)

**PHP 8 compatibility — controllers**
- `app/Controller/AccessController.php`: Added `is_object($character)` guard in debug logging block — `$character` is null in "NO SESSION FOUND" path; accessing `->name` on null is fatal
- `app/Controller/Api/Map.php`: `$systemData['mapId']` → `$systemData['mapId'] ?? 0` (line 707); added `?? []` / `?? false` guards on all keys in `updateUserData` system data block
- `app/Controller/Api/Rest/Map.php`: `$compare['old']` / `$compare['new']` → `?? []` — `compareAccess()` returns only `['new' => ...]` when no existing records exist
- `app/Controller/Api/Rest/System.php`: `$requestData['isCcpId']` → `?? false` — optional GET param
- `app/Controller/Api/Statistic.php`: `$postData['period/typeId/year/week']` → `?? ''` / `?? 0` guards on all optional POST params
- `app/Controller/Api/Rest/AbstractEveScoutController.php`: Added null guard after `getSystemData()` — returns null when system not in universe DB; throws `RuntimeException` caught by existing try/catch

**PHP 8 compatibility — route search (`app/Controller/Api/Rest/Route.php`)**
- `filterData` keys `stargates/jumpbridges/wormholes/…/excludeTypes/endpointsBubble` — all optional; added `?? false/''/ []` guards
- `array_walk` callback `&$key` → `$key` — PHP 8 rejects by-reference key parameter
- `getRouteCacheKey()`: was passed string `$systemFrom`/`$systemTo` (names) where `int` expected; corrected to pass `$systemFromId`/`$systemToId`
- Cache key `implode`: `$keyParts` can contain `excludeTypes` array; replaced with `array_map(fn($v) => is_array($v) ? implode(',', $v) : (string)$v, $keyParts)`
- `$this->jumpArray[$systemId]` before key initialised → `$this->jumpArray[$systemId] ?? null` in `is_array()` check
- Thera jump data missing `regionId`/`constellationId`/`trueSec` fields: added `?? 0` / `?? 0.0` defaults in `updateJumpData()`
- `jumpNodes` key missing from dynamic jump data entry initialisation — added `'jumpNodes' => []`

**PHP 8 compatibility — models**
- `app/Model/Pathfinder/SystemModel.php`: `strtotime($this->rallyUpdated)` → ternary guard (field is nullable)
- `app/Model/Pathfinder/ConnectionModel.php`: `strtotime($this->eolUpdated)` → ternary guard (field is nullable)
- `app/Model/Pathfinder/MapModel.php`: `$config->slackWebHookURL` / `$config->slackChannel` → `?? null` guards — config object properties absent when webhook not configured

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