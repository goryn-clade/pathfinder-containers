# TODO

## Feature: Turnur connections in route search ✅

Implemented. `setTheraJumpData()` is now scoped to Thera (31000005) only via a shared `buildEveScoutJumpData(hubSystemId, cacheKey)` helper. `setTurnurJumpData()` uses the same helper filtered to Turnur (30002086). Both have separate 60s cache keys. A `wormholesTurnur` checkbox has been added to both route dialog templates, wired up in `system_route.js` alongside the Thera checkbox.

---

## Feature: Update Static Data
Update Static data with things such as: 
- New names for drifter wormholes: 31000004 is now Conflux Eyrie, for example
- New Ships
- https://github.com/goryn-clade/pathfinder/issues/186 to adjust M001/L005 lifespan
- check J377 exists
- https://github.com/goryn-clade/pathfinder/issues/177 update statics for drifter wormholes
- 


---
## Enhancement
Update license and maintainer information
As per https://github.com/goryn-clade/pathfinder/issues/109

We should update the html that still shows the original author's information to reflect the current maintainer information.

URL: remove
Media: remove
License: keep
Repository: update to goryn-clade/pathfinder
"If you like pathfinder...": remove
Patreon/paypal buttons: remove

### Readme
- Updates to pathfinder-containers readme
  - any prerequisites for docker/docker-compose versions
  - document breaking changes in compose file
  - document upgrade from old to new compose solution
- Updates to pathfinder readme
  - Update with v3 breaking changes, additions, fixes
  - remove file structure, why do we have this?
  - update contributors

---

## Feature: Themes ✅

https://github.com/goryn-clade/pathfinder/issues/87

Implemented. Two theme toggles added to Menu below Full screen:
- **Light theme** — flips body, navbar, modals, popovers, dropdowns, panels, inputs, tables, scrollbars to light colours. Map canvas stays dark.
- **High contrast** — pure-black background with white text for maximum readability.

Preference stored in `localStorage` (`pf_theme`), applied via `html[data-theme]` attribute in an inline `<head>` script before first paint (no FOUC). Clicking the active theme again reverts to dark. CSS overrides live in `sass/_themes.scss` using CSS custom properties.

### Still to do: 
- Map canvas should be affected by light mode but is not
- table rows should be  affected by light mode but are not
- Map canvas area and map elements should be affected by high-contracts but are not


## Investigation: Account Settings popover

- What is the point of the Captcha on this page? I understand it must be entered to change details, but why? what security control does it provide?
- Why do we have an email address? 


---

## Feature: Unknown system node ✅

Implemented. See CHANGELOG for full details.

---
## Feature: Alliance Map enhancements:

This feature is a response to issue: 
https://github.com/goryn-clade/pathfinder/issues/187

My understanding is that the simplest way to do this would be to tie the deletion of alliance map to only the "admin" role. Maybe the simplest method would be to fix alliance maps to show correctly on the /admin/maps view, and only allow deletion from here. 

---


## Known bugs / deferred fixes

- **Map deletion tab doesn't disappear immediately**: After a successful DELETE, `deleteCurrentMapData` + `updateMapModule` is called but the tab may not be removed. Attempted fix (changing `!== false` to truthy check in `module_map.js:1422`) needs verification.

- **f3-cortex: adopt tagged release**: `ikkez/f3-cortex` is pinned to `dev-master#47d2596` (2025-07-08) because three PHP 8.2 type-hint fixes landed after the `v1.7.8` tag. Once a `v1.7.9`+ tag is published, switch `composer.json` to `"1.7.*"` and drop the commit hash.

- **Audit Discord and Slack webhook integrations**: Verify both integrations still work with current platform APIs. Discord has deprecated legacy webhook formats in favour of structured embeds; Slack has migrated from incoming webhooks to Block Kit. Check `app/Model/Pathfinder/MapModel.php` (`getDiscordWebHookConfig`, `getSlackWebHookConfig`) and any classes that send webhook payloads.

- **zKillboard WebSocket deprecated**: ✅ Implemented — see changelog.

---

## Feature: Migrate killstream to R2Z2 API ✅

The zKillboard WebSocket (`wss://zkillboard.com/websocket/`) is dead. The replacement is the R2Z2 HTTP polling API. The historical REST API (`zkillboard.com/api/...`) used for loading past kills still works and is unchanged.

### R2Z2 API summary

- `GET https://r2z2.zkillboard.com/ephemeral/sequence.json` → `{"sequence": 96976850}` (current head)
- `GET https://r2z2.zkillboard.com/ephemeral/{sequence_id}.json` → killmail JSON or 404
- Response format: `{ killmail_id, hash, esi: {killmail_id, killmail_time, solar_system_id, attackers, victim}, zkb: {hash, solo, npc, totalValue, ...}, uploaded_at, sequence_id }`
- Rate limit: 20 req/s per IP. Minimum 6s wait after 404.
- Files ephemeral ~24h. No auth, no server-side filtering.

### Architecture

Client-side polling, same as the old WebSocket model where each browser had its own connection. One static poller shared across all killboard module instances (multiple maps/tabs), broadcasting to subscribers. Sequence tracked in memory — starts from current head on page load (same as old WS behavior; historical REST API covers the gap).

### Key data format difference

Old WebSocket sent killmail fields flat at root: `{killmail_id, solar_system_id, victim, attackers, zkb}`. R2Z2 nests them inside `esi`: `{esi: {killmail_id, solar_system_id, ...}, zkb: {...}}`. A thin adapter flattens R2Z2 back to the old format so `cacheWsResponse()` and `onWsMessage()` work unchanged.

### Implementation steps

#### 1. Config: add R2Z2 URL

- [ ] `pathfinder/app/pathfinder.ini` — add `ZKILLBOARD_R2Z2 = https://r2z2.zkillboard.com/ephemeral` after `Z_KILLBOARD` in `[PATHFINDER.API]` (line ~386)
- [ ] `config/pathfinder/pathfinder.ini` — mirror same addition (line ~386)
- [ ] `pathfinder/app/Controller/Api/Map.php` — add `'zKillboardR2z2' => Config::getPathfinderData('api.zkillboard_r2z2')` to the `$return->url` array (line ~178)

#### 2. Replace WebSocket with poller in `system_killboard.js`

All changes in `pathfinder/js/app/ui/module/system_killboard.js`.

- [ ] **Add static properties** (near line 808): `pollTimer = null`, `pollSequenceId = null`, `pollActive = false`

- [ ] **Delete `initWebSocket()`** (lines 749–784) and replace with `initPoller()`:
  - Set `wsStatus = 1` (connecting), notify subscribers
  - `fetch(Init.url.zKillboardR2z2 + '/sequence.json')` to get current head
  - On success: set `pollSequenceId`, set `wsStatus = 2` (connected), start `pollNext()`
  - On failure: set `wsStatus = 3` (error), retry after 30s

- [ ] **Add `pollNext()`** — the main polling loop:
  - Guard: if `!pollActive` or no subscribers → `stopPoller()`
  - Guard: if `document.hidden` → register one-shot `visibilitychange` listener to resume, return
  - `fetch(Init.url.zKillboardR2z2 + '/' + sequenceId + '.json')`
  - **404**: wait 6s, retry same sequence (caught up, waiting for new kills)
  - **200**: adapt response format, call `cacheWsResponse()` + broadcast to subscribers via `onWsMessage()`, bump sequence, schedule next poll in 100ms
  - **Error/timeout**: set `wsStatus = 3`, back off 10s, retry
  - **Stale detection**: after 5 consecutive 404s on the same sequence (30s), re-fetch `sequence.json` to resync in case of sequence gaps

- [ ] **Add `stopPoller()`**: clear `pollTimer`, set `pollActive = false`, set `wsStatus = 4` (closed), notify subscribers

- [ ] **Add `adaptR2z2Response(r2z2Data)`**: `return Object.assign({}, r2z2Data.esi, {zkb: r2z2Data.zkb})` — flattens to old WebSocket format

- [ ] **Update `render()`** (line 161): `initWebSocket()` → `initPoller()`

- [ ] **Update `unsubscribeFromWS()`** (line 729): after filtering, if no subscribers remain and `pollActive`, call `stopPoller()`

- [ ] **Remove stale references**: delete `SystemKillboardModule.ws` usage (only existed inside the deleted `initWebSocket`)

#### 3. Unchanged code (no modifications needed)

- `updateWsStatus()` — already handles states 1–4 with correct labels/colors
- `cacheWsResponse()` — works unchanged after `adaptR2z2Response()` flattens the data
- `onWsMessage()` / `filterKillmailByStreams()` — unchanged, still filters on `killmailData.solar_system_id`
- `getSystemKillsData()` — still uses the working REST API (`Init.url.zKillboard`)
- `subscribeToWS()` — unchanged
- `system_intel.js` — only has static zkillboard.com links, no WebSocket usage

#### 4. Build and commit

- [ ] `cd pathfinder && npm run gulp production`
- [ ] Commit in `pathfinder` submodule
- [ ] Update submodule pointer in `pathfinder-containers`

### Risks to verify

- **CORS**: R2Z2 must serve `Access-Control-Allow-Origin` headers for browser `fetch()`. Test from browser console: `fetch('https://r2z2.zkillboard.com/ephemeral/sequence.json').then(r => r.json()).then(console.log)`. If blocked, would need a backend proxy.
- **Sequence gaps**: R2Z2 sequences may not be strictly contiguous. The stale detection (re-fetch `sequence.json` after 5× 404) handles this.
- **Catch-up after long background**: If tab hidden for hours, sequence could be thousands behind. Add a max catch-up window — if more than 500 behind current head, skip to head rather than replaying.

- **pf-socket Ratchet dynamic property deprecations**: ✅ Warnings suppressed — `cmd.php` debug level 2 now masks `E_DEPRECATED`. Underlying issue remains: `cboden/ratchet` v0.4.3 creates dynamic properties on `React\Socket\Connection`, which will be fatal in PHP 9. Fix requires upgrading Ratchet or patching with `#[\AllowDynamicProperties]`.

- **CA Certs updated in containers** as per https://github.com/goryn-clade/pathfinder/issues/178, let's check CA certs are updated when containers are built