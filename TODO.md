# TODO

## Enhancement: Move deployment-specific config from pathfinder.ini to .env

`config/pathfinder/pathfinder.ini` currently holds deployment-specific values that have to be hand-edited per installation and are easy to accidentally commit to the repo (the SUPER admin character ID already is). These should be driven by `.env` so operators only need to edit one file.

### Approach

Convert `config/pathfinder/pathfinder.ini` to a template (`pathfinder.ini.template`) and run `envsubst` in the container entrypoint (same pattern nginx already uses). The template ships with `${VAR}` placeholders; at startup the real values are substituted from env. The `.ini.template` is committed; the rendered `.ini` is gitignored.

### Variables to extract

| .env key | pathfinder.ini location | Notes |
|---|---|---|
| `PF_SUPER_ADMIN_ID` | `[PATHFINDER.ROLES] CHARACTER.0.ID` | CCP character ID of SUPER admin — currently committed in plain text |
| `PF_INSTALL_NAME` | `[PATHFINDER] NAME` | e.g. `"Goryn Clade Pathfinder"` |
| `PF_LOGIN_WHITELIST_CORP` | `[PATHFINDER.LOGIN] CORPORATION` | Comma-separated corp IDs |
| `PF_LOGIN_WHITELIST_ALLIANCE` | `[PATHFINDER.LOGIN] ALLIANCE` | Comma-separated alliance IDs |
| `PF_LOGIN_WHITELIST_CHAR` | `[PATHFINDER.LOGIN] CHARACTER` | Comma-separated character IDs |
| `PF_REGISTRATION_STATUS` | `[PATHFINDER.REGISTRATION] STATUS` | `0` or `1` |

### Files to change

- `config/pathfinder/pathfinder.ini` → rename to `pathfinder.ini.template`, replace values with `${...}` placeholders
- `.env.example` → add the new keys with empty/example defaults
- Container entrypoint or `Dockerfile` → add `envsubst < pathfinder.ini.template > pathfinder.ini` step before app starts
- `.gitignore` → add `config/pathfinder/pathfinder.ini` (rendered output)

### Out of scope

Static tuning values (timers, cache TTLs, map limits, API URLs) stay in the ini — they're not secrets and don't change between deployments.

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
## Enhancement: maintainer info
Check the claude generated text on /login

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

---


## Known bugs / deferred fixes

- **Map deletion tab doesn't disappear immediately**: After a successful DELETE, `deleteCurrentMapData` + `updateMapModule` is called but the tab may not be removed. Attempted fix (changing `!== false` to truthy check in `module_map.js:1422`) needs verification.

- **f3-cortex: adopt tagged release**: `ikkez/f3-cortex` is pinned to `dev-master#47d2596` (2025-07-08) because three PHP 8.2 type-hint fixes landed after the `v1.7.8` tag. Once a `v1.7.9`+ tag is published, switch `composer.json` to `"1.7.*"` and drop the commit hash.

- **Audit Discord and Slack webhook integrations**: Verify both integrations still work with current platform APIs. Discord has deprecated legacy webhook formats in favour of structured embeds; Slack has migrated from incoming webhooks to Block Kit. Check `app/Model/Pathfinder/MapModel.php` (`getDiscordWebHookConfig`, `getSlackWebHookConfig`) and any classes that send webhook payloads.


### Risks to verify

- **CORS**: R2Z2 must serve `Access-Control-Allow-Origin` headers for browser `fetch()`. Test from browser console: `fetch('https://r2z2.zkillboard.com/ephemeral/sequence.json').then(r => r.json()).then(console.log)`. If blocked, would need a backend proxy.
- **Sequence gaps**: R2Z2 sequences may not be strictly contiguous. The stale detection (re-fetch `sequence.json` after 5× 404) handles this.
- **Catch-up after long background**: If tab hidden for hours, sequence could be thousands behind. Add a max catch-up window — if more than 500 behind current head, skip to head rather than replaying.

- **pf-socket Ratchet dynamic property deprecations**: ✅ Warnings suppressed — `cmd.php` debug level 2 now masks `E_DEPRECATED`. Underlying issue remains: `cboden/ratchet` v0.4.3 creates dynamic properties on `React\Socket\Connection`, which will be fatal in PHP 9. Fix requires upgrading Ratchet or patching with `#[\AllowDynamicProperties]`.

- **CA Certs updated in containers** as per https://github.com/goryn-clade/pathfinder/issues/178, let's check CA certs are updated when containers are built
