<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\ClassMethod\BoolReturnTypeFromBooleanStrictReturnsRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NumericReturnTypeFromStrictScalarReturnsRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnNewRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnCastRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictConstantReturnRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnDirectArrayRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnNullableTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnTypeDeclarationBasedOnParentClassMethodRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/pathfinder/app',
        __DIR__ . '/websocket/app',
    ])
    ->withPhpVersion(\Rector\ValueObject\PhpVersion::PHP_83)
    ->withPhpSets(php83: true)
    ->withRules([
        AddVoidReturnTypeWhereNoReturnRector::class,
        BoolReturnTypeFromBooleanStrictReturnsRector::class,
        NumericReturnTypeFromStrictScalarReturnsRector::class,
        ReturnTypeFromReturnNewRector::class,
        ReturnTypeFromReturnCastRector::class,
        ReturnTypeFromStrictConstantReturnRector::class,
        ReturnTypeFromReturnDirectArrayRector::class,
        ReturnNullableTypeRector::class,
        AddReturnTypeDeclarationBasedOnParentClassMethodRector::class,
    ])
    ->withSkip([
        __DIR__ . '/pathfinder/vendor',
        __DIR__ . '/websocket/vendor',
    ]);
