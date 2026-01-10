<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\BooleanNot\ReplaceMultipleBooleanNotRector;
use Rector\CodeQuality\Rector\ClassMethod\ExplicitReturnNullRector;
use Rector\CodeQuality\Rector\Empty_\SimplifyEmptyCheckOnEmptyArrayRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\EarlyReturn\Rector\Return_\ReturnBinaryOrToEarlyReturnRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return RectorConfig::configure()
    ->withCache(__DIR__ . '/.rector-cache')
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/example',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withSkip([
        // Skip adding explicit return null - can be noisy
        ExplicitReturnNullRector::class,

        // Skip empty array simplification - current code is clear
        SimplifyEmptyCheckOnEmptyArrayRector::class,

        // Skip removing empty methods - may be intentional in tests
        RemoveEmptyClassMethodRector::class => [
            __DIR__ . '/tests',
        ],

        // Skip always-true conditions in tests - often intentional for clarity
        RemoveAlwaysTrueIfConditionRector::class => [
            __DIR__ . '/tests',
        ],

        // Skip #[Override] attribute - adds noise for minimal benefit
        AddOverrideAttributeToOverriddenMethodsRector::class,

        // Skip changing !== null to instanceof - more verbose with FQCNs
        FlipTypeControlToUseExclusiveTypeRector::class,

        // Skip breaking up return $a || $b - less readable for simple boolean logic
        ReturnBinaryOrToEarlyReturnRector::class,

        // Skip double negation removal - sometimes intentional for boolean coercion
        ReplaceMultipleBooleanNotRector::class,
    ])
    ->withRules([
        // Ensure all files have strict types
        DeclareStrictTypesRector::class,
    ]);
