<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

use Rasuvaeff\PropertyTesting\AssumptionSkipped;
use Rasuvaeff\PropertyTesting\Runner\TrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\TrialOutcome;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;

/**
 * Executes the property body through Testo's interceptor pipeline and folds
 * each {@see TestResult} into the engine's {@see TrialOutcome}: an
 * {@see AssumptionSkipped} failure is a discard, a failing status is a
 * failure, anything else passes.
 *
 * It also aggregates every run's result attributes (merged run-by-run, last
 * write per key wins). Downstream interceptors attach per-run attributes to
 * each run's TestResult — e.g. Testo's codecov plugin stores its
 * CoverageResult there. The aggregate result the property reports must carry
 * them, otherwise the property test vanishes from per-test coverage and
 * consumers like Infection never select it for mutants.
 *
 * @internal
 */
final class TestoTrialExecutor implements TrialExecutor
{
    /** @var array<non-empty-string, mixed> */
    private array $attributes = [];

    /**
     * @param \Closure(TestInfo): TestResult $next
     */
    public function __construct(
        private readonly TestInfo $info,
        private readonly \Closure $next,
    ) {}

    #[\Override]
    public function execute(array $arguments): TrialOutcome
    {
        $result = ($this->next)($this->info->with(arguments: array_values($arguments)));
        $this->attributes = array_merge($this->attributes, $result->attributes);

        if ($result->failure instanceof AssumptionSkipped) {
            return TrialOutcome::discarded();
        }

        if ($result->status->isFailure()) {
            return TrialOutcome::failed($result->failure);
        }

        return TrialOutcome::passed();
    }

    /**
     * The attributes of every executed run, merged in execution order.
     *
     * @return array<non-empty-string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
