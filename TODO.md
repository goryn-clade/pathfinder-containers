# TODO


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