<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

use Rasuvaeff\PropertyTesting\AssumptionSkipped;
use Rasuvaeff\PropertyTesting\Runner\TrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\TrialOutcome;
use Testo\Codecov\Result\CoverageResult;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Value\Status;

/**
 * Executes the property body through Testo's interceptor pipeline and folds
 * each {@see TestResult} into the engine's {@see TrialOutcome}: an
 * {@see AssumptionSkipped} failure or a per-run skip/cancel is a discard, a
 * failing status is a failure, anything else passes.
 *
 * It also aggregates every run's result attributes. Downstream interceptors
 * attach per-run attributes to each run's TestResult — e.g. Testo's codecov
 * plugin stores its {@see CoverageResult} there. The aggregate result the
 * property reports must carry them, otherwise the property test vanishes from
 * per-test coverage and consumers like Infection never select it for mutants.
 * Two keys are combined rather than overwritten: coverage merges across runs
 * (each run reports only the lines it executed) and `duration` sums (the
 * reported time is the whole property's, not the last run's). Every other key
 * is last-write-wins.
 *
 * @internal
 */
final class TestoTrialExecutor implements TrialExecutor
{
    private const string DURATION = 'duration';

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

        foreach (array_keys($result->attributes) as $key) {
            $this->attributes[$key] = $this->combine($this->attributes[$key] ?? null, $key, $result->attributes[$key]);
        }

        if ($result->failure instanceof AssumptionSkipped) {
            return TrialOutcome::discarded();
        }

        if ($result->status === Status::Skipped || $result->status === Status::Cancelled) {
            // A per-run skip or cancel asserted nothing; folding it into a pass
            // would report a green property that checked no input. Treat it as a
            // discard so an all-skipped property gives up rather than passes.
            return TrialOutcome::discarded();
        }

        if ($result->status->isFailure()) {
            return TrialOutcome::failed($result->failure);
        }

        return TrialOutcome::passed();
    }

    /**
     * Folds one run's attribute value onto the carried one. Coverage merges so
     * no run's lines are lost; `duration` sums to the whole property's time;
     * everything else keeps the latest value.
     */
    private function combine(mixed $carried, string $key, mixed $value): mixed
    {
        if ($carried instanceof CoverageResult && $value instanceof CoverageResult) {
            return $carried->merge($value);
        }

        if ($key === self::DURATION && is_int($carried) && is_int($value)) {
            return $carried + $value;
        }

        return $value;
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
