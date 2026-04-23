# TODO


## Readme updates 
- Review Claude changes to Readmes


## Known bugs / deferred fixes

- **Map deletion tab doesn't disappear immediately**: After a successful DELETE, `deleteCurrentMapData` + `updateMapModule` is called but the tab may not be removed. Attempted fix (changing `!== false` to truthy check in `module_map.js:1422`) needs verification.

- **f3-cortex: adopt tagged release**: `ikkez/f3-cortex` is pinned to `dev-master#47d2596` (2025-07-08) because three PHP 8.2 type-hint fixes landed after the `v1.7.8` tag. Once a `v1.7.9`+ tag is published, switch `composer.json` to `"1.7.*"` and drop the commit hash.

- **pasting multiple structures issue**: all structures are saved correctly by the POST, but `updateUserData` returns only the last one. Root cause suspected: `AbstractPathfinderModel::reset()` not calling `parent::reset()` means the Registry-cached `corporationStructures` rel singleton keeps its `_id` across loop iterations in `saveStructure()`, so iterations 2 and 3 do UPDATE instead of INSERT on the junction table. Fix committed (28fc1f1f) but unverified. Debug logging added (4a198be3) — check `STRUCT-DEBUG` lines in docker logs after a paste.

- **R2Z2 poll error** `TypeError: NetworkError` thrown on every page load from `pollNext()` in `system_killboard.js`. `initPoller` (sequence endpoint) succeeds but the subsequent `/api/Killboard/r2z2/{seqId}` fetch rejects at network level rather than returning an HTTP error. See `r2z2debug.md` for full analysis. Needs a HAR to see: (1) whether the request gets a status code at all, (2) the actual body of `/api/Killboard/sequence` to confirm the `sequence` key/type.

- **vulnerability in firebase/php-jwt**: CVE-2025-45769 (low severity, weak encryption). Currently on v6.11.1 (`^6` in composer.json). Upgrade to v7.x is possible — `composer require firebase/php-jwt:^7 goryn-clade/pathfinder_esi:3.0.13 --dry-run` resolves cleanly to v7.0.5. Only usage is `JWT::decode()` and `JWK::parseKeySet()` in `Sso.php` — the v6→v7 API diff shows no breaking changes to those two calls (only stricter `iat`/`nbf`/`exp` numeric validation and an RSA key minimum length of 2048-bit added). Safe to upgrade when convenient.
