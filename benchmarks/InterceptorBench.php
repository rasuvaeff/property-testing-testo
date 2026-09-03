<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Benchmarks;

use Testo\Bench;
use Testo\Testing\Attribute\TestingSuite;
use Testo\Testing\Helper\TestRunner;

#[TestingSuite(path: __DIR__ . '/BenchPropertyFixture.php')]
final class InterceptorBench
{
    #[Bench(['baseline' => [self::class, 'runProperty']], calls: 20, iterations: 3, tolerance: \INF)]
    public static function passingPropertyThroughTheRealPipeline(): void
    {
        TestRunner::runTest([BenchPropertyFixture::class, 'sumIsCommutative']);
    }

    public static function runProperty(): void
    {
        TestRunner::runTest([BenchPropertyFixture::class, 'sumIsCommutative']);
    }
}
