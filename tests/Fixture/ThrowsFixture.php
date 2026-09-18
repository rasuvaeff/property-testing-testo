<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Test;

/**
 * Fixture executed through the real Testo runner by
 * {@see \Rasuvaeff\PropertyTesting\Testo\Tests\PropertyRunnerE2ETest}: the
 * `throws:` expectation against bodies that only throw — no `Assert::` call,
 * so the fulfilled expectation alone must keep the aggregate from being risky.
 */
final class ThrowsFixture
{
    #[Test]
    #[Property(runs: 5, seed: 1, generators: 'ints', throws: \DomainException::class)]
    public function everyRunThrowsTheExpectedClass(int $x): never
    {
        throw new \DomainException('value ' . $x);
    }

    #[Test]
    #[Property(runs: 5, seed: 1, generators: 'ints', throws: \DomainException::class)]
    public function noRunThrows(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function ints(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}
