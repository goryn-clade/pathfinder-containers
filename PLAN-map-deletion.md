# Map deletion tab persistence — debug & fix plan

## Context

After a successful map DELETE, the tab sometimes does not disappear immediately. A prior fix changed `if(tabMapData !== false)` to `if(tabMapData)` in `js/app/module_map.js` (commit `31cde30e`), and added a local `deleteCurrentMapData` + `updateMapModule` call in `js/app/ui/dialog/map_settings.js` so the deleter gets immediate feedback rather than waiting for the `mapDeleted` WS round-trip.

That fix is logically correct (see analysis below) but the symptom may persist due to a **race with `mapUpdate` WebSocket messages** that re-add the just-deleted map to the local cache.

## Why the existing fix is correct

`Util.getCurrentMapData(mapId)` at [pathfinder/js/app/util.js:2886-2899](pathfinder/js/app/util.js#L2886-L2899) returns the result of `Array.find()`, which is `undefined` when nothing matches — **not `false`**.

- Old check: `if(tabMapData !== false)` → `undefined !== false` is **true** → tab kept, `deleteTab()` never queued.
- New check: `if(tabMapData)` → `undefined` is falsy → goes to `else`, `deleteTab()` runs.

So this fix is right and needed. Don't revert it.

## Likely remaining root cause: WS race re-adds the map

Two independent code paths can re-populate `Init.currentMapData` with the just-deleted map id, after the local `deleteCurrentMapData` has run:

1. **In-flight `mapUpdate` WS message** at [pathfinder/js/app/mappage.js:234-237](pathfinder/js/app/mappage.js#L234-L237) calls `Util.updateCurrentMapData(...)`. That function at [pathfinder/js/app/util.js:2914-2924](pathfinder/js/app/util.js#L2914-L2924) **pushes the map back in** if its index isn't found — exactly the case after `deleteCurrentMapData()` removed it.

2. **Periodic poll refresh** at [pathfinder/js/app/mappage.js:405-408](pathfinder/js/app/mappage.js#L405-L408) calls `Util.setCurrentMapData(data.mapData)`, which overwrites the local cache wholesale. If the next poll fires before the server has finished processing the DELETE (or before its cache invalidates), the deleted map comes back.

The `mapDeleted` WS message at [pathfinder/js/app/mappage.js:239-241](pathfinder/js/app/mappage.js#L239-L241) is what's *supposed* to tell all clients to drop the map, but it doesn't help if a `mapUpdate` for the same id arrives first or a poll re-fetches it.

## Debug steps (cheapest first)

### Step 1 — Reproduce with devtools open

In the browser, before deleting:
- Add `Init.currentMapData` to a watch expression.
- Set an XHR breakpoint on `Map/{id}` DELETE.
- Add temporary `console.log`s (keyed on the deleted mapId) inside:
  - `updateCurrentMapData` in `pathfinder/js/app/util.js`
  - `setCurrentMapData` in `pathfinder/js/app/util.js`
  - the `else` branch at [pathfinder/js/app/module_map.js:1439-1442](pathfinder/js/app/module_map.js#L1439-L1442) (the deleteTab queue)
  - inside `deleteTab` itself at [pathfinder/js/app/module_map.js:1240](pathfinder/js/app/module_map.js#L1240)

Delete a map and capture the order of events:
- `DELETE 200 OK` → local `deleteCurrentMapData` → `updateMapModule` `else` branch fires → `deleteTab` runs → **then** any subsequent `updateCurrentMapData`/`setCurrentMapData` re-adding the id?

If you see a re-add after `deleteTab`, that confirms the WS/poll race.

### Step 2 — Verify the served bundle has the fix

Source: `pathfinder/js/app/module_map.js` (mtime `Apr 18 17:05`).
Bundle: `pathfinder/public/js/v3.0.0/app/mappage.js` (mtime `May 2 14:38`).

Bundle is newer, so the fix should be in. If `deleteTab` never logs in Step 1, the bundle may be stale — rebuild via the normal gulp/docker workflow.

### Step 3 — Decide between the two fixes below

If the race is confirmed in Step 1, apply **Fix A** first. It's a one-line semantic change.

## Proposed fixes

### Fix A — `updateCurrentMapData` should update, not insert

In [pathfinder/js/app/util.js:2914-2924](pathfinder/js/app/util.js#L2914-L2924):

```js
let updateCurrentMapData = mapData => {
    let mapDataIndex = getCurrentMapDataIndex(mapData.config.id);

    if(mapDataIndex !== false){
        Init.currentMapData[mapDataIndex].config = mapData.config;
        Init.currentMapData[mapDataIndex].data = mapData.data;
    }
    // else: map not in local cache — do not resurrect.
    // New maps should arrive via setCurrentMapData (full refresh) or an
    // explicit "map added" path, not implicitly via an update message.
};
```

**Risk:** if the codebase relies on `updateCurrentMapData` to introduce *new* maps (e.g. when another user shares a map with the current user), Fix A breaks that flow. Search for callers and trace what kinds of `mapData` they pass:

```bash
grep -rn "updateCurrentMapData" pathfinder/js
```

If callers genuinely use it as upsert for new maps, prefer Fix B.

### Fix B — Recently-deleted id guard (belt & braces)

Maintain a short-lived set of recently-deleted map ids (e.g. 5s TTL) in `util.js`. Have `updateCurrentMapData` and `setCurrentMapData` skip ids in that set.

Sketch:

```js
let recentlyDeletedMapIds = new Map(); // id -> expiresAt
const RECENTLY_DELETED_TTL_MS = 5000;

let isRecentlyDeleted = mapId => {
    let expiresAt = recentlyDeletedMapIds.get(mapId);
    if(expiresAt && expiresAt > Date.now()) return true;
    if(expiresAt) recentlyDeletedMapIds.delete(mapId);
    return false;
};

let deleteCurrentMapData = mapId => {
    Init.currentMapData = Init.currentMapData.filter(m => m.config.id !== mapId);
    recentlyDeletedMapIds.set(mapId, Date.now() + RECENTLY_DELETED_TTL_MS);
};
```

Then guard inside `updateCurrentMapData` and inside `setCurrentMapData` (filter the incoming array against `isRecentlyDeleted`).

**Risk:** TTL is a guess; if the server takes longer than 5s to stop emitting updates for the deleted map, the tab can re-appear. Increase TTL or invalidate the entry on `mapDeleted` WS confirmation.

## Recommended order

1. Step 1 (reproduce + log) — confirm root cause before touching code.
2. Step 2 (verify bundle).
3. Apply Fix A. Test by deleting a map; tab should disappear and stay gone.
4. If Fix A breaks the "new map shared with me" flow, revert and apply Fix B.
5. Update `pathfinder-containers/TODO.md`: remove the bullet under "Known bugs / deferred fixes" once verified, and note in `pathfinder-containers/changelog.md`.

## Files to touch

- `pathfinder/js/app/util.js` — Fix A (1 line) or Fix B (~20 lines).
- After fix lands, rebuild the JS bundle (gulp) and the dev container so `public/js/v3.0.0/app/mappage.js` picks up the change.

## Out of scope

- Don't refactor `getCurrentMapData` to return `null` instead of `false` even though that would have prevented the original bug — too invasive, too many call sites.
- Don't touch the `tabMapData` truthy check at [pathfinder/js/app/module_map.js:1431](pathfinder/js/app/module_map.js#L1431). It's correct.
