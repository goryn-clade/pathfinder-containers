# R2Z2 Poll Error — Debug Notes

## Symptom

On every page load (when a system with the killboard module is visible), Firefox logs:

```
R2Z2 poll error TypeError: NetworkError when attempting to fetch resource.
  error mappage.js:7
  pollNext mappage.js:32
  pollTimer mappage.js:32
  (Async: setTimeout handler)
  pollNext / pollTimer  ← repeated ~12 times (each 10s retry cycle)
```

## Code path

1. `system_killboard.js` → `render()` → `SystemKillboardModule.initPoller()`
2. `initPoller` fetches `/api/Killboard/sequence` (this **succeeds**, otherwise we'd see "R2Z2 init failed")
3. Gets sequence ID, calls `pollNext()`
4. `pollNext` fetches `/api/Killboard/r2z2/${seqId}` — **this throws `TypeError: NetworkError`**
5. Catch block logs the error, schedules retry after 10s — loops indefinitely

## What NetworkError means

`TypeError: NetworkError when attempting to fetch resource` is Firefox's error when
`fetch()` **rejects** (not resolves with an error status). This happens when:
- TCP connection is reset or abruptly closed
- HTTP/2 stream is RST_STREAM'd by the server
- Browser aborts the request (AbortController, page unload)

It does **NOT** happen for normal HTTP 4xx/5xx responses — those resolve with `resp.ok = false`
and would surface as `Error: R2Z2 502` etc.

## What has been ruled out

- **No service worker** — checked, none registered
- **No AbortController** — no `signal` in fetch call, no global fetch override
- **Not a CORS issue** — same-origin relative URL
- **Not auth blocking** — `Killboard::beforeroute()` doesn't check session authentication
- **Not my timeout changes** — 15s Guzzle timeout on r2z2 is well under 35s FPM / 40s nginx limits
- **Not a route mismatch** — wildcard route `/api/@controller/@action/@arg1` covers it; F3 AJAX flag honoured via `X-Requested-With: XMLHttpRequest`
- **Not bad URL construction** — even `undefined`/`NaN`/`null` sequence IDs produce valid URL strings; PHP returns 400, not a connection reset

## Most likely root cause

Unknown — cannot determine from static analysis alone. The candidates are:

1. **`r2z2.zkillboard.com` is unreachable / returns unexpected response format**
   - `sequence` endpoint might return data with a different key than `{sequence: N}`
   - If `seqData.sequence` is `undefined`, `pollSequenceId = undefined`, URL = `/api/Killboard/r2z2/undefined`
   - PHP converts invalid seqId to 0 → returns `400 {"error": "Invalid sequence ID"}`
   - JS throws `Error('R2Z2 400')` — but that's NOT NetworkError, so this alone doesn't explain it

2. **PHP produces an incomplete/malformed HTTP response**
   - If `r2z2.zkillboard.com` returns a very large body and PHP hits its memory limit (256M)
     while reading `(string)$response->getBody()`, PHP-FPM might die mid-response, causing
     nginx to RST the connection → browser gets NetworkError
   - Guzzle streaming vs. buffering could matter here

3. **nginx closes the connection without sending HTTP headers**
   - Would only happen if PHP-FPM has no available workers or the FastCGI connection drops
     before nginx can write any response headers to the client

4. **HTTP/2 RST_STREAM from Traefik**
   - If nginx returns something Traefik can't forward cleanly, Traefik may send RST_STREAM
     → Firefox throws NetworkError

## How to diagnose

1. **Browser Network tab** (most useful): navigate to a system with killboard, open DevTools → Network,
   filter for `r2z2`, look at the request. Report:
   - Status code (or "failed" label)
   - Response headers
   - Response body

2. **Docker PHP logs**: `docker logs pathfinder 2>&1 | grep -i "killboard\|r2z2\|fatal\|error"` during
   the poll cycle

3. **Test the sequence endpoint directly**:
   ```
   curl -H "X-Requested-With: XMLHttpRequest" https://pfdev.sa.muel.nz/api/Killboard/sequence
   ```
   Check the `sequence` key name and value type.

4. **Test the r2z2 endpoint directly** (using the sequence ID from step 3):
   ```
   curl -H "X-Requested-With: XMLHttpRequest" https://pfdev.sa.muel.nz/api/Killboard/r2z2/SEQUENCE_ID
   ```
   If this returns a valid HTTP response (any status), the issue is not a connection reset.
   If this returns nothing / hangs / connection refused, that's the NetworkError cause.

5. **Test upstream directly from the server**:
   ```
   docker exec pathfinder curl -v https://r2z2.zkillboard.com/ephemeral/sequence.json
   ```
   Confirms whether the upstream API is reachable and what format it returns.

## Relevant files

| File | Role |
|------|------|
| `pathfinder/js/app/ui/module/system_killboard.js:883` | `pollNext()` — where the error fires |
| `pathfinder/js/app/ui/module/system_killboard.js:974` | `initPoller()` — starts the loop |
| `pathfinder/app/Controller/Api/Killboard.php` | PHP proxy: `sequence()` and `r2z2()` |
| `pathfinder/app/pathfinder.ini:381` | `ZKILLBOARD_R2Z2 = https://r2z2.zkillboard.com/ephemeral` |
| `static/nginx/site.conf:20-23` | `location ^~ /api/Killboard/r2z2/` with `access_log off` |
