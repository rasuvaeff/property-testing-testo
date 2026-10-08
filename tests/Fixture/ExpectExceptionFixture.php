<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;

/**
 * Fixture executed through the real Testo runner by
 * {@see \Rasuvaeff\PropertyTesting\Testo\Tests\PropertyRunnerE2ETest}.
 *
 * Exercises Testo exception expectations inside and around the property loop.
 * Excluded from the Unit suite like every fixture here.
 */
final class ExpectExceptionFixture
{
    #[Test]
    #[Property(runs: 5, seed: 1, generators: 'ints')]
    #[ExpectException(\RuntimeException::class)]
    public function expectsARuntimeExceptionButFailsAnAssertion(int $x): void
    {
        Assert::true(actual: false, message: 'never holds');
    }

    #[Test]
    #[Property(runs: 3, seed: 1, generators: 'ints')]
    #[ExpectException(\RuntimeException::class)]
    public function throwsTheExpectedException(int $x): never
    {
        throw new \RuntimeException('expected');
    }

    /** @return array<string, ArbitraryInterface> */
    public static function ints(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}
