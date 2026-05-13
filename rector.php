<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/pathfinder/app',
        __DIR__ . '/websocket/app',
    ])
    ->withPhpVersion(\Rector\ValueObject\PhpVersion::PHP_83)
    ->withPhpSets(php83: true)
    ->withSkip([
        __DIR__ . '/pathfinder/vendor',
        __DIR__ . '/websocket/vendor',
    ]);
