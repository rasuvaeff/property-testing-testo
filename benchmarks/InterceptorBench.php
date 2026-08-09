<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Benchmarks;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Bench;
use Testo\Testing\Helper\TestRunner;

final class InterceptorBench
{
    #[Bench([], calls: 20, iterations: 3)]
    public static function passingPropertyThroughTheRealPipeline(): void
    {
        TestRunner::runTest([BenchPropertyFixture::class, 'sumIsCommutative']);
    }
}

/**
 * @internal
 */
final class BenchPropertyFixture
{
    #[\Testo\Test]
    #[Property(runs: 50, seed: 1)]
    public function sumIsCommutative(int $a, int $b): void
    {
        \Testo\Assert::same($a + $b, $b + $a);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function sumIsCommutativeGenerators(): array
    {
        return ['a' => Gen::intBetween(-1_000, 1_000), 'b' => Gen::intBetween(-1_000, 1_000)];
    }
}
