<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Test;

/** A diagnostic from any input fails and shrinks to the generator's minimum. */
final class DiagnosticFixture
{
    #[Test]
    #[Property(runs: 5, seed: 1, generators: 'ints', failOn: E_USER_WARNING)]
    public function everyInputRaisesADiagnostic(int $value): void
    {
        trigger_error('diagnostic for ' . $value, E_USER_WARNING);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function ints(): array
    {
        return ['value' => Gen::intBetween(1, 10)];
    }
}
