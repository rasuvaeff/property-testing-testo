<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Fixture;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\BuggyStack;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\PopCommand;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\PushCommand;
use Testo\Test;

/**
 * Stateful fixture executed through the real Testo runner by
 * {@see \Rasuvaeff\PropertyTesting\Testo\Tests\StatefulE2ETest}.
 *
 * The property drives generated command sequences against a {@see BuggyStack}
 * (FIFO where LIFO is expected), so it fails on purpose and the runner shrinks
 * the counterexample to the minimal failing sequence (Push, Push, Pop).
 */
final class StatefulPropertyFixture
{
    #[Test]
    #[Property(runs: 100, seed: 42, generators: 'stackCommands')]
    public function buggyStackPreservesLifoOrder(CommandSequence $sequence): void
    {
        StateMachine::check($sequence, static fn(): BuggyStack => new BuggyStack());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function stackCommands(): array
    {
        return [
            'sequence' => Gen::commands([], [
                Gen::map(Gen::intBetween(0, 5), static fn(mixed $v): PushCommand => new PushCommand((int) $v)),
                Gen::constant(new PopCommand()),
            ]),
        ];
    }
}
