<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Support;

/**
 * A stack with a planted bug: it pops in FIFO order instead of LIFO. The
 * postcondition of {@see PopCommand} (LIFO top) is violated as soon as two
 * distinct values sit on the stack, so a stateful property falsifies it.
 */
final class BuggyStack implements StackSut
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
        // BUG: returns the oldest element (FIFO) rather than the newest (LIFO).
        $value = array_shift($this->items);

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
