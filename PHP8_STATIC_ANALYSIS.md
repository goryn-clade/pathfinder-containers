# PHP 8 Static Analysis Execution Plan

This is a procedural runbook for a coding agent to execute. Work top-to-bottom. Stop at each checkpoint marked **STOP** and report results to the user before continuing. Commit frequently (per-phase at minimum). Follow commit conventions in [.claude/CLAUDE.md](.claude/CLAUDE.md) — no `Co-Authored-By` lines, submodule changes committed in the submodule first then pointer-bumped.

## Goal

Find and fix remaining PHP 7 → 8.2 compatibility issues in `pathfinder` and `websocket` submodules using layered static analysis. Scope is **compatibility only** — not code-quality cleanup, not style, not dead-code removal.

## Background

The upgrade has been reactive: F3 escalates PHP NOTICEs to HTTP 500, so every undefined array key / deprecated callable / type coercion issue surfaces as a hard runtime failure on whatever page the user happens to visit. Rector v2.4 and PHPStan v2.1 are already installed in this repo but Rector is effectively disabled and PHPStan's existing 100+ violations are untriaged. We want a proactive sweep.

## Tool layering (do in this order)

Analysis **before** rewrites — we want to understand what's broken before Rector mechanically touches code, especially given F3's `Base::` dynamic dispatch where auto-fixes can mask bugs.

1. `phpcompatibility/php-compatibility` (via PHPCS) — cross-version specific, cheapest, catches removed functions / changed signatures / `${var}` interpolation
2. PHPStan level 8 + deprecation rules — already installed, catches undefined array keys and deprecated-callable issues
3. Rector PHP 8.2 upgrade sets only — force multiplier, runs after we understand the shape of the problem
4. Manual behavioral review — loose comparisons, sort stability, numeric coercion
5. `composer why-not php:8.2` dependency audit
6. Smoke-test everything in the dev container

## Scope

- **In**: `pathfinder/app/`, `websocket/app/`
- **Out**: `pathfinder/vendor/` (includes `pathfinder_esi` — separate upstream pass), `pathfinder/tmp/` (F3 compiled templates, regenerated artifacts), CI integration (deferred)

---

## Phase 0 — Preconditions

### 0.1 Verify tool binaries
```bash
cd /Users/sam/Development/pathfinder/pathfinder-containers
vendor/bin/phpstan --version
vendor/bin/rector --version
```
If either missing, stop and report.

### 0.2 Bump websocket PHP requirement
The websocket submodule currently requires PHP >=7.1, which makes any analysis at 8.2 target meaningless for its files.

Edit `websocket/composer.json`: change `"php": ">=7.1"` → `"php": ">=8.2"`.

Then:
```bash
cd websocket
composer update --lock
cd ..
```

### 0.3 Install PHPCS + PHPCompatibility
```bash
composer require --dev squizlabs/php_codesniffer phpcompatibility/php-compatibility dealerdirect/phpcodesniffer-composer-installer
```
(`dealerdirect/phpcodesniffer-composer-installer` auto-registers the PHPCompatibility standard with PHPCS.)

Create `.phpcs.xml` at repo root:
```xml
<?xml version="1.0"?>
<ruleset name="pf-php8-compat">
  <description>PHP 8.2 compatibility sweep for pathfinder + websocket</description>
  <file>pathfinder/app</file>
  <file>websocket/app</file>
  <exclude-pattern>*/vendor/*</exclude-pattern>
  <exclude-pattern>*/tmp/*</exclude-pattern>
  <config name="testVersion" value="8.2-"/>
  <rule ref="PHPCompatibility"/>
  <arg name="colors"/>
  <arg value="sp"/>
</ruleset>
```

**STOP** — report Phase 0 complete. Commit with message `Add PHP 8 compat static analysis tooling`.

---

## Phase 1 — PHPCompatibility sweep

### 1.1 Run
```bash
vendor/bin/phpcs --standard=.phpcs.xml --report=full --report-file=phpcs-compat-report.txt || true
```
(The `|| true` is because PHPCS exits non-zero on findings — we want the report, not an abort.)

Also generate a summary:
```bash
vendor/bin/phpcs --standard=.phpcs.xml --report=summary --report-file=phpcs-compat-summary.txt || true
```

### 1.2 Triage
Read `phpcs-compat-summary.txt`. Categorize findings:
- **ERROR** = definite breakage (removed functions, invalid syntax at 8.2)
- **WARNING** = deprecation or behavior change

Fix ERRORs first, mechanically. Common fixes:
- `each($arr)` → `foreach` loop
- `create_function(...)` → closure
- `${var}` inside strings → `{$var}`
- `money_format` → `NumberFormatter`

Do NOT fix warnings yet — Rector in Phase 3 will handle most of them.

### 1.3 Verify
Re-run `vendor/bin/phpcs --standard=.phpcs.xml` and confirm zero ERRORs remain (warnings OK).

**STOP** — report count of errors fixed per file. Commit per submodule with `fix: PHP 8 compatibility errors from phpcompatibility sweep`. Pointer-bump in `pathfinder-containers` after.

---

## Phase 2 — PHPStan baseline + compat-targeted grep

### 2.1 Regenerate full report
```bash
vendor/bin/phpstan analyse --memory-limit=1G > phpstan-report.txt || true
```

### 2.2 Generate baseline to freeze existing debt
The existing report has 100+ violations that are a mix of compat issues and unrelated level-8 strictness debt (iterable types, nullable properties). We want to surface only NEW issues plus specific compat-critical patterns.

```bash
vendor/bin/phpstan analyse --generate-baseline=phpstan-baseline.neon --memory-limit=1G || true
```

Wire the baseline into `phpstan.neon`:
```yaml
includes:
  - vendor/phpstan/phpstan-deprecation-rules/rules.neon
  - phpstan-baseline.neon
```
(Keep any existing `includes` entries; add `phpstan-baseline.neon` at the end.)

Verify:
```bash
vendor/bin/phpstan analyse --memory-limit=1G
```
Should now exit 0 (all existing issues are baselined).

### 2.3 Grep for compat-critical patterns
```bash
grep -Ei 'ctype_digit|deprecated|offset .* does not exist|undefined array key|callable|parse_url|dynamic property|float.* (int|implicit)' phpstan-report.txt > compat-hits.txt
wc -l compat-hits.txt
```

### 2.4 Fix top-down
Work through `compat-hits.txt` sequentially. For each fix:
1. Apply the fix in the submodule
2. Remove the corresponding line from `phpstan-baseline.neon` (the baseline should shrink monotonically — never grow)
3. Re-run `vendor/bin/phpstan analyse --memory-limit=1G` to confirm no new errors

Common patterns from this codebase (context for what you'll see):
- `__METHOD__` inside callable arrays → `__FUNCTION__`
- `ctype_digit($x)` where `$x` may be int → `is_string($x) && ctype_digit($x)`
- Undefined array keys → `$arr['key'] ?? null` / `?? []` / `?? 0`
- `parse_url(null)` → null-guard the input
- `(int)ini_get('memory_limit')` → parse the suffix

**STOP** — report count of compat-hits fixed. Commit per submodule: `fix: PHP 8 compat issues from phpstan sweep`. Pointer-bump.

---

## Phase 3 — Rector (PHP 8.2 upgrade sets only)

Now mechanical rewrites. Code quality / dead-code / type-coverage sets stay OFF — only PHP upgrade rules and deprecation rules.

### 3.1 Rewrite `rector.php`
Replace contents with:
```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/pathfinder/app',
        __DIR__ . '/websocket/app',
    ])
    ->withSkip([
        __DIR__ . '/pathfinder/vendor',
        __DIR__ . '/pathfinder/tmp',
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPhpSets(php82: true)
    ->withPreparedSets(deadCode: false, codeQuality: false, deprecation: true);
```

### 3.2 Dry run
```bash
vendor/bin/rector process --dry-run > rector-dryrun.txt 2>&1
```
Read the output. Pay particular attention to:
- **Readonly promotion** on constructors — safe if the class isn't reflected or cloned
- **First-class callable** rewrites in `Controller/` — F3's `Base::` dynamic dispatch can look like a callable; verify each
- **Match expression** rewrites — usually safe but verify switches with fall-through
- **Null coalescing** rewrites — almost always safe

### 3.3 Add risky files to skip
If any specific files look dangerous in the dry-run (especially anything F3 calls reflectively), add to `->withSkip([...])`.

### 3.4 Apply
```bash
vendor/bin/rector process
```

### 3.5 Re-verify
```bash
vendor/bin/phpstan analyse --memory-limit=1G
```
Must still exit 0. If Rector introduced NEW errors, revert the specific file and add to skip list. Do not add new entries to the baseline — Rector output should be clean.

### 3.6 Rebuild and smoke-test
Per [.claude/CLAUDE.md](.claude/CLAUDE.md) workflow:
```bash
docker compose -f compose.dev.yml build --no-cache pf
docker compose -f compose.dev.yml up -d --force-recreate pf
docker logs -f pathfinder
```
Manual tests in browser:
- `/setup` renders without 500
- `/api/User/getEveServerStatus` (known-bad from backlog; may still 500 on Redis TTL — not your responsibility to fix here, just verify Rector didn't make it worse)
- Map load page
- Login flow

**STOP** — report smoke test results. Commit per submodule: `refactor: apply Rector PHP 8.2 upgrade rules`. Pointer-bump.

---

## Phase 4 — Manual behavioral review

Static tools don't catch behavioral semantic changes. Grep + review:

```bash
grep -rn --include='*.php' -E '==\s*["\x27]|["\x27]\s*==' pathfinder/app websocket/app > loose-compare-hits.txt
grep -rn --include='*.php' -E 'usort|uasort|uksort|\bsort\(' pathfinder/app websocket/app > sort-hits.txt
```

### 4.1 Loose comparisons
Read `loose-compare-hits.txt`. For each hit, ask:
- Is one side a numeric string like `"42"` and the other a non-numeric string? Pre-PHP 8, `0 == "foo"` was `true`. In PHP 8, it's `false`. This often flips auth / permission logic silently.
- If in doubt, change to `===` or explicit cast.

### 4.2 Sort stability
Pre-PHP 8 sorts were unstable; PHP 8 sorts are stable. If any code relies on a specific unstable order (rare but real — e.g. a secondary tiebreaker is assumed from insertion order), it may now sort differently. Review `sort-hits.txt` for any comparators that return 0 frequently.

### 4.3 Document
For each finding: either fix it in-place, or add to [PLAN.md](PLAN.md) as a deferred item with file/line reference.

**STOP** — report findings. Commit if any fixes: `fix: PHP 8 behavioral compatibility review`.

---

## Phase 5 — Dependency audit

```bash
cd pathfinder-containers && composer why-not php:8.2
cd pathfinder && composer why-not php:8.2
cd ../websocket && composer why-not php:8.2
cd ..
```

For any packages claiming <8.2:
- Check if a newer version supports 8.2 → `composer require vendor/pkg:^new-version`
- If unmaintained → add to [PLAN.md](PLAN.md) backlog
- If critical and no replacement → flag for user decision, do not decide unilaterally

**STOP** — report findings.

---

## Phase 6 — Changelog & wrap-up

Add to [changelog.md](changelog.md):
```markdown
## PHP 8 static analysis sweep

- Activated PHPCompatibility (PHPCS), PHPStan baseline, and Rector PHP 8.2 upgrade sets
- Fixed N errors from PHPCompatibility
- Fixed N compat-critical PHPStan hits (from M total baselined)
- Applied Rector php82 + deprecation rule sets across pathfinder/app and websocket/app
- Behavioral review: loose comparisons (N reviewed, M fixed), sort stability (N reviewed, M fixed)
- Dependency audit: ...
```

Add this section to [README.md](README.md) under a "Static analysis" heading:
```markdown
## Static analysis

```
vendor/bin/phpcs
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/rector process --dry-run
```

PHPStan uses a baseline (`phpstan-baseline.neon`) to freeze pre-existing level-8 debt. Do not expand the baseline — shrink it as issues are fixed.
```

Do NOT add CI integration — defer until the baseline is stable for a week.

---

## Verification checklist

Before declaring done:

- [ ] `vendor/bin/phpcs --standard=.phpcs.xml` exits 0 (warnings OK, errors not)
- [ ] `vendor/bin/phpstan analyse --memory-limit=1G` exits 0 with baseline active
- [ ] `vendor/bin/rector process --dry-run` prints "0 files would be changed"
- [ ] `grep -Ei 'ctype_digit|deprecated|offset .* does not exist' phpstan-report.txt` → zero matches
- [ ] `composer why-not php:8.2` clean in all three repos (or deferred items documented in PLAN.md)
- [ ] Dev container smoke test: `/setup`, map load, login all render without 500s
- [ ] `phpstan-baseline.neon` is smaller than when you started, not larger
- [ ] `websocket/composer.json` shows `"php": ">=8.2"`
- [ ] changelog.md entry added

## Critical files

- [.phpcs.xml](.phpcs.xml) — new
- [rector.php](rector.php) — rewritten to activate php82 sets
- [phpstan.neon](phpstan.neon) — add baseline include
- [phpstan-baseline.neon](phpstan-baseline.neon) — new, shrinks over time
- [composer.json](composer.json) — add PHPCS + PHPCompatibility to require-dev
- [websocket/composer.json](websocket/composer.json) — PHP >=8.2
- [changelog.md](changelog.md) — summary entry
- [PLAN.md](PLAN.md) — any deferred findings

## Known risks & watchouts

- **F3 magic dispatch**: `Base::` dynamic method calls look like callables to Rector. Review `Controller/` dry-run diffs carefully.
- **F3 template cache**: `pathfinder/tmp/*.php` MUST stay in Rector's skip list — regenerated artifacts, changes are wiped on rebuild.
- **No automated test suite**: Rector changes validate only via manual smoke tests. Keep commits small and atomic so revert is cheap.
- **Baseline blindspot**: pre-baselined lines won't re-alert. Acceptable for a compat sprint, but note in changelog that full level-8 cleanup is a separate future pass.
- **Reflection-autoloaded files**: `pathfinder/app/lib/` has files loaded by F3 reflection. Trust PHPStan's deprecation rule over Rector here.
- **Commit discipline**: Submodule pointer bumps are easy to forget. After committing in a submodule, always `git add` the submodule path in `pathfinder-containers` and commit with `Update {submodule} submodule: ...`.
