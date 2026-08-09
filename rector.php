<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withRules([AddNameToLiteralArgumentRector::class])
    // The test suite is reflection-driven by design: #[Property] generator
    // methods and stub test bodies are invoked by name via reflection, so
    // Rector's static dead-code analysis cannot see those call sites and would
    // strip them. Exempt the dead-code rules that would break those fixtures.
    ->withSkip([
        RemoveUnusedPrivateMethodRector::class => [__DIR__ . '/tests'],
        RemoveEmptyClassMethodRector::class => [__DIR__ . '/tests'],
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__ . '/tests'],
        // `@var mixed` on assignments from mixed-returning generators is
        // load-bearing: it suppresses Psalm's MixedAssignment. Not useless.
        RemoveUselessVarTagRector::class,
    ]);
