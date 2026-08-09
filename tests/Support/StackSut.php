<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Support;

/**
 * The system under test for the stateful stack example: a last-in-first-out
 * stack of ints. {@see Stack} implements it correctly; {@see BuggyStack} pops in
 * FIFO order to give the property runner something to falsify.
 */
interface StackSut
{
    public function push(int $value): void;

    public function pop(): int;

    public function size(): int;
}
