<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use Rasuvaeff\PropertyTesting\Testo\PropertyInterceptor;
use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Marks a test method as a property: the {@see PropertyInterceptor} takes over,
 * generating random arguments from a generators method until the property has
 * completed {@see $runs} successful checks or exhausted its discard budget.
 *
 * Attribute arguments in PHP must be constant expressions, so the generators
 * cannot be passed inline. Instead name a method (on the same test case) that
 * returns `array<string, ArbitraryInterface>`, keyed by parameter name. When
 * {@see $generators} is null the runner falls back to a method named
 * `<testMethod>Generators`.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
#[FallbackInterceptor(PropertyInterceptor::class)]
final readonly class Property implements Interceptable
{
    /**
     * @param int $runs Number of successful random inputs to check. Discarded inputs do not count.
     * @param ?int $seed Fixed seed for reproducibility. Omit to let the runner pick a random one
     *        (the failing seed is reported by {@see PropertyViolationException}).
     * @param ?string $generators Method name returning array<string, ArbitraryInterface>.
     *        Defaults to `<testMethod>Generators`.
     * @param ?int $maxShrinks Cap on the number of accepted shrink steps. Null (default) means
     *        no cap. 0 disables shrinking, reporting the original counterexample unchanged.
     * @param ?string $examples Method name returning iterable<array<mixed>> of fixed positional
     *        argument tuples, each run (before the random inputs) as an explicit example.
     *        Defaults to `<testMethod>Examples` when that method exists.
     * @param ?int $maxDiscards Maximum number of discarded inputs before the property gives up.
     *        Null (default) uses ten times the resolved run count.
     * @param ?int $timeoutMs Wall-clock deadline for a single run (random or example) in
     *        milliseconds. A body that takes longer fails the property with a
     *        {@see DeadlineExceededException} naming the offending input — protection against
     *        pathological inputs (catastrophic regex, deep recursion, unbounded backoff).
     *        Measured after the run returns, so a body that never returns cannot be
     *        interrupted; shrink trials are not measured. Null (default) disables the deadline.
     * @param ?int $budgetMs Wall-clock budget for the whole random phase in milliseconds.
     *        When it runs out before {@see $runs} successful checks complete, the property
     *        fails with a {@see TimeBudgetExceededException}. Null (default) disables the budget.
     */
    public function __construct(
        public int $runs = 100,
        public ?int $seed = null,
        public ?string $generators = null,
        public ?int $maxShrinks = null,
        public ?string $examples = null,
        public ?int $maxDiscards = null,
        public ?int $timeoutMs = null,
        public ?int $budgetMs = null,
    ) {
        if ($runs < 1) {
            throw new \InvalidArgumentException('Runs must be greater than or equal to 1');
        }
        if ($maxShrinks !== null && $maxShrinks < 0) {
            throw new \InvalidArgumentException('Max shrinks must be greater than or equal to 0');
        }
        if ($maxDiscards !== null && $maxDiscards < 0) {
            throw new \InvalidArgumentException('Max discards must be greater than or equal to 0');
        }
        if ($timeoutMs !== null && $timeoutMs < 1) {
            throw new \InvalidArgumentException('Timeout must be greater than or equal to 1 millisecond');
        }
        if ($budgetMs !== null && $budgetMs < 1) {
            throw new \InvalidArgumentException('Budget must be greater than or equal to 1 millisecond');
        }
    }
}
