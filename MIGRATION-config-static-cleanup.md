# Migration guide — `config/` vs `static/` layout cleanup

This guide walks operators of an existing Pathfinder deployment through the
changes introduced by [PLAN-config-static-cleanup.md](PLAN-config-static-cleanup.md).
It only covers what an operator must do — see the plan file for the
rationale and per-file edits.

## Who needs to act

- **Dev operators** (`compose.dev.yml`): act on all three sections below.
- **Test operators** (`compose.test.yml`): act on the **redis.conf** section
  only.
- **Prod operators** (`compose.yml`): act on the **redis.conf** section
  only, and review the new `redis.conf` settings.

## Before / after at a glance

### Before

```
config/
  pathfinder/
    config.ini       ← redundant copy of static template (dev-only mount)
    pathfinder.ini   ← hardcoded values, dev-only mount, gets clobbered
                       on container start by envsubst
    plugin.ini       ← mounted by all three composes ✓
static/
  redis/
    redis.conf       ← never COPYed, only ever bind-mounted by dev + test
```

Compose mounts (relevant subset):

| Mount                                              | dev | test | prod |
|----------------------------------------------------|-----|------|------|
| `./config/pathfinder/config.ini`                   | ✓   | —    | —    |
| `./config/pathfinder/pathfinder.ini`               | ✓   | —    | —    |
| `./config/pathfinder/plugin.ini`                   | ✓   | ✓    | ✓    |
| `./static/redis/redis.conf`                        | ✓   | ✓    | —    |
| `valkey-server --appendonly yes` (inline command)  | —   | —    | ✓    |

### After

```
config/
  pathfinder/
    plugin.ini       ← unchanged, mounted by all three composes
  redis/
    redis.conf       ← moved here from static/, mounted by all three composes
```

Compose mounts (relevant subset):

| Mount                                              | dev | test | prod |
|----------------------------------------------------|-----|------|------|
| `./config/pathfinder/plugin.ini`                   | ✓   | ✓    | ✓    |
| `./config/redis/redis.conf`                        | ✓   | ✓    | ✓    |

`pathfinder.ini` is now produced exclusively by `envsubst` from the static
template at container start, in every environment.

## Migration steps

### 1. Pull the changes and review your `.env`

```bash
git pull
git status     # confirm no uncommitted overrides under config/
```

The dev-only hardcoded `pathfinder.ini` is gone, so any value previously set
there must now come from your `.env`. Confirm these keys are set in `.env`
(any blank ones are fine if you actually want them blank):

```
PF_INSTALL_NAME           # e.g. "Pathfinder Community Edition"
PF_REGISTRATION_STATUS    # 0 or 1
PF_LOGIN_WHITELIST_CHAR
PF_LOGIN_WHITELIST_CORP
PF_LOGIN_WHITELIST_ALLIANCE
PF_SUPER_ADMIN_ID         # your CCP character ID
```

If anything is missing, copy the matching block from `.env.example` and fill
it in. The shipped `.env.example` already lists all of them.

> **Heads up for dev operators:** if you previously customized
> `config/pathfinder/pathfinder.ini` by hand (beyond what was checked in),
> those changes will be lost. Diff your last working copy against the
> repo's `static/pathfinder/pathfinder.ini` and port any deltas into `.env`
> *before* the next container start. (In practice, the dev mount was being
> overwritten by `envsubst` on every restart anyway, so unless you edited it
> very recently you've already been running off the env-rendered values.)

### 2. Review the new `redis.conf` (prod operators especially)

Prod was previously running with only `--appendonly yes`. After this
cleanup, prod will load [config/redis/redis.conf](config/redis/redis.conf),
which adds:

| Setting               | Value           | Effect on prod                       |
|-----------------------|-----------------|--------------------------------------|
| `appendonly`          | `yes`           | unchanged                            |
| `maxmemory`           | `64mb`          | **new cap** — was unbounded before   |
| `maxmemory-policy`    | `allkeys-lru`   | **new** — evict any key by LRU       |
| `timeout`             | `0`             | matches valkey default               |
| `tcp-backlog`         | `511`           | matches valkey default               |
| `tcp-keepalive`       | `300`           | matches valkey default               |
| `loglevel`            | `notice`        | matches valkey default               |
| `databases`           | `16`            | matches valkey default               |

The only behavioural change for prod is the **64 MB memory cap with LRU
eviction**. If your prod Redis dataset is currently >64 MB, raise
`maxmemory` in `config/redis/redis.conf` before deploying, or you'll start
evicting cache entries. Check current usage:

```bash
docker exec <your-redis-container> valkey-cli INFO memory | grep used_memory_human
```

### 3. Rebuild and restart

#### Dev

```bash
docker compose -f compose.dev.yml build pf
docker compose -f compose.dev.yml up -d --force-recreate pf pf-redis
```

#### Test

```bash
docker compose -f compose.test.yml up -d --force-recreate pf-redis
# pf only needs a restart if you also pulled app changes
```

#### Prod

```bash
docker compose pull
docker compose up -d --force-recreate pf-redis pf
```

### 4. Verify

Pathfinder app config reflects `.env`:

```bash
docker exec pathfinder cat /var/www/html/pathfinder/app/pathfinder.ini \
  | grep -E '^NAME|^STATUS|^CHARACTER\.0\.ID'
# Expected: values from your .env (PF_INSTALL_NAME, PF_REGISTRATION_STATUS,
# PF_SUPER_ADMIN_ID).
```

Redis is loading the conf file (not just defaults):

```bash
docker exec <your-redis-container> valkey-cli CONFIG GET appendonly
# → appendonly / yes
docker exec <your-redis-container> valkey-cli CONFIG GET maxmemory
# → maxmemory / 67108864   (proves redis.conf was loaded — defaults are 0)
```

App smoke test: load the site, log in, open a map. No 500s in
`docker logs pathfinder`.

## Rollback

If something goes wrong, `git revert` the cleanup commit and rebuild.
There are no schema, data, or volume changes — `db_data` and `redis_data`
volumes are untouched, and the `.env` additions (if any) are harmless if
the old code is restored.

If you need to roll back **only** the prod redis change without reverting
everything:

1. In `compose.yml`, restore `command: ["valkey-server", "--appendonly", "yes"]`
   on the `pf-redis` service.
2. Remove the `./config/redis/redis.conf:/etc/redis/redis.conf:ro` volume.
3. `docker compose up -d --force-recreate pf-redis`.

## FAQ

**Q: I have local edits to `config/pathfinder/config.ini`. Will I lose them?**
A: That file was byte-identical to the static template and was deleted. If
you genuinely had local edits, port them into `static/pathfinder/config.ini`
(rebuild required) — but note that this template is meant to be the same
across all deployments, so per-instance edits should usually be expressed
as env vars instead.

**Q: Can I keep a local override for `pathfinder.ini` somewhere?**
A: For values not yet covered by env vars, yes — add them to
`static/pathfinder/pathfinder.ini` and rebuild, or open an issue to expose
them as env vars. The bind-mount-override pattern is being removed
deliberately because dev was diverging from test/prod.

**Q: Do I need to clear any caches?**
A: No. Template cache in `pathfinder/tmp/` is unaffected; Redis data in the
`redis_data` volume is preserved across the restart (subject to the new
`maxmemory` cap, see step 2).
