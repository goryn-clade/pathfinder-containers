# TODO


## Readme updates 
- Review Claude changes to Readmes


## Known bugs / deferred fixes

- ~~**Map deletion tab doesn't disappear immediately**~~ Fixed: `updateCurrentMapData` no longer re-inserts a recently-deleted map (WS/poll race guard added in `util.js`).

- **f3-cortex: adopt tagged release**: `ikkez/f3-cortex` is pinned to `dev-master#47d2596` (2025-07-08) because three PHP 8.2 type-hint fixes landed after the `v1.7.8` tag. Once a `v1.7.9`+ tag is published, switch `composer.json` to `"1.7.*"` and drop the commit hash.


