<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Test;

/**
 * Fixture executed through the real Testo runner by
 * {@see \Rasuvaeff\PropertyTesting\Testo\Tests\PropertyRunnerE2ETest}.
 *
 * The property holds only for positive draws; non-positive ones are discarded
 * via {@see Assume::that()}, so the test passes. Excluded from the Unit suite
 * (see testo.php) for symmetry with the falsifying fixture beside it.
 */
final class AssumeDiscardFixture
{
    #[Test]
    #[Property(runs: 100, seed: 20260629, generators: 'ints')]
    public function holdsOnlyForPositiveValues(int $x): void
    {
        Assume::that($x > 0);

        Assert::true($x > 0);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function ints(): array
    {
        return ['x' => Gen::intBetween(-50, 50)];
    }
}
