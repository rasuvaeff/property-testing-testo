<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * Pops the top value. Applicable only when the model is non-empty; the
 * postcondition asserts LIFO order (the popped value equals the last pushed).
 */
final readonly class PopCommand implements Command
{
    #[\Override]
    public function preCondition(mixed $model): bool
    {
        \assert(is_array($model));

        return $model !== [];
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        \assert(is_array($model));

        return array_slice($model, 0, -1);
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof StackSut);

        return $system->pop();
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert(is_array($model) && $model !== []);

        return $result === $model[array_key_last($model)];
    }

    #[\Override]
    public function __toString(): string
    {
        return 'Pop()';
    }
}
