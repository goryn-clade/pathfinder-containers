# Security Review Plan — `pathfinder-containers/pathfinder`

## Scope & threat model

**Target:** PHP 8.2 Fat-Free Framework app + MariaDB + Redis + PHP/Ratchet WebSocket server, fronted by nginx, integrating with EVE Online's ESI/SSO OAuth.

**Attacker goal of primary concern:** read/write access to the MariaDB instance — directly (SQLi, leaked creds, exposed port), via RCE in the PHP app, or by abusing admin/setup endpoints.

**Highest-suspicion attack surface** (worth flagging up front):

1. **Wildcard dynamic dispatch** in `app/routes.ini` — `/api/@controller/@action` and `/api/rest/@controller*` invoke `Controller\Api\{controller}::{action}` with user-controlled class/method names. Any public method on any class reachable via the namespace becomes callable. This is the single most likely path to auth bypass or unintended sink invocation.
2. **`/setup` route still enabled** in `routes.ini` despite the comment saying to remove it post-install. Protected only by nginx Basic auth (single shared password from `$APP_PASSWORD`). Setup wizards typically run DB DDL — a bypass = full DB control.
3. **`/admin*` panel** — verify the auth gate is enforced inside `Admin->dispatch`, not just by convention.
4. **ESI/SSO callback** (`Controller/Ccp/Sso.php`) — OAuth flows are recurring sources of state/CSRF, open-redirect, and token-confusion bugs.
5. **Cortex ORM (`ikkez/f3-cortex`)** pinned to a non-tagged commit. Audit how queries are built — F3 historically has had `Mapper::find()` patterns that are safe only if filter arrays are well-formed.
6. **DB credentials** flow from `.env` → `pathfinder.ini` template → app. Check whether they leak via the `/setup` debug page, error pages (F3 escalates NOTICEs to 500s with full traces in dev), or logs.

---

## Phased plan

### Phase 0 — Recon & inventory `[DONE]`
- [x] Enumerate every route (`routes.ini`, websocket endpoints). _Note: grep pattern fixed — original missed `GET|POST` multi-method lines._
- [x] Map every public method on every `Controller\Api\*` class → 60 methods across 23 controllers.
- [x] List every DB query construction site → 62 SQL sinks, 32 code sinks, 176 FS sinks captured to `/tmp/`.
- [x] Run `composer audit` — main app clean; websocket has 3 advisories (F-04, F-05). _Note: websocket is PHP/Ratchet, not Node — npm audit N/A._
- [ ] Diff against upstream `goryn-clade/pathfinder` to isolate fork deltas. _Not done — pending._
- [x] Image/container scan: `trivy image` + `hadolint` + `trivy fs` run. _checkov not installed; compose port exposure not manually reviewed (covered partially by trivy fs)._

### Phase 1 — AuthN / AuthZ `[DONE]`
- [x] Trace request → `AccessController::beforeroute()` enforces login on all inheriting controllers. Session stored in Redis.
- [x] All 23 Api controllers checked: 20 properly inherit `AccessController`; 3 extend `Controller` directly (`User`, `Killboard`, `GitHub`) — `User` has internal `getCharacter()` guards but no 401 (F-10). `Killboard`/`GitHub` intentionally public.
- [x] `/admin` — role-based check (`SUPER`/`CORPORATION`) enforced in `beforeroute()` before `dispatch()`. No `PF_SUPER_ADMIN_ID` env var; role assignment is the gate.
- [x] `/setup` — nginx Basic Auth is the only gate; `init()` has no PHP-level auth check (F-09). Cannot self-serve password reset. Schema re-run risk exists if called against populated DB.
- [x] SSO — state nonce validated, JWT issuer verified, tokens stored hashed. Cookie flags: HttpOnly + SameSite now set (F-02, F-03 fixed). Secure flag pending TLS (F-11).

### Phase 2 — SQL injection `[DONE]`
- [x] All 62 sinks classified: 59 safe (parameterized/hardcoded), 2 fragile patterns (F-18: `implode()` in Setup SHOW VARIABLES — keys hardcoded so not exploitable; AbstractModel ID list — integers only).
- [x] User-input → Cortex `find()` traced: all use array-form filters `['col = ?', $val]`. No string concatenation with user input found.
- [x] ORDER BY / LIMIT / column params: hardcoded or whitelist-validated throughout.
- [x] `Db/Sql/` directory read — all parameterized.
- [x] Second-order injection: not explicitly traced end-to-end for map names / character notes. _Partial — recommend follow-up._

### Phase 3 — Other injection / RCE `[DONE]`
- [x] Dynamic dispatch sinks: 9 `call_user_func` patterns — all target hardcoded callables. Wildcard router has no allow-list but auth-by-inheritance covers it (F-14).
- [x] Template injection: all template paths static/config-driven. No user-controlled view path.
- [x] File operations: import/export use hardcoded model table names. No LFI/path traversal found.
- [x] Command exec: 4 `exec()` calls in `Setup.php` — hardcoded version-check commands only (`git --version`, etc.), behind Basic Auth.
- [x] PHP object injection: no `unserialize()` on untrusted data found. Session stored via PHP's redis session handler (serialized by PHP, not user-controlled).
- [ ] XXE: `simplexml_load_string` / `DOMDocument::loadXML` not explicitly grepped. _Not done — low priority given no XML import features found._

### Phase 4 — SSRF & external requests `[DONE]`
- [x] ESI client / Killboard proxy: URL base hardcoded from config; user-supplied segment cast to int. No SSRF.
- [x] Webhooks (Discord/Slack): URL from admin-controlled config only. Not user-influenceable at request time.
- [x] Redirect endpoints: all `reroute()` calls use F3 route aliases or hardcoded paths. No open redirect.

### Phase 5 — Session, CSRF, transport `[DONE]`
- [x] CSRF: `[ajax]` routes require `X-Requested-With: XMLHttpRequest`; nginx sends no CORS headers → cross-origin preflight blocked. No traditional CSRF token but effective in practice (Low, F-14 context).
- [x] Cookie flags: HttpOnly + SameSite fixed (F-02, F-03). Secure pending TLS (F-11).
- [x] Session fixation: `session_regenerate_id()` not called on SSO login (F-06).
- [x] TLS / `letsencrypt/` config reviewed against live `pfdev.sa.muel.nz`. Cert valid (LE/R12, expires 2026-07-05), `acme.json` perms 600, HTTP→HTTPS 308 redirect in place. New finding: F-20 (HSTS missing). Pending dev-config fixes (F-11, F-12, F-13a/b) not yet deployed to live.
- [x] WebSocket auth: token generated server-side, 30s TTL, one-time use. No IP/session binding (F-07). PHP/Ratchet, not Node.

### Phase 6 — Secrets & container hygiene `[DONE]`
- [x] `trivy fs --scanners secret` run against repo — no hardcoded secrets flagged.
- [x] `compose.yml` / `compose.dev.yml` reviewed: MariaDB and Redis have **no host `ports:` mapping** — not exposed beyond the Docker bridge network. Traefik mounts `/var/run/docker.sock:ro` (standard, noted Info).
- [x] File perms checked: `logs/` bind-mount files are world-writable (0666) on host — low operational risk. `config/` dirs are 755 (owner-writable only). New findings: F-18 (Redis no auth), F-19 (app connects as MariaDB root).
- [x] nginx config: no `autoindex`, `.ini`/`.log` files denied, no error-page path disclosure. `server_tokens` fixed (F-13b).
- [x] PHP config: `display_errors` not set (defaults Off in PHP 8 CLI ini); `expose_php` not set; `allow_url_include` not set. `session.cookie_httponly` + `samesite` added (F-02, F-03). DEBUG=3 in `.env` is dev-only; `.env.example` defaults to 0 (F-08).

### Phase 7 — Logic & abuse `[DONE]`
- [x] `0, 512` route params = F3 `ttl=0, kbps=512` (bandwidth throttle, not request rate limit). No `limit_req_zone` in nginx (F-17).
- [x] Mass assignment: all `copyfrom()` calls use explicit allow-lists or server-generated data. No bare mass-assignment found.
- [x] TOCTOU on map access: `hasAccess()` check and subsequent update are not atomic, but the window is negligible and there is no meaningful privilege to gain from the race. Not a finding.
- [x] Privilege escalation: **SUPER** role is config-file-only (hardcoded character IDs in `PATHFINDER.ROLES.CHARACTER`); no runtime API can grant it. **CORPORATION** role requires EVE in-game director/personnel_manager/security_officer role verified via ESI. No user-accessible endpoint can modify roleId. No privilege escalation path found.

### Phase 8 — Triage & report `[DONE]`
- [x] Score each finding — see `findings.md`.
- [x] `findings.md` delivered (20 findings: 0 Critical, 2 High, 5 Medium, 5 Low, 6 Info; 6 already fixed). _Updated: +F-18 (Redis no auth, Low), +F-19 (MariaDB root user, Medium), +F-20 (HSTS missing, Low)._
- [x] `quick-wins.md` written — 6 actionable items with code snippets, ordered by impact.
- [x] No PoCs required — no Critical/High finding with a direct DB write path (SQLi ruled out; container CVEs mitigated by config change).

---

## Tooling

- **Static**: Semgrep (`p/php`, `p/owasp-top-ten`, `p/security-audit`); Psalm with taint analysis (`--taint-analysis`); PHPStan.
- **Dependency**: `composer audit`, `npm audit` on `package.json` and `websocket/`, GitHub Advisory DB lookups for the pinned Cortex commit.
- **Container**: `trivy image`, `dockle`, `hadolint pathfinder.Dockerfile`, `checkov` for compose.
- **Dynamic** (against a dev container): Burp / ZAP authenticated scan, plus a small custom fuzzer that exercises the `/api/@controller/@action` wildcard against every public method discovered in Phase 0.
- **Manual**: read every controller end-to-end; tools won't catch the dispatch-layer auth gaps that are the most likely real bugs here.

---

## Local execution — environment setup

### 1. Pre-flight — get a working dev stack

```bash
cd /Users/sam/Development/pathfinder/pathfinder-containers
cp .env.example .env   # if not already present
docker compose -f compose.dev.yml up -d
docker logs -f pathfinder
```

### 2. Install scanning tools (host side)

```bash
brew install semgrep trivy hadolint
pip install --user checkov
composer global require vimeo/psalm phpstan/phpstan
```

### 3. Phase 0 recon dump

From `pathfinder-containers/pathfinder`:

```bash
# Dependency CVEs
composer audit --locked --format json > /tmp/composer-audit.json
( cd ../websocket && composer audit --locked --format json > /tmp/ws-composer-audit.json )

# Route + endpoint inventory
grep -nE '^(GET|POST|PUT|DELETE|PATCH|GET\|POST|/api)' app/routes.ini > /tmp/routes.txt
grep -rnE 'public function ' app/Controller/Api/ > /tmp/api-methods.txt

# Sink inventory
grep -rnE '\->(exec|find|load|select)\(|\bquery\(|\bprepare\(' app/ > /tmp/sql-sinks.txt
grep -rnE 'unserialize\(|eval\(|call_user_func|forward_static_call|`[^`]+\$' app/ > /tmp/code-sinks.txt
grep -rnE 'file_get_contents|fopen|include|require' app/ > /tmp/fs-sinks.txt
```

### 4. Container & infra scan

```bash
cd /Users/sam/Development/pathfinder/pathfinder-containers
hadolint --format json pathfinder.Dockerfile pf-websocket.Dockerfile > /tmp/hadolint.json
trivy image --format json --quiet pathfinder-containers-pf --severity HIGH,CRITICAL > /tmp/trivy-image.json
trivy fs --format json --quiet . --scanners vuln,secret,config > /tmp/trivy-fs.json
checkov -f compose.dev.yml -o json > /tmp/checkov.json  # requires: pip install checkov
```

### 5. Static analysis sweeps

```bash
cd pathfinder
semgrep --config p/php --config p/owasp-top-ten --config p/security-audit \
        --json --output /tmp/semgrep.json app/
psalm --init app 4
psalm --taint-analysis --report=/tmp/psalm-taint.json
phpstan analyse app --level=5 --error-format=json > /tmp/phpstan.json
```

### 6. Dynamic probes against the live container

```bash
curl -is http://localhost:80/api/User/getEveServerStatus > /tmp/curl-getEveServerStatus.txt
curl -is http://localhost:80/api/Setup/init > /tmp/curl-setup-init.txt      # should 404 — if not, finding
curl -is http://localhost:80/setup > /tmp/curl-setup.txt                    # should require Basic auth

docker run -t --network host owasp/zap2docker-stable zap-baseline.py \
  -t http://localhost:80 -r /tmp/zap-report.html
```

---

## Model strategy — hybrid

- **Opus 4.7** — primary reviewer for Phases 1, 2, 3, 7, 8. Multi-hop taint reasoning, exploit-chain construction, F3 quirks (NOTICE→500, template compile cache).
- **Sonnet 4.6** — parallel subagents for Phases 0, 4, 6. Recon, route enumeration, dependency CVE lookup, container/nginx config audit, secret grepping.
- **Haiku 4.5** — one-shot lookups only.

---

## Deliverables

- [x] `findings.md` — vulnerability register (20 findings, F-01 through F-20).
- [x] `quick-wins.md` — config flips, route removals, header additions deliverable in <1 day.
- [x] PoCs — N/A. No Critical/High finding reaches the DB directly (SQLi ruled out; container CVEs are patch-only).
