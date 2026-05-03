# TODO


## Readme updates 
- Review Claude changes to Readmes


## Known bugs / deferred fixes

- **f3-cortex: adopt tagged release**: `ikkez/f3-cortex` is pinned to `dev-master#47d2596` (2025-07-08) because three PHP 8.2 type-hint fixes landed after the `v1.7.8` tag. Once a `v1.7.9`+ tag is published, switch `composer.json` to `"1.7.*"` and drop the commit hash.


- **assess upgrade to php 8.4**: `trafex/php-nginx:3.8.0+` ships PHP 8.4. Currently pinned to 3.6.0 (PHP 8.3) because all higher tags jumped straight to 8.4+. Alpine package CVEs are mitigated by `apk upgrade --no-cache` in the Dockerfile. When the app is ready for PHP 8.4 compatibility work, bump the base image tag and update all `php83-*` package installs to `php84-*`.



