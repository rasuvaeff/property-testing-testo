<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Support;

use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;

/**
 * The parts of the engine's contract this package accepts more than one
 * spelling of, while its constraint spans two core lines.
 *
 * A characterization pins what the engine does, so it has to keep pinning it
 * across a core release that renames a field or a class. Asking the installed
 * engine which name it uses keeps the assertion exact on both — the
 * alternative, weakening it to "some RuntimeException", stops checking the
 * thing the test exists for.
 *
 * Every helper here is temporary by construction: it disappears when the
 * constraint narrows to one core line again.
 */
final class CoreCompat
{
    /** @var ?class-string<\Throwable> */
    private static ?string $generationExhausted = null;

    private function __construct()
    {
        // Static helpers; not instantiable.
    }

    /**
     * The exception a bounded-attempt generator gives up with. Named
     * `GenerationExhausted` up to core 0.9, `GenerationExhaustedException` from
     * 0.10 — the suffix every other public exception already carried.
     *
     * Provoked rather than named: a literal for the older spelling would be a
     * reference to a class that no longer exists on the newer engine, and the
     * engine is the authority on its own name either way.
     *
     * @return class-string<\Throwable>
     */
    public static function generationExhausted(): string
    {
        if (self::$generationExhausted !== null) {
            return self::$generationExhausted;
        }

        try {
            Gen::filter(Gen::int(), static fn(mixed $value): bool => false)->generate(new Random(1));
        } catch (\RuntimeException $exhausted) {
            return self::$generationExhausted = $exhausted::class;
        }

        throw new \LogicException('Gen::filter() with an impossible predicate no longer exhausts');
    }

    /**
     * Runs a counterexample discarded through `Assume::that()` before the
     * failure. Core 0.9 reports them as `skips`; 0.10 renames the field to
     * `discards` and gives `skips` to the environmental skips it started
     * counting separately — the same word, two quantities, which is precisely
     * why reading it through the machine-readable form is the safe way across.
     */
    public static function discardsBeforeFailure(CounterExample $counterExample): int
    {
        $fields = $counterExample->toArray();
        $discards = $fields['discards'] ?? $fields['skips'] ?? null;

        \assert(is_int($discards));

        return $discards;
    }
}
