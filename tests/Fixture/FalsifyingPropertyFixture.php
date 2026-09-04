<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Test;

/**
 * Fixture executed through the real Testo runner by
 * {@see \Rasuvaeff\PropertyTesting\Testo\Tests\PropertyRunnerE2ETest}.
 *
 * It lives under tests/Fixture and is excluded from the Unit suite (see
 * testo.php) because its #[Property] method fails on purpose: the Unit suite
 * must stay green, while the e2e test runs it in a nested application and
 * asserts on the captured TestResult.
 */
final class FalsifyingPropertyFixture
{
    #[Test]
    #[Property(runs: 20, seed: 20260629, generators: 'ints')]
    public function everyValueIsAtMostFifty(int $x): void
    {
        Assert::true($x <= 50, 'value must be <= 50');
    }

    /** @return array<string, ArbitraryInterface> */
    public static function ints(): array
    {
        return ['x' => Gen::intBetween(51, 100)];
    }
}
