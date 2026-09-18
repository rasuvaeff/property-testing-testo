<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting;

use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\ShrinkMode;
use Rasuvaeff\PropertyTesting\Testo\PropertyInterceptor;
use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Marks a test method as a property: the {@see PropertyInterceptor} takes over,
 * generating random arguments from a generators method until the property has
 * completed {@see $runs} successful checks or exhausted its discard budget.
 *
 * Attribute arguments in PHP must be constant expressions. A provider can be
 * a method name, any callable accepted by an attribute expression, or an
 * invokable provider object; callable providers return
 * `array<string, ArbitraryInterface>`, keyed by parameter name. When
 * {@see $generators} is null the runner falls back to a method named
 * `<testMethod>Generators`.
 *
 * With {@see $auto} the provider becomes optional: parameters it does not
 * cover are derived from the property's own signature through
 * {@see Gen::forParameters()} — the `@param` psalm type when there is one,
 * the native type otherwise.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
#[FallbackInterceptor(PropertyInterceptor::class)]
final readonly class Property implements Interceptable
{
    /**
     * A non-callable array is kept as written so that the interceptor can
     * refuse it by name; the attribute itself validates nothing.
     *
     * @var \Closure|array<array-key, mixed>|string|null
     */
    public \Closure|array|string|null $generators;

    /** @var \Closure|array<array-key, mixed>|string|null */
    public \Closure|array|string|null $examples;

    /**
     * @param int $runs Number of successful random inputs to check. Discarded inputs do not count.
     * @param ?int $seed Fixed seed for reproducibility. Omit to let the runner pick a random one
     *        (the failing seed is reported by {@see PropertyViolationException}).
     * @param (callable(): array<string, ArbitraryInterface>)|array<array-key, mixed>|string|null $generators
     *        Method name or callable returning array<string, ArbitraryInterface>. Defaults to
     *        `<testMethod>Generators`.
     * @param ?int $maxShrinks Cap on the number of accepted shrink steps. Null (default) means
     *        no cap. 0 disables shrinking, reporting the original counterexample unchanged.
     * @param (callable(): iterable<array<mixed>>)|array<array-key, mixed>|string|null $examples
     *        Method name or callable returning fixed positional argument tuples, each run (before
     *        the random inputs) as an explicit example. Defaults to `<testMethod>Examples` when
     *        that method exists.
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
     * @param ?ShrinkMode $shrink How hard to minimise a counterexample: {@see ShrinkMode::Full}
     *        (the default), {@see ShrinkMode::Off} to report the input as generated, or
     *        {@see ShrinkMode::Bounded} together with $shrinkBudgetMs.
     * @param ?int $shrinkBudgetMs Wall-clock budget for the shrink descent in milliseconds — the
     *        one knob here that costs determinism, since how far the descent gets depends on how
     *        long the body takes. It answers "the descent hung", not "reproduce this exactly".
     * @param ?list<Phase> $phases Stages this property performs, in run order. Null (default) runs
     *        all of them; a subset trades coverage for time on purpose.
     * @param bool $derandomize Derives an unset seed from the property id instead of drawing one,
     *        so the same property on the same code always selects the same inputs. An explicit
     *        $seed still wins.
     * @param EdgeCases $edgeCases Whether the numeric generators keep biasing toward their boundary
     *        values ({@see EdgeCases::Mixin}, the default) or generate uniformly
     *        ({@see EdgeCases::None}). Turn them off when the edges are what this property cannot
     *        use — a body discarding `0`, a range end that violates a precondition — so the discard
     *        budget stops paying for one run in five.
     * @param ?string $path A recorded shrink descent (`CounterExample::$path`) followed instead of
     *        searched for again. It needs the $seed of the run that produced it — the steps mean
     *        nothing against another one — and it is a debugging aid, not a fixture: editing a
     *        generator orphans it, which is what the regression corpus is for.
     * @param bool $auto Derive a generator from the property's signature for every parameter the
     *        provider does not cover — the `@param` psalm type when there is one (`int<1, 300>`
     *        beats a bare `int`), the native type otherwise, and an error naming the parameter for
     *        anything unreadable. The provider (explicit or conventional) becomes the overrides and
     *        may be partial; it may also cover everything, in which case auto derives nothing.
     *        Deliberately opt-in and deliberately without an environment knob: the environment
     *        dials the suite, while this changes what one property's arguments mean.
     * @param ?class-string<\Throwable> $throws The exception class every run must throw. A run that
     *        throws it (or a subclass) passes; one that returns normally fails with
     *        `Expected <class> to be thrown, but it was not` and shrinks like any other
     *        counterexample; one that throws another class fails with that throw. A skip and an
     *        `Assume::that()` discard keep their meaning — never a pass earned by throwing. This is
     *        the per-run replacement for `#[ExpectException]`, which observes the aggregate result
     *        and is refused on a property. The matching throw is recorded as an assertion, so a
     *        body that asserts nothing else is not reported as risky.
     */
    public function __construct(
        public int $runs = 100,
        public ?int $seed = null,
        callable|array|string|null $generators = null,
        public ?int $maxShrinks = null,
        callable|array|string|null $examples = null,
        public ?int $maxDiscards = null,
        public ?int $timeoutMs = null,
        public ?int $budgetMs = null,
        public ?ShrinkMode $shrink = null,
        public ?int $shrinkBudgetMs = null,
        public ?array $phases = null,
        public bool $derandomize = false,
        public ?string $path = null,
        public EdgeCases $edgeCases = EdgeCases::Mixin,
        public bool $auto = false,
        // Last on purpose: a parameter added anywhere else moves the ones
        // after it, and every attribute passing them positionally would
        // silently mean something else. New parameters append here.
        public ?string $throws = null,
    ) {
        // A data holder: every value is validated by the interceptor, which
        // can name the property. Testo instantiates the attribute long before
        // the interceptor runs, and a constructor that throws aborts the
        // pipeline with the reason buried in `previous`.
        $this->generators = $this->provider($generators);
        $this->examples = $this->provider($examples);
    }

    /**
     * @param callable|array<array-key, mixed>|string|null $provider
     * @return \Closure|array<array-key, mixed>|string|null
     */
    private function provider(callable|array|string|null $provider): \Closure|array|string|null
    {
        if (\is_callable($provider) && !\is_string($provider)) {
            return \Closure::fromCallable($provider);
        }

        return $provider;
    }
}
