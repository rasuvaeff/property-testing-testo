<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Test;

/**
 * Fixture executed through the real Testo runner: every run is discarded, so
 * the property exhausts its discard budget and gives up instead of passing
 * vacuously.
 */
final class GaveUpFixture
{
    #[Test]
    #[Property(runs: 5, seed: 1, generators: 'ints', maxDiscards: 3)]
    public function neverChecksAnything(int $x): void
    {
        Assume::that(condition: false);
    }

    /** @return array<string, ArbitraryInterface> */
    private function ints(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}
