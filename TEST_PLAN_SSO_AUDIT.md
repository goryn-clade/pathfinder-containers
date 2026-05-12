# SSO audit — test plan

Companion to [PLAN_SSO_AUDIT.md](PLAN_SSO_AUDIT.md). Every commit in the
audit changes the SSO / cookie auth path; this doc is the regression net
that has to be green before the branch ships.

The single highest-priority test is **§F1/F2 — JWT issuer & audience
verification**: that change is the most likely cause of the current login
failure (`Character verification failed by CCP SSO` after CCP callback).
Start there.

> **Heads-up — typo in upstream:** the error string in `Sso.php:53` reads
> `'Character verification failed by SSP SSO'`. "SSP" is a typo for "CCP"
> (CCP Games, EVE Online's publisher). Not security-related; worth fixing
> while in the file but doesn't change any behaviour.

---

## Common setup

All runtime tests assume the dev stack is up and pointed at Sisi
(`CCP_ESI_DATASOURCE = singularity`), with a working CCP application
configured on `developers.eveonline.com` for the test client:

```bash
docker compose -f compose.dev.yml build --no-cache pf
docker compose -f compose.dev.yml up -d --force-recreate pf
docker logs -f pathfinder            # in one pane
docker exec pathfinder tail -f /var/www/html/pathfinder/logs/SSO.log
```

`.env` must include:
- `TOKEN_ENCRYPTION_KEY` (64-hex). Generate: `openssl rand -hex 32`.
- A real `CCP_SSO_CLIENT_ID` + `CCP_SSO_SECRET_KEY` from a Sisi app
  registered with redirect URI matching `URL + /sso/callbackAuthorization`.
- `PF_DEBUG=3` for the test window only — gives F3 stack traces in
  responses so you can read failures inline.

Browser test rig:
- Two browsers (or browser + incognito) for multi-character / cookie tests.
- DevTools open with **Network → Preserve log** on for redirect chains.

---

## 1. F1 + F2 — JWT issuer & audience verification *(start here)*

**What changed.** `verifyJwtAccessToken()`
[Sso.php:519-565](pathfinder/app/Controller/Ccp/Sso.php#L519-L565) now
**throws** on issuer mismatch (was a log-only no-op) and now performs an
`aud`/`azp` check (was missing).

### Suspected current breakage

After CCP callback, `verifyCharacterData()` returns null →
`callbackAuthorization()` sets `SESSION.SSO.ERROR` to "Character
verification failed by CCP SSO" and reroutes. The underlying exception is
swallowed by `verifyCharacterData()` and *only* surfaced in
`logs/SSO.log`. Read that file first:

```bash
docker exec pathfinder tail -50 /var/www/html/pathfinder/logs/SSO.log
```

Look for `Unable to verify character data. JWT issuer mismatch` or
`JWT audience mismatch`.

**Most likely cause — issuer scheme mismatch.** `CCP_SSO_JWK_CLAIM` in
both `environment.ini` blocks is `login.eveonline.com`, but CCP v2 SSO
sets `iss = "https://login.eveonline.com"` in the JWT. `hash_equals`
requires exact match.

**Diagnostic (temporary, do NOT commit):** drop a log line into
`verifyJwtAccessToken()` just after `JWT::decode()` to dump the actual
claim:

```php
self::getSSOLogger()->write(sprintf('JWT claims iss=[%s] aud=[%s] azp=[%s] expected_claim=[%s] expected_aud=[%s]',
    (string)$decodedJwt->iss,
    is_array($decodedJwt->aud ?? null) ? implode(',', $decodedJwt->aud) : (string)($decodedJwt->aud ?? ''),
    (string)($decodedJwt->azp ?? ''),
    static::getSsoJwkClaim(),
    (string)Controller\Controller::getEnvironmentData('CCP_SSO_CLIENT_ID')
));
```

Trigger one login, read the line, then revert.

**Likely fix paths once confirmed:**
- Change `CCP_SSO_JWK_CLAIM` env to `https://login.eveonline.com`, OR
- Normalize in code: strip `https?://` from `$decodedJwt->iss` before
  `hash_equals` (allows either form regardless of CCP's future change), OR
- Accept either: `hash_equals($expected, $iss) || hash_equals('https://' . $expected, $iss)`.

### Pass criteria

- Fresh Sisi login completes; no `JWT issuer mismatch` line in `SSO.log`.
- Log a deliberately-misconfigured `CCP_SSO_CLIENT_ID` (`xxx`) and retry;
  `SSO.log` shows `JWT audience mismatch` and the user sees the "verification
  failed" error page — proves the check is now real, not a no-op.
- Restore the correct client id; login succeeds again.

### Nested-flow sanity (`callbackAuthorization()` line-by-line)

The callback at [Sso.php:205-351](pathfinder/app/Controller/Ccp/Sso.php#L205)
has six nested gates. Walk a single successful login through each and
confirm the SSO log is clean at every level:

| Line | Condition | If fails, you see |
|------|-----------|-------------------|
| 220 | `$stateMap` non-empty | redirects silently, no error in `SSO.log` — F6 state map missing or evicted |
| 222–226 | `code` + `state` present, state in map | `SESSION.SSO.ERROR = "Invalid response"` |
| 244 | `accessData` has `accessToken` + `expires` + `refreshToken` | `Service timeout (4s)` — token endpoint POST failed; check `requestAccessData` log line for `grant_type=[authorization_code]` |
| 249 | `verifyCharacterData()` returns non-null | **current failure** — `Character verification failed by CCP SSO` |
| 258 | `getCharacterData()` returns non-empty | `Failed to load characterData from ESI` — ESI down or scope missing |
| 269 | `updateCharacter()` returns non-null | silent fail; means copyfrom blew up — check `logs/error.log` |
| 274 | `isAuthorized() === 'OK'` | `Character "X" is not authorized to log in. Reason: …` |
| 309 | `loginByCharacter()` returns true | `Failed authentication due to technical problems: <name>` |

For the current breakage (line 249), only F1/F2 is in play.

---

## 2. F3 — refresh-token log redaction

**What changed.** `requestAccessData()` failure path runs
`$requestParams` through `redactSecrets()` before `print_r` logging.

### Tests

1. Force a token-endpoint failure (block egress to `login.eveonline.com`
   for 30s, or use a stale refresh token):
   ```bash
   docker exec pathfinder sh -c 'iptables -A OUTPUT -d login.eveonline.com -j REJECT' 2>/dev/null || \
     echo "no iptables in image — fake the failure by deleting CCP_SSO_SECRET_KEY temporarily"
   ```
2. Trigger a login or a token refresh (open an existing map while access
   token is close to expiry).
3. `grep -i 'refresh_token\|client_secret\|code=' logs/SSO.log` — should
   return zero matches that contain real values; should return matches
   showing `[REDACTED]`.

### Pass criteria

- `SSO.log` shows `[refresh_token] => [REDACTED]`, `[code] => [REDACTED]`,
  `[client_secret] => [REDACTED]` on the failure line.
- `grant_type=[refresh_token]` or `grant_type=[authorization_code]` tag
  is present (A3 instrumentation).

---

## 3. F4 — CSPRNG replacement

**What changed.** `openssl_random_pseudo_bytes` → `random_bytes` in three
sites; SSO `state` bumped from 12 → 32 bytes.

### Tests

1. Static check:
   ```bash
   grep -rn 'openssl_random_pseudo_bytes' pathfinder/app/
   # → no results
   ```
2. Capture the SSO redirect URL during login; confirm `state` param is
   64 hex chars (32 bytes).
3. Inspect a fresh `remember-me` cookie value in DevTools: should be
   `<32 hex>:<32 hex>` — selector and validator each 16 bytes.

---

## 4. F7 — dead-code removal in `getSsoAccessData()`

**What changed.** Unreachable `else` branch deleted.

### Tests

Code review only. Confirm `getSsoAccessData()` body is the one-line
`return $this->verifyAuthorizationCode($authCode, $pkceVerifier);`. No
behavioural change expected.

---

## 5. A1 — JWKS caching

**What changed.** `getCcpJwkData()` reads/writes F3 cache with 1h TTL;
busts on `"kid" invalid` exception.

### Tests

1. Two successive logins. Count CCP JWKS hits:
   ```bash
   docker exec pathfinder grep -c 'getJWKS' /var/www/html/pathfinder/logs/SSO.log
   # before second login vs after: should NOT increment
   ```
2. Bust the cache manually:
   ```bash
   docker exec -it $(docker compose ps -q pf-redis) valkey-cli -a "$REDIS_PASSWORD" \
     KEYS '*sso_jwks_keyset*'
   docker exec -it $(docker compose ps -q pf-redis) valkey-cli -a "$REDIS_PASSWORD" \
     DEL <returned key>
   ```
   Trigger one more login; JWKS should be re-fetched once and the cache
   re-populated.
3. Simulate `kid` rotation: log in, manually edit the cached JWKS in
   Redis to break the `kid`, log in again. Expect: one `kid invalid`
   recovery cycle, cache busted, fresh fetch, login succeeds. Single
   retry — not a loop.

### Pass criteria

- 1 JWKS fetch per ~3600s of login activity, not 1 per login.
- `kid` rotation self-heals without operator action.

---

## 6. A2 — scope minimisation

**What changed.** No-op; all 10 scopes verified in use. See PLAN_SSO_AUDIT
A2 table.

### Tests

Spot-check against `CCP_ESI_SCOPES` in env vs grep results:
```bash
for scope in $(grep '^CCP_ESI_SCOPES' pathfinder/app/environment.ini | head -1 | cut -d= -f2 | tr ',' ' '); do
  fn=$(echo "$scope" | sed -E 's|esi-||; s|\.read_||; s|\.write_||; s|\.v1||; s|\.|_|g')
  echo "=== $scope ==="
  grep -rn "$fn\|$scope" pathfinder/app/ | head -2
done
```

No deletion test needed — nothing changed. Re-run if scopes are added later.

---

## 7. A6 — log/tmp directory perms

**What changed.** `chmod 0755` (was `0766`).

### Tests

```bash
docker exec pathfinder ls -ld /var/www/html/pathfinder/logs /var/www/html/pathfinder/tmp
# Expect drwxr-xr-x  not  drwxrw-rw-
```

Confirm logs still get written:
```bash
docker exec pathfinder ls -la /var/www/html/pathfinder/logs/
# files exist, owned by `nobody`, mode 0644
```

---

## 8. A5 — cookie selector entropy bump

**What changed.** Selector size `random_bytes(12)` → `random_bytes(16)`.

### Tests

After a fresh login, inspect a `char_<md5>` cookie value in DevTools.
- Selector part (before `:`): 32 hex chars.
- Validator part (after `:`): 32 hex chars.

Old cookies from before the bump (24 hex selector) should still
authenticate — verify by manually re-adding such a cookie value if you
have one from an earlier test session. They survive until natural expiry
or rotation.

---

## 9. C1 — cookie rotation on use

**What changed.** Successful login via `getCookieCharacters()` rotates
the validator; selector preserved; original expiry preserved. The
"expired OR mismatch → erase" branch is split so token mismatch on a
non-expired row no longer destroys the legit user's row.

### Tests

1. **Happy-path rotation.**
   - Log in via SSO, then log out (session-only — leave the `char_*` cookie).
   - Copy current cookie value `S1:V1` from DevTools.
   - Click **Login with remembered character**.
   - Confirm: new cookie value is `S1:V2` (selector unchanged,
     validator changed). Reload the map; you're still logged in.

2. **Original expiry preserved.**
   - In DevTools, note the cookie's expiry timestamp before clicking
     "login with remembered character".
   - After rotation, confirm the new cookie's expiry is the **same**
     timestamp, not extended by 30 days.

3. **Stale cookie does NOT erase the DB row (the DoS fix).**
   - In browser A: log in, capture cookie `S1:V1`, log out (session
     only), then "login with remembered character" → cookie becomes
     `S1:V2`. Confirm A can reload the map.
   - In browser B (incognito): manually set the same `char_<md5>` cookie
     to the **old** value `S1:V1`. Click "login with remembered
     character". Browser B should fail and clear its cookie.
   - Switch back to browser A: reload the map. **Must remain logged in.**
     If A is logged out, the stale-cookie DoS regression has crept back.

4. **Genuine expiry still erases.**
   - Manually set a `character_authentication` row's `expires` to the
     past:
     ```sql
     UPDATE character_authentication
       SET expires = '2000-01-01 00:00:00'
       WHERE characterId = <your test character>;
     ```
   - Use the remembered-cookie login. Expect: cookie cleared, DB row
     erased, redirected to SSO.

5. **Preview endpoint does not rotate** (`$rotate = false`).
   - Open the login page; the JS preview pings
     `/api/User/getCookieCharacter` with the cookie. Confirm cookie value
     in DevTools is unchanged after that request.

---

## 10. A3 — refresh-token persistence + grant_type logging

**What changed.** `getAccessToken()` persists the new refresh token after
a successful refresh (was discarded). `requestAccessData()` failure log
includes `grant_type=[…]` tag.

### Tests

1. Trigger a refresh: shorten a character's
   `esiAccessTokenExpires` to a past timestamp; then access any map
   page. The next `getAccessToken()` call goes through CCP.
2. Check the DB row:
   ```sql
   SELECT esiAccessTokenExpires, LEFT(esiRefreshToken, 5)
   FROM `character` WHERE id = <test character>;
   ```
   `esiAccessTokenExpires` updated to ~20m from now; `esiRefreshToken`
   begins with `v1:` (F5 setter encrypted on save).
3. **Concurrent-refresh smoke (informational only — race may not
   reproduce):**
   ```bash
   docker exec mariadb-or-host mysql -e \
     "UPDATE pathfinder.\`character\` SET esiAccessTokenExpires = '2000-01-01 00:00:00' WHERE id = <id>"
   # then in two terminals:
   curl -b "PHPSESSID=<your-session>" https://localhost/api/Map/getMapData & \
   curl -b "PHPSESSID=<your-session>" https://localhost/api/Map/getMapData & wait
   ```
   Check `SSO.log` for clustered `grant_type=[refresh_token]` failures.
   If they cluster, the race PLAN_SSO_AUDIT A3 deferred is real and the
   Redis lock should be revisited.

---

## 11. F6 — multi-tab login state map

**What changed.** `SESSION.SSO.STATE` is a map keyed by state token;
`SESSION.SSO.FROM` embedded per-entry. Max 5 entries, oldest evicted.

### Tests

1. **Two-tab login (the original bug).**
   - Open tab A: navigate to `/login`, click **Login with EVE Online** →
     redirected to CCP, **do not** complete yet.
   - Open tab B: same flow, redirect to CCP, **do not** complete.
   - In tab A: complete login → lands on map.
   - In tab B: complete login → lands on map (was: "Invalid response").

2. **State map cap.** Open 6 tabs and trigger the SSO redirect in each
   without completing any. Then complete tab #1 (oldest state). Expect:
   tab #1's callback fails with "Invalid response" (its state was evicted
   when tab #6 pushed the map past 5). Tabs #2–#6 should still succeed
   in any order.

3. **Defensive filter against legacy scalar sessions.** Set
   `SESSION.SSO.STATE` to a raw string in Redis to simulate an in-flight
   session from before the upgrade, then trigger a login:
   ```bash
   docker exec $(docker compose ps -q pf-redis) valkey-cli -a "$REDIS_PASSWORD" \
     SET pathfinder.SESSION.SSO.STATE '"legacy_string_value"'
   ```
   The login flow should silently drop the malformed entry, add its own
   `{state => {from, createdAt, pkceVerifier}}`, and succeed.

---

## 12. A4 — PKCE

**What changed.** `code_challenge` (S256) added to auth URL;
`code_verifier` added to token POST. Feature flag `CCP_SSO_USE_PKCE = 1`.

### Tests

1. **Wire check.** Click **Login with EVE Online**, intercept the
   redirect URL to `login.eveonline.com/v2/oauth/authorize`. Query
   string must include:
   - `code_challenge=<43 base64url chars>`
   - `code_challenge_method=S256`

2. **End-to-end with PKCE on.** Complete the login. Confirm a normal
   map page appears, `SSO.log` has no `grant_type=[authorization_code]`
   failures.

3. **Kill-switch.** Set `CCP_SSO_USE_PKCE = 0` in `environment.ini`,
   rebuild, retry. Redirect URL must **not** include `code_challenge`.
   Login still completes (this is the fallback for the case where CCP
   rejects PKCE alongside `client_secret`).

4. **Mismatched verifier.** Temporary instrumentation: in
   `verifyAuthorizationCode()` clobber `$pkceVerifier` to `'wrong'`
   before the call. Trigger a login. CCP should respond with
   `invalid_grant`; `SSO.log` should show
   `grant_type=[authorization_code]` failure. Revert.

5. **Pending CCP verification.** PLAN_SSO_AUDIT records A4 as needing a
   Sisi round-trip. Test #1 + #2 above is exactly that.

---

## 13. F5 — token encryption at rest

**What changed.** `TokenCipher` (libsodium) encrypts `esiAccessToken`
and `esiRefreshToken` before persistence. Setter encrypts on write;
`getAccessToken()` decrypts on read. Lazy migration via `v1:` prefix
sniff. Eager bulk migration via `migrate-tokens.php`. Key rotation via
`rotate-token-key.php`.

### Tests

1. **Wire check.** After a fresh SSO login:
   ```sql
   SELECT id,
          LEFT(esiAccessToken, 10) AS access_head,
          LEFT(esiRefreshToken, 10) AS refresh_head,
          LENGTH(esiAccessToken) AS access_len,
          LENGTH(esiRefreshToken) AS refresh_len
   FROM pathfinder.`character`
   WHERE id = <test character>;
   ```
   Both heads should start with `v1:`. `refresh_len` ≤ 256 (fits
   `VARCHAR256`).

2. **Round-trip.** Access any map page (causes a `getAccessToken()` call
   without refresh — decryption path). Map renders. No
   `JWT issuer mismatch` / `Unable to verify` lines. Repeat after access
   token expiry to exercise the refresh path → confirm row gets a fresh
   nonce (different `v1:…` value) on save.

3. **Wrong key.** Backup the current value of `TOKEN_ENCRYPTION_KEY`,
   then set it to a different valid 64-hex key, restart `pf`. Reload the
   map: user should be sent back through SSO (decrypt failed →
   `getAccessToken()` returned false). No 500s. Restore the key.

4. **Empty key.** Blank `TOKEN_ENCRYPTION_KEY` in `.env`, restart. Any
   attempt to refresh a token should throw `RuntimeException` — visible
   in `logs/error.log`. The error is intentional (fail-closed); restore
   the key.

5. **Eager bulk migration** (the new `migrate-tokens.php`):
   - Seed plaintext via SQL (simulate legacy v2 row):
     ```sql
     UPDATE pathfinder.`character`
       SET esiAccessToken = 'plaintext-access-XYZ',
           esiRefreshToken = 'plaintext-refresh-XYZ'
       WHERE id = <test character>;
     ```
   - Dry run:
     ```bash
     docker compose run --rm \
       -e TOKEN_ENCRYPTION_KEY="$(grep ^TOKEN_ENCRYPTION_KEY= .env | cut -d= -f2 | tr -d '"')" \
       pf php /usr/local/bin/migrate-tokens.php --dry-run
     # → migrated=1 already_encrypted=N empty=M (dry-run)
     ```
   - Wet run (no flag). Re-query the DB: row now has `v1:…` heads.
   - Re-run wet: `migrated=0 already_encrypted=…` — proves idempotency.

6. **Key rotation** (`rotate-token-key.php`):
   - Generate `$NEW = $(openssl rand -hex 32)`.
   - Dry run with both keys:
     ```bash
     docker compose run --rm \
       -e OLD_TOKEN_ENCRYPTION_KEY="$(grep ^TOKEN_ENCRYPTION_KEY= .env | cut -d= -f2 | tr -d '"')" \
       -e NEW_TOKEN_ENCRYPTION_KEY="$NEW" \
       pf php /usr/local/bin/rotate-token-key.php --dry-run
     ```
   - Wet run; swap `.env`; restart; reload map → still logged in
     (proves the script's re-encryption matched the new key the app now
     loads).
   - Sanity: try the wet run a second time **without** updating
     `.env` yet. Script should report `failed > 0` because rows are now
     encrypted with the new key but `OLD_TOKEN_ENCRYPTION_KEY` is still
     pointing at the previous old key.

---

## End-to-end smoke (one click after all tests)

After everything above is green, do a full from-cold login:

1. `docker compose down && docker compose up -d --force-recreate`
2. Wait for `entrypoint.sh` to finish; tail `pathfinder` logs.
3. Clear browser cookies for the domain.
4. Visit `/`, click **Login with EVE Online**, complete on Sisi.
5. Land on `/map`. Open a map. Paste a sig. Jump a system. Open the EVE
   ingame window for a corp via right-click → "Show Info" (exercises
   `openIngameWindow` → access-token round-trip).
6. `tail logs/SSO.log logs/error.log` — should be silent. Any new line
   is a regression.

---

## Out-of-band items not exercised here

- **`/setup` access** — covered in MIGRATION-v2-to-v3.md §6, not in
  this audit's scope.
- **WebSocket HMAC binding** — separate plan; smoke-tested via
  `pf-socket` log monitoring during a map session.
- **Secret management migration** — listed "out of scope" in
  PLAN_SSO_AUDIT.
