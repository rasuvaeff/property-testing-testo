<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Test;

/**
 * Fixture executed through the real Testo runner: the filter rejects every
 * generated value, so generation exhausts and the run fails cleanly instead of
 * crashing.
 */
final class ExhaustedFixture
{
    #[Test]
    #[Property(runs: 1, seed: 1, generators: 'impossible')]
    public function neverGetsAValue(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function impossible(): array
    {
        return ['x' => Gen::filter(Gen::intBetween(1, 10), static fn(int $n): bool => $n > 10)];
    }
}
