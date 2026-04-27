# TODO


## Readme updates 
- Review Claude changes to Readmes

## New EOL states

A connection can currently be marked as "end of life" ("eol"). 

Previously this state was reached when a wormhole had less than 4 hours left of its natural lifespan
This has now been changed in the game, and the real behaviour is now has these states: 
Healthy: >4h left
End of life (EOL1) 1>4h left
End of life (EOl2) 0>1h left
Zombie (EOL3) <0h left (can life up to 20% of the wormhole lifespan past the natural end time. so a 12h lifespan wormhole can live 720 minutes + up to 144 minutes "bonus" time)

## Known bugs / deferred fixes

- **Map deletion tab doesn't disappear immediately**: After a successful DELETE, `deleteCurrentMapData` + `updateMapModule` is called but the tab may not be removed. Attempted fix (changing `!== false` to truthy check in `module_map.js:1422`) needs verification.

- **f3-cortex: adopt tagged release**: `ikkez/f3-cortex` is pinned to `dev-master#47d2596` (2025-07-08) because three PHP 8.2 type-hint fixes landed after the `v1.7.8` tag. Once a `v1.7.9`+ tag is published, switch `composer.json` to `"1.7.*"` and drop the commit hash.

- **R2Z2 poll error** `TypeError: NetworkError` thrown on every page load from `pollNext()` in `system_killboard.js`. `initPoller` (sequence endpoint) succeeds but the subsequent `/api/Killboard/r2z2/{seqId}` fetch rejects at network level rather than returning an HTTP error. See `r2z2debug.md` for full analysis. Needs a HAR to see: (1) whether the request gets a status code at all, (2) the actual body of `/api/Killboard/sequence` to confirm the `sequence` key/type.

- **Consolidate DB seed SQL into a single file**: The `eve_universe.sql` base dump must be supplemented by several patch files that are never auto-imported: `zarzakh.sql` (Zarzakh system 30100000 + region/constellation/stargates), `pochven_and_trailblazer.sql`, `wormhole_lifespan_fix.sql`, and `new_wormholes.sql` (Pochven WH dogma attributes, private fork only). Either merge all patches into a single canonical `eve_universe.sql` and update the zip, or mount and auto-run each patch in `compose.yml` after the base import. `eve_universe.sql.zip` is also stale relative to the unzipped file and should be regenerated.

