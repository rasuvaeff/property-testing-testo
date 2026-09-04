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
 * {@see \Rasuvaeff\PropertyTesting\Testo\Tests\PropertyRunnerE2ETest}: an in-body
 * draw whose domain depends on the generated parameter. The property fails
 * for any drawn index above 3, so the counterexample must shrink both the
 * parameter (to its lower bound) and the drawn value (to 4).
 */
final class DrawPropertyFixture
{
    #[Test]
    #[Property(runs: 20, seed: 20260710, generators: 'sizes')]
    public function everyDrawnIndexIsSmall(int $size): void
    {
        $index = Gen::draw(Gen::intBetween(0, $size));

        Assert::true($index <= 3, 'drawn index must be <= 3');
    }

    /** @return array<string, ArbitraryInterface> */
    public static function sizes(): array
    {
        return ['size' => Gen::intBetween(10, 50)];
    }
}
