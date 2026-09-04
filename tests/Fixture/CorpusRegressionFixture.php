<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Test;

/**
 * Fixture executed through the real Testo runner to exercise the corpus
 * record -> replay -> prune cycle end to end.
 *
 * The property fails for every generated value until the driving e2e test sets
 * `FIXTURE_PASS` — the "bug" is then fixed, the recorded regression replays
 * green and is pruned. No seed on purpose: a pinned seed disables replay.
 */
final class CorpusRegressionFixture
{
    #[Test]
    #[Property(runs: 3, generators: 'ints')]
    public function everyValueIsAtMostFifty(int $x): void
    {
        Assert::true(getenv('FIXTURE_PASS') !== false || $x <= 50, 'value must be <= 50');
    }

    /** @return array<string, ArbitraryInterface> */
    public static function ints(): array
    {
        return ['x' => Gen::intBetween(51, 100)];
    }
}
