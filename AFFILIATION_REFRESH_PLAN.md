# Implementation Plan: Per-Login Affiliation Refresh

A handoff plan for Sonnet. Repo paths assume the user's checkout layout. The two repos involved are `pathfinder_esi` (ESI client library) and `pathfinder-containers/pathfinder` (the app submodule).

---

## Step 1 — Add ESI client wrapper for affiliation

**Repo:** `~/development/pathfinder/pathfinder_esi`
**File:** `app/Client/Ccp/Esi/Esi.php`

Add a new protected request method right after the existing `getCharacterAffiliationRequest()` (around line 109):

```php
/**
 * Modern affiliation endpoint - per developers.eveonline.com docs.
 * Public endpoint, no auth required.
 * @param array $characterIds
 * @return RequestConfig
 */
protected function getCharactersAffiliationRequest(array $characterIds) : RequestConfig {
    return new RequestConfig(
        WebClient::newRequest('POST', $this->getEndpointURI(['characters', 'affiliation', 'POST'])),
        $this->getRequestOptions('', $characterIds),
        function($body) : array {
            $affiliationData = [];
            if(!(is_object($body) && ($body->error ?? null))){
                foreach((array)$body as $entry){
                    $affiliationData[] = (new Mapper\Character\Affiliation($entry))->getData();
                }
            }
            return $affiliationData;
        }
    );
}
```

The existing `Mapper\Character\Affiliation` already maps `character_id`, `corporation_id`, `alliance_id`. Reuse it as-is.

**Verify** the endpoint path constant — check what `getEndpointURI(['characters', 'affiliation', 'POST'])` resolves to. Look at how the existing `getCharacterAffiliationRequest` resolves its URI; if the new endpoint has a different path key in the routing config, add it there. Search for `'characters' =>` and `'affiliation'` in the endpoint config files in `pathfinder_esi/app/`.

The callable name to use from the app side is `getCharactersAffiliation` (note the plural).

---

## Step 2 — Add `affiliationUpdated` column to CharacterModel

**Repo:** `~/development/pathfinder/pathfinder-containers/pathfinder`
**File:** `app/Model/Pathfinder/CharacterModel.php`

In the `$fieldConf` array (around the existing timestamp fields like `lastLogin` near line 82), add:

```php
'affiliationUpdated' => [
    'type' => Schema::DT_TIMESTAMP,
    'index' => true,
    'default' => null,
    'nullable' => true
],
```

Match the exact format of neighboring nullable timestamp fields — copy their structure to avoid schema-build drift. Do not write a migration script; the user runs migrations from the admin page.

---

## Step 3 — Add `updateAffiliation()` method to CharacterModel

**File:** `app/Model/Pathfinder/CharacterModel.php`

Add a new public method, placed near `updateFromESI()` (around line 1179). The method must:

1. **TTL gate (1 hour):** if `$this->affiliationUpdated` is within 3600 seconds of "now", return `true` immediately (no ESI call, no save).
2. Call `self::getF3()->ccpClient()->send('getCharactersAffiliation', [[$this->_id]])` (note: the wrapper takes an array; we send a single-element array).
3. If response is empty or `count !== 1`, log via `Sso::getSSOLogger()` and return `false`.
4. Extract `corporation_id` and `alliance_id` (alliance may be missing — handle null).
5. **Resolve corp:** instantiate `CorporationModel::getNew('CorporationModel')`, call `getById($newCorpId, 0)`. If the corp model isn't valid AFTER its own ESI fetch, log and return `false` — don't proceed to set the FK on the character (this prevents the silent FK-failure bug we diagnosed).
6. **Resolve alliance** the same way if `alliance_id` is set; null otherwise.
7. Assign `$this->corporationId`, `$this->allianceId`, and `$this->affiliationUpdated = self::getF3()->get('getTimeFromTimestamp')(time())` (or whatever the project's existing timestamp idiom is — look at how `lastLogin` is set in `User.php::loginByCharacter()` around line 89, which uses `touch('lastLogin')`).
8. Call `$this->save()`. The existing `AbstractModel::save()` swallows `DatabaseException` — that's pre-existing behavior. Log explicitly here on failure so we have visibility.
9. Return `true` on success, `false` on any error.

**Important:** the save in this method only updates the character row. The corp/alliance rows are saved separately by their own `getById()` calls. If the corp save inside `CorporationModel::getById()` fails, the character FK assignment must NOT happen — that's what step 5 guards against.

Use `Sso::getSSOLogger()->write(...)` for failure logs. Format: `'updateAffiliation failed for character %d: %s'`.

---

## Step 4 — Wire into SSO callback (full SSO login)

**File:** `app/Controller/Ccp/Sso.php`
**Method:** `callbackAuthorization()` (line 166)

After `$characterModel = $this->updateCharacter($characterData);` (line 215) and **before** the `isAuthorized()` check at line 219, add:

```php
$characterModel->updateAffiliation();
```

Do NOT check the return value — per requirement, ESI failures should not block login. Stale affiliation is acceptable; the user just gets the previous corp/alliance for this session.

**Also:** since `updateAffiliation()` now owns corp/alliance assignment, remove the corp/alliance assignment lines from `updateCharacter()` (lines 563–564). Keep the affiliation lookup OUT of `getCharacterData()` — that method should now only fetch basic character data via `getCharacter`. Remove the `getCharacterAffiliation` block (lines 511–537) from `getCharacterData()`. The `$characterData->corporation` and `$characterData->alliance` properties become unused — remove them too.

**Remove the `[DEBUG corp]` logging** added in the previous session (lines 512, 524, 568–571 of `Sso.php`).

---

## Step 5 — Wire into character switch on map

**File:** `app/Controller/Ccp/Sso.php`
**Method:** `requestAuthorization()` (line 69)

After the existing `$updateStatus = $character->updateFromESI();` (line 94), add:

```php
$character->updateAffiliation();
```

Before the `$character->isAuthorized()` check at line 104. Don't gate on the return value.

---

## Step 6 — Wire into cookie login

**File:** `app/Controller/Controller.php`
**Method:** `getCookieCharacters()` (around line 341)

After `$updateStatus = $characterAuth->characterId->updateFromESI();` (line 341) and **before** the `if($character->hasUserCharacter())` check (which leads to authorization), add:

```php
$characterAuth->characterId->updateAffiliation();
```

Same pattern — fire-and-forget, don't block login on ESI failures.

---

## Step 7 — Verification

1. Build and start the container per the project's standard workflow (the user handles builds — do not run docker commands).
2. User runs the migration from the admin page to create the `affiliationUpdated` column.
3. User performs a clean SSO login for character `525344969` (the test case from this session — currently has stale `corporationId = 98675758`, ESI says it should be `220243444`).
4. Verify in DB:
   ```sql
   SELECT id, corporationId, allianceId, affiliationUpdated FROM `character` WHERE id = 525344969;
   SELECT id, name FROM corporation WHERE id = 220243444;
   ```
   - `corporationId` should now be `220243444`
   - `allianceId` should be `551692893`
   - `affiliationUpdated` should be the login time
   - The new corp row should exist
5. Check `sso.log` for any `updateAffiliation failed` entries.
6. Log out, wait < 1 hour, log back in via cookie. Confirm in `sso.log` that the TTL gate kicked in (no second ESI call within the hour). The user may need to add a temporary log line in step 3 above the TTL early-return to confirm this — leave a TODO for that or skip if low-value.

---

## Files touched (summary)

| Repo | File | Change |
|------|------|--------|
| pathfinder_esi | `app/Client/Ccp/Esi/Esi.php` | Add `getCharactersAffiliationRequest()` |
| pathfinder | `app/Model/Pathfinder/CharacterModel.php` | Add `affiliationUpdated` field + `updateAffiliation()` method |
| pathfinder | `app/Controller/Ccp/Sso.php` | Wire into SSO callback, character switch; remove debug logs; remove affiliation logic from `getCharacterData()`/`updateCharacter()` |
| pathfinder | `app/Controller/Controller.php` | Wire into cookie login |

## Out of scope

- No locking around concurrent affiliation refreshes (per requirement #3).
- No migration script (per requirement #1 — user runs migrations from admin page).
- Don't touch `updateFromESI()` (per requirement #2 — keeps the 24h-cached `getCharacter` call separate).
- Don't run gulp/docker builds (per project memory).
