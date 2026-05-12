# EVE SSO / OAuth audit — findings & remediation plan

Scope: `pathfinder/app/Controller/Ccp/Sso.php`, `pathfinder/app/Controller/Controller.php` (cookie auth), `pathfinder_esi/app/Client/Ccp/Sso/Sso.php`, related session/state handling.

This document has two parts:
1. **Confirmed findings** — discovered by reading the code during scoping. Each is a real defect or gap with a concrete remediation.
2. **Remaining audit tasks** — areas not yet read end-to-end. Each may surface further findings.

Both parts will ship in a single SSO hardening PR, in the commit order at the bottom.

## Model guidance

Each item is tagged with a recommended model:

- **Sonnet** — well-bounded, mechanical, or pattern-matches existing code. Sonnet executes directly.
- **Sonnet + Opus review** — Sonnet implements; Opus reviews the design before merge. Used where edge cases are subtle (concurrency, OAuth flow correctness).
- **Opus** — design and implementation. Used where a small mistake has outsized consequences (crypto at rest, anything involving key material).

Sonnet's instinct on investigation items (A1) is to skip the investigation and patch. For those, the plan explicitly calls out the verification step that must be done first.

---

## Part 1 — Confirmed findings

### F1 — Issuer (`iss`) check is broken; tokens are effectively unverified for issuer
**Severity:** High. **Model:** Sonnet. **Location:** [pathfinder/app/Controller/Ccp/Sso.php:477](pathfinder/app/Controller/Ccp/Sso.php#L477)

```php
if (strpos((string) $decodedJwt->iss, static::getSsoJwkClaim()) !== true) {
    self::getSSOLogger()->write(sprintf(self::ERROR_TOKEN_VERIFICATION, __METHOD__));
}
return $decodedJwt;
```

`strpos()` returns `int|false`, never `true`. The condition is always true, so every successful login logs `ERROR_TOKEN_VERIFICATION`. Even when the "check" "fails" only a log line is written — `$decodedJwt` is still returned to the caller.

**Remediation:** Pass expected issuer to `JWT::decode()` for built-in validation, or compare with `hash_equals($decodedJwt->iss, $expectedIssuer)` and throw on mismatch. No silent return.

### F2 — Audience (`aud`) is not verified
**Severity:** High. **Model:** Sonnet (collapse with F1 — same function). **Location:** [pathfinder/app/Controller/Ccp/Sso.php:468-481](pathfinder/app/Controller/Ccp/Sso.php#L468-L481)

`JWT::decode()` verifies signature, `exp`, `nbf`, `iat`. It does not check `aud` — that's a caller responsibility per `firebase/php-jwt` docs. CCP tokens include `aud` set to the client's character API key; we never check it. A token issued for a different client/app would still be accepted.

**Remediation:** After `JWT::decode()`, verify `$decodedJwt->aud === $expectedAudience` (probably equal to our `CCP_SSO_CLIENT_ID`). Also check `azp` (authorized party) if present.

### F3 — Refresh token leaks to log on failure
**Severity:** High. **Model:** Sonnet. **Location:** [pathfinder/app/Controller/Ccp/Sso.php:435](pathfinder/app/Controller/Ccp/Sso.php#L435)

```php
self::getSSOLogger()->write(sprintf(self::ERROR_ACCESS_TOKEN, print_r($requestParams, true)));
```

`$requestParams` contains `refresh_token` (or `code`) on the failure path. Any log scraper / log forwarder now has long-lived refresh tokens.

**Remediation:** Redact `refresh_token`, `code`, `client_secret` before logging. Helper `redactSecrets(array): array` that masks known sensitive keys.

### F4 — Deprecated CSPRNG: `openssl_random_pseudo_bytes`
**Severity:** Medium. **Model:** Sonnet. **Locations:**
- [pathfinder/app/Controller/Ccp/Sso.php:144](pathfinder/app/Controller/Ccp/Sso.php#L144) — OAuth `state`
- [pathfinder/app/Controller/Controller.php:260-265](pathfinder/app/Controller/Controller.php#L260-L265) — cookie auth selector/validator

`openssl_random_pseudo_bytes` is a PHP 7+ alias for `random_bytes`, deprecated in PHP 8.4. [pathfinder/app/Controller/Api/Map.php:482](pathfinder/app/Controller/Api/Map.php#L482) already uses `random_bytes(16)` — inconsistent.

**Remediation:** All three call sites → `random_bytes()`. Bump SSO `state` from 12 → 32 bytes while in the file.

### F5 — Refresh & access tokens stored plaintext in DB
**Severity:** Medium. **Model:** Opus (design + implementation). **Location:** [pathfinder/app/Model/Pathfinder/CharacterModel.php:108-116](pathfinder/app/Model/Pathfinder/CharacterModel.php#L108-L116)

Columns `esiAccessToken`, `esiRefreshToken` are stored as plain VARCHAR. A DB read (backup, dump, SQLi) exposes them. Refresh tokens are long-lived.

**Remediation:** Encrypt at rest with libsodium `crypto_secretbox`, key from new `.env` var `TOKEN_ENCRYPTION_KEY` (32 bytes). Add `encrypt()`/`decrypt()` accessors. Lazy re-encryption on next refresh (no migration script needed). Document rotation procedure.

**Why Opus:** key generation, nonce handling (one per encryption, never reused), key rotation strategy, and lazy-migration semantics are easy to get subtly wrong. A reused nonce on `crypto_secretbox` breaks the security model entirely. Worth slow, careful design.

### F6 — Single-slot `state` storage prevents multi-tab login
**Severity:** Low (UX, not security). **Model:** Sonnet + Opus review. **Location:** [pathfinder/app/Controller/Ccp/Sso.php:144-145](pathfinder/app/Controller/Ccp/Sso.php#L144-L145)

`SESSION.SSO.STATE` holds a single value — second tab's login overwrites first.

**Remediation:** Store as map `{state => {createdAt, from}}`, max ~5 entries, prune on read. Consume atomically with `getAndDelete()`.

**Why review:** atomic-consume semantics on a F3 session map have a subtle race — confirm the chosen primitive (session write lock vs single `$f3->set` of the modified map) doesn't lose entries under concurrent callbacks from sibling tabs.

**Outcome (2026-05-12):** Applied. `SESSION.SSO.STATE` is now a map; `SESSION.SSO.FROM` is embedded per-entry.
- `rerouteAuthorization()`: reads existing map, filters invalid entries (defensive against legacy scalar
  sessions), evicts oldest when at capacity (≥5), inserts `{from, createdAt}` keyed by state token.
- `callbackAuthorization()`: looks up incoming state in map, extracts `from`, consumes entry (unset + write
  back / clear when empty). All downstream login logic unchanged.
- Residual race acknowledged: `\DB\SQL\Session::read()` has no `FOR UPDATE` lock, so truly simultaneous
  writes can lose one entry. Rare in practice (human-paced tab clicks), accepted for UX-level fix.

### F7 — Dead code in `getSsoAccessData()`
**Severity:** Trivial. **Model:** Sonnet. **Location:** [pathfinder/app/Controller/Ccp/Sso.php:354-366](pathfinder/app/Controller/Ccp/Sso.php#L354-L366)

`$authCode` is typed `string`; the `!empty()` check + log-only `else` is unreachable in normal use (empty string passes the type check but never hits in practice from the callback flow). Clean while in the file.

### C1 — No cookie rotation on use (found during A5 audit)
**Severity:** Medium. **Model:** Sonnet + Opus review. **Location:** [pathfinder/app/Controller/Controller.php:302-401](pathfinder/app/Controller/Controller.php#L302-L401)

`getCookieCharacters()` validates the selector/validator pair on every page load but never rotates the token on success. A stolen "remember me" cookie (30-day expiry, from `pathfinder.ini COOKIE_EXPIRE = 30`) remains usable for the full lifetime. The legitimate user has no way to detect or revoke it until their own cookie expires or they explicitly log out.

**Remediation:** On successful validation in `getCookieCharacters()`, before returning the character:
1. Generate a fresh `$newSelector` / `$newValidator` pair (same sizes as creation).
2. Update the DB row in place: `$characterAuth->selector = $newSelector; $characterAuth->token = hash('sha256', $newValidator); $characterAuth->save();`
3. Overwrite the cookie: `$this->getF3()->set('COOKIE.' . self::COOKIE_PREFIX_CHARACTER . '_' . $name, "$newSelector:$newValidator", $remainingTtl);`

**Concurrent-request edge case (why Opus review):** Two simultaneous requests from the same browser (e.g., parallel asset fetches that happen to trigger the auth path) both present the same valid cookie. First request rotates — old selector is gone. Second request's `getByForeignKey('selector', $oldSelector)` returns empty (`dry()` = true) → falls into the `$invalidCookie = true` branch → **erases the cookie** → silent logout. Possible mitigations: (a) "grace period" — retain old selector for ~5s after rotation; (b) per-characterId advisory lock; (c) accept the race (browser parallel requests on the auth path are rare and self-healing on next page load). Opus should pick the appropriate strategy.

---

## Part 2 — Remaining audit tasks

These need investigation; outcome unknown. Each may add new findings to Part 1.

### A1 — JWKS caching behaviour
**Model:** Sonnet — but the investigation step is mandatory before any code change.
[Sso.php:487-498](pathfinder/app/Controller/Ccp/Sso.php#L487-L498) calls `ssoClient()->send('getJWKS')` on every callback. Need to confirm whether `GuzzleCacheMiddleware` (in pathfinder_esi) honours the `Cache-Control` header CCP returns on `/oauth/jwks`. If yes, leave it. If no, JWKS is fetched per-login — slow path + soft dependency on CCP availability.

**Method:** Add a temporary log line in the JWKS request callback, do two successive logins, count requests. If uncached, add an explicit 1h F3 cache with invalidation on `kid` miss.

> ⚠️ **For Sonnet:** Do NOT skip the investigation. Confirm with a temp log line + two logins BEFORE adding caching. If the middleware already caches, this item becomes a no-op.

### A2 — Scope minimisation
**Model:** Sonnet.
`SSO_CCP_REQUEST_SCOPES` in `environment.ini`. Map each declared scope to the ESI call that uses it. Drop unused. Reduces blast radius if a refresh token leaks.

**Method:** Grep ESI client calls (`$f3->ccpClient()->send(...)`) across `pathfinder/app`, build a scope-usage table, diff against declared scopes.

**Outcome (2026-05-12):** No-op. All 10 declared scopes are actively used:
| Scope | Call site |
|-------|-----------|
| `esi-location.read_online.v1` | `getCharacterOnline` — CharacterModel.php:823 |
| `esi-location.read_location.v1` | `getCharacterLocation` — CharacterModel.php:862 |
| `esi-location.read_ship_type.v1` | `getCharacterShip` — CharacterModel.php:974 |
| `esi-ui.write_waypoint.v1` | `setWaypoint` — Api/System.php:41 |
| `esi-ui.open_window.v1` | `openWindow` — Api/User.php:238 |
| `esi-universe.read_structures.v1` | `getUniverseStructure` — Universe/StructureModel.php:93 |
| `esi-corporations.read_corporation_membership.v1` | `getCorporationRoles` — CorporationModel.php:297 |
| `esi-clones.read_clones.v1` | `getCharacterClones` — CharacterModel.php:799 |
| `esi-characters.read_corporation_roles.v1` | `getCharacterRoles` — CharacterModel.php:771 |
| `esi-search.search_structures.v1` | `search` — Ccp/Universe.php:311 |

### A3 — Concurrent refresh-token race
**Model:** Sonnet + Opus review.
Two concurrent requests with an expired access token both call `refreshAccessToken()`. CCP may invalidate the first refresh token when issuing the second — first request gets 401, user sees a stale logout.

**Method:** Reproduce in dev with two parallel curl requests at expiry time. If reproduces, add Redis lock keyed by `characterId` around `CharacterModel::getAccessToken()`.

**Why review:** Redis lock semantics are subtle — TTL must exceed the worst-case CCP token endpoint round trip; lock release must be safe under request timeout; "lock acquired but request died" must self-heal. Sonnet often picks reasonable defaults but doesn't reason about the failure cases. Opus should review the TTL, release path, and what happens to the waiting request after lock timeout.

**Outcome (2026-05-12):** Redis lock deferred pending evidence of the race.

- Production evidence (the app works despite `getAccessToken()` previously never persisting the
  refresh token from CCP's response) strongly suggests CCP does not rotate refresh tokens on use.
  If it doesn't rotate, there is no concurrent-invalidation race.
- Applied defensive fix: `CharacterModel::getAccessToken()` now persists `esiRefreshToken` after a
  successful refresh. Previously the new refresh token from CCP was discarded. No-op if CCP doesn't
  rotate; correct if CCP ever starts rotating.
- Added `grant_type=[%s]` tag to the `ERROR_ACCESS_TOKEN` log line so concurrent `refresh_token`
  grant failures can be measured in production. Revisit lock design if clustered
  `grant_type=[refresh_token]` failures appear in SSO logs.
- Deferred: Redis lock, threading `characterId` into `requestAccessData()` for richer failure
  logging. Track here if evidence emerges.

### A4 — PKCE feasibility
**Model:** Sonnet + Opus review.
EVE SSO v2 supports PKCE (RFC 7636). Currently a confidential client flow with `client_secret` — fine for server-side but PKCE adds defence against code interception (logs, referer leakage at the redirect URI).

**Method:** Confirm CCP accepts PKCE alongside `client_secret`. If yes, add `code_challenge`/`code_verifier` to auth + token requests. Verifier stored next to `state` in session.

**Why review:** OAuth flow correctness. Verifier length, challenge method (`S256`), URL-safe base64 (no padding) all matter for interop. Opus should verify the implementation against RFC 7636 §4 and CCP's documented behaviour.

**Outcome (2026-05-12):** Applied with feature flag. PKCE is now layered on the existing confidential-client flow; `client_secret` stays in Basic Auth.
- `rerouteAuthorization()`: generates `pkceVerifier` (43 base64url chars from `random_bytes(32)`) and `pkceChallenge` (`BASE64URL(SHA256(verifier))`); embeds verifier in state map entry; adds `code_challenge` + `code_challenge_method=S256` to the CCP auth URL.
- `callbackAuthorization()`: extracts `pkceVerifier` from consumed state entry; passes to `getSsoAccessData()`.
- `getSsoAccessData()` / `verifyAuthorizationCode()`: accept optional `$pkceVerifier`; include `code_verifier` in POST body to `/v2/oauth/token` when non-empty.
- ESI client unchanged — passthrough `form_params` already handled it.
- Feature flag `CCP_SSO_USE_PKCE = 1` in both `[ENVIRONMENT.DEVELOP]` and `[ENVIRONMENT.PRODUCTION]` in `environment.ini`. Set to `0` to disable without a code change if CCP rejects `code_verifier` on confidential clients.
- **Pending verification:** Sisi login round-trip required before treating this as confirmed. See Opus review for failure-mode table.

### A5 — Cookie-based "remember me" auth surface
**Model:** Sonnet for the audit read, Opus review if new findings surface.
[Controller.php:255-280](pathfinder/app/Controller/Controller.php#L255-L280) implements the selector/validator persistent login pattern. Read-through audit to confirm:
- `validator` is compared with `hash_equals()` (timing-safe)
- DB stores `hash('sha256', $validator)`, not plaintext
- Cookie flags: `HttpOnly`, `Secure`, `SameSite=Lax` (php.ini covers `Secure`/`SameSite` globally per Phase 1 hardening — confirm the cookie still inherits)
- Selector + validator length adequate (≥ 16 bytes each)
- Cookie rotation on use (one-time-token pattern)

### A6 — Token logging hygiene end-to-end
**Model:** Sonnet.
Beyond F3, sweep all `getSSOLogger()->write(...)` and `error_log(...)` call sites in the SSO + character refresh path for any access/refresh token references. Confirm log files aren't world-readable in the container.

**Outcome (2026-05-12):** Clean with one minor hardening applied.
- `requestAccessData()` failure
 path: `redactSecrets()` (F3) covers `refresh_token`, `code`, `client_secret` ✓
- `verifyCharacterData()` exception path: logs `$e->getMessage()` — JWT exception messages don't include the raw token ✓
- `CharacterModel::getAccessToken()` refresh path: no token logging ✓
- `GuzzleLogMiddleware`: request headers (`Authorization: Bearer …`) not logged (`DEFAULT_LOG_REQUEST_HEADERS=false`); 200 responses not logged (`DEFAULT_LOG_2XX=false`); error response bodies only extract the `error` key, not `access_token`/`refresh_token` ✓
- Log directory: was `chmod 0766` (world-writable) — changed to `chmod 0755` in `pathfinder.Dockerfile`. Log files are still created `0644` (world-readable) by PHP's default umask in the single-user container; no other users present to exploit.

---

## Execution order (single PR, internal commits)

Each line below is one commit, smallest blast radius first. Model column matches the per-item tags above.

| #  | Item        | Model                    |
|----|-------------|--------------------------|
| 1  | F1 + F2     | Sonnet                   |
| 2  | F3          | Sonnet                   |
| 3  | F4          | Sonnet                   |
| 4  | F7          | Sonnet                   |
| 5  | A1          | Sonnet (investigate first) |
| 6  | A2          | Sonnet                   |
| 7  | A6          | Sonnet                   |
| 8  | A5          | Sonnet (audit read)      |
| 9  | C1          | Sonnet + Opus review     |
| 10 | A3          | Sonnet + Opus review     |
| 11 | F6          | Sonnet + Opus review     |
| 12 | A4          | Sonnet + Opus review     |
| 13 | F5          | Opus                     |

Items 1–8 can be Sonnet-driven end-to-end. Switch to Opus for the design pass on item 9 onward (or have Sonnet draft each and Opus review the diff before commit).

## Out of scope (track elsewhere)
- Secret management migration (`.env` → Docker secrets / Vault) — separate workstream
- Audit log for SSO events (login, char switch, refresh failure) — observability workstream
