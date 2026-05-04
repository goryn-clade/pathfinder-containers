# TODO


## Readme updates 
- Review Claude changes to Readmes

## Security testing: 

- test commit 1ace60ff
```bash
# Force an Sso exception (e.g. by mangling the SSO callback URL with a bad code parameter)
# while DEBUG=0. Inspect the JSON response body:
# Expect: text="An internal error occurred", no "$e->getMessage()" content visible.
```
## Validate eve-universe database 
Check systems like Zarzakh are in the database and in the SDE seed file. it seems database at pfdev is a good example.

## Known bugs / deferred fixes

- **f3-cortex: adopt tagged release**: `ikkez/f3-cortex` is pinned to `dev-master#47d2596` (2025-07-08) because three PHP 8.2 type-hint fixes landed after the `v1.7.8` tag. Once a `v1.7.9`+ tag is published, switch `composer.json` to `"1.7.*"` and drop the commit hash.

- **assess upgrade to php 8.4**: `trafex/php-nginx:3.8.0+` ships PHP 8.4. Currently pinned to 3.6.0 (PHP 8.3) because all higher tags jumped straight to 8.4+. Alpine package CVEs are mitigated by `apk upgrade --no-cache` in the Dockerfile. When the app is ready for PHP 8.4 compatibility work, bump the base image tag and update all `php83-*` package installs to `php84-*`.

- **setup page cron issue*
pathfinder   | 2026-05-04T07:59:19.836942976Z NOTICE: PHP message: Undefined array key "length"
pathfinder   | 2026-05-04T07:59:19.837139721Z NOTICE: PHP message: [app/Cron/Universe.php:319]
pathfinder   | 2026-05-04T07:59:19.837168635Z NOTICE: PHP message: [app/Cron/Universe.php:319] Base->{closure}()
pathfinder   | 2026-05-04T07:59:19.837197550Z NOTICE: PHP message: [vendor/xfra35/f3-cron/lib/cron.php:129] Exodus4D\Pathfinder\Cron\Universe->updateSovereigntyData()
pathfinder   | 2026-05-04T07:59:19.837219201Z NOTICE: PHP message: [app/Lib/Cron.php:48] Cron->execute()
pathfinder   | 2026-05-04T07:59:19.837242808Z NOTICE: PHP message: [app/Controller/Api/Setup.php:82] Exodus4D\Pathfinder\Lib\Cron->execute()
pathfinder   | 2026-05-04T07:59:19.837271303Z NOTICE: PHP message: [vendor/bcosca/fatfree-core/base.php:2081] Exodus4D\Pathfinder\Controller\Api\Setup->cronExecute()
pathfinder   | 2026-05-04T07:59:19.837293164Z NOTICE: PHP message: [vendor/bcosca/fatfree-core/base.php:1881] Base->call()
pathfinder   | 2026-05-04T07:59:19.837395273Z NOTICE: PHP message: [index.php:27] Base->run()
