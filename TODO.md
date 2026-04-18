# TODO


## Feature: Update Static Data

Verified against SDE 2025-07-07 TRANQUILITY (in-container SQL diff, April 2026):

- ✅ **M001/L005 lifespan** — Fixed. Was 960 min (16h), SDE says 270 min (4.5h). Applied via `export/sql/wormhole_lifespan_fix.sql`. Closes [#186](https://github.com/goryn-clade/pathfinder/issues/186).
- ✅ **New ships** — None missing. eve_universe already has all published ship types from SDE.
- ✅ **New wormhole types** — None missing.
- ✅ **J377** — Does not exist in SDE. Not a real system; remove from tracking.
- **Drifter WH renames** — SDE still uses J-codes for 31000000-range systems (e.g. 31000004 = J200727, not "Conflux Eyrie"). These names are not in the official SDE and require a manual check against anoik.is or dotlan. See [#177](https://github.com/goryn-clade/pathfinder/issues/177).
- **Drifter WH statics** — `export/csv/system_static.csv` is sourced from anoik.is, not the SDE. Requires manual update from anoik.is. See [#177](https://github.com/goryn-clade/pathfinder/issues/177).

## Readme updates 
- Review Claude changes to Readmes

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
