<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Support;

/**
 * A correct LIFO stack — the passing system under test.
 */
final class Stack implements StackSut
{
    /**
     * @var list<int>
     */
    private array $items = [];

    #[\Override]
    public function push(int $value): void
    {
        $this->items[] = $value;
    }

    #[\Override]
    public function pop(): int
    {
        $value = array_pop($this->items);

        if ($value === null) {
            throw new \UnderflowException('pop from empty stack');
        }

        return $value;
    }

    #[\Override]
    public function size(): int
    {
        return count($this->items);
    }
}
