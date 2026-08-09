<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Testo\Test;

/**
 * Stateful / model-based testing: instead of one input, a property drives a
 * whole *sequence* of operations against a system under test, comparing each
 * result to a simplified model. When it fails, the runner shrinks the failing
 * sequence to the shortest one that still breaks. Run it through Testo:
 *
 *   docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/testo
 *
 * Executing this file directly with `php` only defines the classes and prints
 * the hint at the bottom — the property runs under Testo.
 */

/**
 * The system under test: a small last-in-first-out stack of ints.
 */
final class ExampleStack
{
    /** @var list<int> */
    private array $items = [];

    public function push(int $value): void
    {
        $this->items[] = $value;
    }

    public function pop(): int
    {
        $value = array_pop($this->items);

        if ($value === null) {
            throw new \UnderflowException('pop from empty stack');
        }

        return $value;
    }

    public function size(): int
    {
        return count($this->items);
    }
}

/**
 * Push a value. Always applicable; the model is the list of stacked values.
 */
final readonly class Push implements Command
{
    public function __construct(private int $value) {}

    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function nextState(mixed $model): mixed
    {
        \assert(is_array($model));

        return [...$model, $this->value];
    }

    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof ExampleStack);

        $system->push($this->value);

        return $system->size();
    }

    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert(is_array($model));

        return $result === count($model) + 1;
    }

    public function __toString(): string
    {
        return 'Push(' . $this->value . ')';
    }
}

/**
 * Pop the top value. Applicable only on a non-empty model; the postcondition
 * asserts LIFO order.
 */
final readonly class Pop implements Command
{
    public function preCondition(mixed $model): bool
    {
        \assert(is_array($model));

        return $model !== [];
    }

    public function nextState(mixed $model): mixed
    {
        \assert(is_array($model));

        return array_slice($model, 0, -1);
    }

    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof ExampleStack);

        return $system->pop();
    }

    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert(is_array($model) && $model !== []);

        return $result === $model[array_key_last($model)];
    }

    public function __toString(): string
    {
        return 'Pop()';
    }
}

#[Test]
final class StackStateMachineProperties
{
    /**
     * Any valid sequence of pushes and pops keeps the real stack in step with
     * the model. The initial model is the empty stack ([]); each generated
     * command carries its own precondition, model transition and postcondition.
     */
    #[Property(runs: 200)]
    public function stackBehavesLikeItsModel(CommandSequence $sequence): void
    {
        StateMachine::check($sequence, static fn(): ExampleStack => new ExampleStack());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function stackBehavesLikeItsModelGenerators(): array
    {
        return [
            'sequence' => Gen::commands([], [
                Gen::map(Gen::intBetween(0, 99), static fn(mixed $v): Push => new Push((int) $v)),
                Gen::constant(new Pop()),
            ]),
        ];
    }
}

echo 'Defined ' . StackStateMachineProperties::class . " — run the properties with: vendor/bin/testo\n";
