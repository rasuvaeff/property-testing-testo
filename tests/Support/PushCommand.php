<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * Pushes a value. Always applicable; the model is the list of stacked values.
 */
final readonly class PushCommand implements Command
{
    public function __construct(
        public int $value,
    ) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        \assert(is_array($model));

        return [...$model, $this->value];
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof StackSut);

        $system->push($this->value);

        return $system->size();
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert(is_array($model));

        return $result === count($model) + 1;
    }

    #[\Override]
    public function __toString(): string
    {
        return 'Push(' . $this->value . ')';
    }
}
