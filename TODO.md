# TODO


## Readme updates 
- Review Claude changes to Readmes


## Known bugs / deferred fixes

- **Map deletion tab doesn't disappear immediately**: After a successful DELETE, `deleteCurrentMapData` + `updateMapModule` is called but the tab may not be removed. Attempted fix (changing `!== false` to truthy check in `module_map.js:1422`) needs verification.

- **f3-cortex: adopt tagged release**: `ikkez/f3-cortex` is pinned to `dev-master#47d2596` (2025-07-08) because three PHP 8.2 type-hint fixes landed after the `v1.7.8` tag. Once a `v1.7.9`+ tag is published, switch `composer.json` to `"1.7.*"` and drop the commit hash.

- **pasting multiple structures issue** when pasting multiple structures from dscan at once, it initially adds all the structures, then slowly deletes them one by one. this should be investigated. 

- **fatal logout** inactive web sessions eventually result in a 504, we should check why this happens and try to handle it gracefully. a "timed-out" message is preferable to just a generic 5xx.
