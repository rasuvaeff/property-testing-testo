<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

use Rasuvaeff\PropertyTesting\AssumptionSkipped;
use Rasuvaeff\PropertyTesting\Runner\TrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\TrialOutcome;
use Testo\Codecov\Result\CoverageResult;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Exception\CancelTest;
use Testo\Core\Exception\SkipTest;
use Testo\Core\Value\Status;

/**
 * Executes the property body through Testo's interceptor pipeline and folds
 * each {@see TestResult} into the engine's {@see TrialOutcome}: an
 * {@see AssumptionSkipped} failure is a discard, a per-run skip/cancel is a
 * skip (a discard the corpus does not prune on), a successful status passes,
 * and anything else — including anything the pipeline throws — is a failure.
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

    private int $runs = 0;

    private int $skipped = 0;

    private ?\Throwable $firstSkip = null;

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
        ++$this->runs;

        try {
            $result = ($this->next)($this->info->with(arguments: array_values($arguments)));
        } catch (AssumptionSkipped) {
            return TrialOutcome::discarded();
        } catch (SkipTest|CancelTest $skip) {
            // A skip raised from a lifecycle hook — `#[BeforeTest]` guarding a
            // missing dependency is the common case — never reaches the
            // terminal handler that turns a skip from the *body* into a
            // Status::Skipped result: the lifecycle interceptor runs the hooks
            // inside this closure and lets the throw travel. Folded into a
            // failure it falsified the property and shrank around the skip,
            // re-running the hook on every trial, while README promises a skip
            // from the body *or a hook* skips the run.
            return $this->skip($skip);
        } catch (\Throwable $failure) {
            // The pipeline below (a lifecycle hook, a downstream interceptor)
            // threw instead of reporting: that is this run's failure, and it
            // must reach the engine as one — escaping here would abort the
            // whole property with no counterexample.
            return TrialOutcome::failed($failure);
        }

        foreach (array_keys($result->attributes) as $key) {
            $this->attributes[$key] = $this->combine($this->attributes[$key] ?? null, $key, $result->attributes[$key]);
        }

        if ($result->failure instanceof AssumptionSkipped) {
            return TrialOutcome::discarded();
        }

        if ($result->status === Status::Skipped || $result->status === Status::Cancelled) {
            // A per-run skip or cancel asserted nothing; folding it into a pass
            // would report a green property that checked no input. It is a
            // discard — and remembered, so a property whose every run skipped
            // is reported as skipped rather than as one that gave up.
            return $this->skip($result->failure);
        }

        if ($result->status->isSuccessful()) {
            return TrialOutcome::passed();
        }

        // Failed, Error — and Aborted, Risky: anything that is not a success is
        // not evidence the input passed.
        return TrialOutcome::failed($result->failure ?? new \RuntimeException(sprintf(
            'The run ended with status %s and no failure attached',
            $result->status->name,
        )));
    }

    /**
     * Records one skipped run — from the body, where the terminal handler
     * reports it as a status, or from a hook, where it arrives as a throw —
     * and reports it to the engine as a skip.
     *
     * A skip, not a plain discard: the engine counts both the same everywhere
     * but the corpus phase, where a discard means the recorded input left the
     * property's domain and the entry is pruned. A skip says nothing about the
     * input, so reporting one as a discard let a machine without the
     * dependency the body guards against delete the counterexample for every
     * machine that has it.
     */
    private function skip(?\Throwable $reported): TrialOutcome
    {
        ++$this->skipped;
        $this->firstSkip ??= $reported;

        return TrialOutcome::skipped();
    }

    /**
     * Whether every run so far was skipped or cancelled (and there was one):
     * the property as a whole is then a skipped test, not a failed one.
     */
    public function everyRunSkipped(): bool
    {
        return $this->runs > 0 && $this->skipped === $this->runs;
    }

    /**
     * What the first skipped run reported, when it reported anything.
     */
    public function firstSkip(): ?\Throwable
    {
        return $this->firstSkip;
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
