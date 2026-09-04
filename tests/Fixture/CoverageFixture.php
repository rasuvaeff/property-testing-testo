<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Test;

/**
 * Fixture executed through the real Testo runner: every run passes but the
 * required coverage label never occurs, so the vacuous pass must fail.
 */
final class CoverageFixture
{
    #[Test]
    #[Property(runs: 5, seed: 1, generators: 'ints')]
    public function coversValuesAboveAThousand(int $x): void
    {
        Classify::cover($x > 1000, 'big', 50.0);
        Assert::true($x <= 10);
    }

    /**
     * Public but not static on purpose: this is the one fixture covering the
     * instance-method form of a generators provider, which the interceptor
     * binds to the running case instance. Everywhere else, prefer static.
     *
     * @return array<string, ArbitraryInterface>
     */
    public function ints(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}
