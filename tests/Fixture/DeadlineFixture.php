<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Test;

/**
 * Fixture executed through the real Testo runner: the body sleeps well past
 * the per-run deadline.
 */
final class DeadlineFixture
{
    #[Test]
    #[Property(runs: 3, seed: 1, generators: 'ints', timeoutMs: 5)]
    public function everyRunIsSlow(int $x): void
    {
        usleep(20_000);
    }

    /** @return array<string, ArbitraryInterface> */
    private function ints(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}
