# Claude Instructions — pathfinder-containers

## Project Overview

This repo (`pathfinder-containers`) is the Docker deployment wrapper for
[Pathfinder](https://github.com/goryn-clade/pathfinder), an EVE Online wormhole
mapping tool. It composes three components:

- **pathfinder** (git submodule) — the main PHP application (Fat-Free Framework)
- **pathfinder_esi** (Composer dependency) — ESI/SSO API client library
- **Supporting services** — MariaDB, Redis, a Node.js socket server

## Current Goal: PHP 8 Upgrade

We're trying to package a major upgrade from v2.x to v3.0. This will include merging all the community submitted bug fixes, dependabot PRs, bug fixes, and a working php8 app as a baseline for 3.0.

We are porting the stack from PHP 7 to PHP 8.2/8.3. The work spans three repos:

- `pathfinder-containers` — Docker/nginx config, compose files
- `pathfinder` submodule — app code, templates, composer deps
- `pathfinder_esi` — ESI client library (separate package, bumped via composer)

### Where to look first
- check for a rector-report.txt file in pathfinder-containers and pathfinder_esi to identify low hanging fruit for fixing

### known php7 to php8 issues to check for: 
- Nullable types and union types
- Removed functions (each(), create_function(), etc.)
- String interpolation ${var} → {$var}
- Match expressions replacing switch where appropriate  
- Constructor property promotion
- Named arguments
- Strict type coercion changes (especially around int/float/string)
- Any deprecated or removed extensions

### What's been fixed so far

- Guzzle 6 → 7, PSR-7 v1 → v2
- `JsonStream`: introduced `decode(): mixed` to avoid `getContents(): string` PSR-7
  conflict; declared `protected StreamInterface $stream` to suppress dynamic
  property deprecation
- `JsonStreamInterface`: added `decode()` method declaration
- `GuzzleCcpErrorLimitMiddleware`, `AbstractApi`, `Esi`, `Sso`: updated callers
  to use `decode()` instead of `getContents()`
- `AbstractIterator`: fixed deprecated string callable `'self::method'` →
  `[static::class, 'method']`
- `Controller::counter()`: fixed undefined array key defaults
- `Setup.php`: fixed `parse_url(null)`, missing `query` key, missing
  `tplCharacterId`, tool-not-installed array key guards
- `f3-cortex` bumped to latest master (adds `#[\ReturnTypeWillChange]` on
  `offsetSet`)
- Many F3 template fixes in `setup.html`, `cron_table_row.html`,
  `requirements_table.html`, `debug.html`: `?? null` / `?? []` / `?? 0` guards
  throughout to stop PHP 8 undefined key/property NOTICEs from becoming 500s
  (F3 escalates all NOTICEs to HTTP 500)
- `AbstractLog.php`: `?? null` guards on stdClass property accesses

### Active work

Getting `/setup` to render without 500s so the DB schema can be bootstrapped
via the setup wizard.

### Known remaining issues (backlog)

1. `GET /api/User/getEveServerStatus` 500 — Redis TTL passed as string
2. DB schema not yet created (blocked on /setup loading)

## Key Architecture Notes

- **F3 templates** are compiled and cached in `pathfinder/tmp/*.php`. After
  fixing template source files, the cache must be cleared or the image rebuilt.
  We always rebuild with `--no-cache` to avoid stale compiled templates.
- **F3 escalates PHP NOTICEs to 500** — undefined array keys and undefined
  object properties that would be warnings in other frameworks are fatal here.
- **Compose image** (`pathfinder-containers-pf`) is separate from any manually
  built image. Always use `docker compose -f compose.dev.yml build` not
  `docker build`.
- **`/setup` requires HTTP Basic auth** — nginx enforces it via
  `/etc/nginx/.setup_pass` (user: `pf`, password from `$APP_PASSWORD` env var).

## Workflow

```bash
# Rebuild and restart after code changes
docker compose -f compose.dev.yml build --no-cache pf && \
docker compose -f compose.dev.yml up -d --force-recreate pf

# Watch logs
docker logs -f pathfinder

# Clear template cache without full rebuild
docker exec pathfinder sh -c 'rm -f /var/www/html/pathfinder/tmp/*.php'
```

## Working Style
- scan ahead and batch fixes
- Don't waste tokens explaining unless it's asked for, just fix.
- If I allow something, check if I want to add a broad rule to settings.json to allow it in future
- Best way to test is to rebuild the docker containers and ask the user to test
- Keep a succint summary of changes in pathinder-containers/changelog.md
- If you find problems that are not in your immediate scope to fix, add them to PLAN.md at the repo root


## Commit conventions

- No `Co-Authored-By` lines in commits
- Submodule changes: commit in `pathfinder` first, then update the pointer in
  `pathfinder-containers` with a matching message prefixed `Update pathfinder submodule:`
- try to avoid frequent version iterations.
