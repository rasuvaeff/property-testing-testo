<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Support;

use Rasuvaeff\PropertyTesting\Runner\Clock;

/**
 * Deterministic clock for deadline/budget characterization: every reading
 * advances by a fixed step, so "elapsed" time is an exact function of how many
 * times the interceptor consulted the clock — no sleeping, no flakiness.
 */
final class FakeClock implements Clock
{
    private int $now = 0;

    public function __construct(
        private readonly int $stepNs,
    ) {}

    #[\Override]
    public function nanoseconds(): int
    {
        $reading = $this->now;
        $this->now += $this->stepNs;

        return $reading;
    }
}
