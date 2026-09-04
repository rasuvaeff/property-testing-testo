<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Test;

/**
 * Fixture executed through the real Testo runner: the explicit example fails
 * before the random phase ever starts.
 */
final class ExampleFailingFixture
{
    #[Test]
    #[Property(runs: 2, seed: 1, generators: 'ints')]
    public function everyValueIsSmall(int $x): void
    {
        Assert::true($x < 100, 'too big');
    }

    /** @return array<string, ArbitraryInterface> */
    public static function ints(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }

    /** @return list<list<int>> */
    public static function everyValueIsSmallExamples(): array
    {
        return [[100]];
    }
}
