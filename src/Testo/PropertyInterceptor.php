<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\Runner\Clock;
use Rasuvaeff\PropertyTesting\Runner\CoverageFailed;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\GaveUp;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\RunStatistics;
use Rasuvaeff\PropertyTesting\Runner\TimeBudgetExceeded;
use Testo\Common\Messenger;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Log\Level;
use Testo\Core\Value\Status;
use Testo\Core\Value\TestType;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * The Testo adapter for a {@see Property}: resolves the test framework's
 * conventions — the attribute, the reflection-discovered generators/examples
 * methods, the environment overrides — into a {@see PropertyDefinition}, runs
 * it on the framework-agnostic {@see PropertyRunner}, and maps the structured
 * {@see PropertyResult} back to a single Testo {@see TestResult}.
 *
 * The interceptor self-registers via the {@see Property} attribute's
 * {@see \Testo\Pipeline\Attribute\FallbackInterceptor}, so simply requiring the
 * package is enough — no plugin registration in {@see testo.php} is needed.
 *
 * It sits close to the test function in the pipeline (after data providers,
 * repeat and retry policies) so it owns argument generation for property tests.
 *
 * @api
 */
#[InterceptorOptions(
    order: InterceptorOptions::ORDER_CLOSE_TO_TEST,
    testType: TestType::Test,
)]
final readonly class PropertyInterceptor implements TestRunInterceptor
{
    /**
     * Warn when more than this fraction of runs is discarded via {@see \Rasuvaeff\PropertyTesting\Assume::that()}.
     */
    private const float SKIP_RATE_WARNING_THRESHOLD = 0.9;

    private PropertyRunner $runner;

    /** @var list<PropertyListener> */
    private array $listeners;

    /**
     * @param iterable<PropertyListener> $listeners Observers of the run's
     *   lifecycle events, notified in the given order. The verbose trace
     *   listener is appended automatically when `PROPERTY_VERBOSE` is on.
     */
    public function __construct(
        private Messenger $messenger,
        ?Clock $clock = null,
        iterable $listeners = [],
    ) {
        $this->runner = new PropertyRunner($clock);
        $this->listeners = array_values(is_array($listeners) ? $listeners : iterator_to_array($listeners));
    }

    /**
     * @param callable(TestInfo): TestResult $next
     */
    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        $reflection = $info->testDefinition->reflection;

        if (!$reflection instanceof \ReflectionMethod) {
            // Property tests need a generators method on the test case class.
            return $next($info);
        }

        $attributes = $reflection->getAttributes(Property::class, \ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return $next($info);
        }

        $property = $attributes[0]->newInstance();

        $definition = new PropertyDefinition(
            id: $reflection->getDeclaringClass()->getName() . '::' . $reflection->getName(),
            name: $info->name,
            generators: $this->resolveGenerators($reflection, $info, $property),
            parameterNames: array_map(
                static fn(\ReflectionParameter $p): string => $p->getName(),
                $reflection->getParameters(),
            ),
            config: new PropertyConfig(
                runs: $this->resolveRuns($property->runs),
                seed: $this->resolveSeed($property->seed),
                maxShrinks: $property->maxShrinks,
                maxDiscards: $property->maxDiscards,
                timeoutMs: $property->timeoutMs,
                budgetMs: $property->budgetMs,
            ),
            examples: $this->resolveExamples($reflection, $info, $property),
            // A pinned attribute seed wins over the corpus: replaying recorded
            // failures would break the pinned reproducibility.
            replayRegressions: $property->seed === null,
        );

        $listeners = $this->listeners;
        if ($this->resolveVerbose()) {
            $listeners[] = new VerboseListener($this->messenger);
        }

        $executor = new TestoTrialExecutor($info, \Closure::fromCallable($next));

        $result = $this->runner->run($definition, $executor, $listeners, FilesystemCorpus::fromEnv());

        return $this->mapResult($info, $result, $executor->attributes());
    }

    /**
     * Maps the engine's structured result to the one aggregate {@see TestResult}
     * Testo reports, reporting the classification distribution and the
     * excessive-discard warning for the outcomes that completed (or exhausted)
     * their random phase.
     *
     * @param array<non-empty-string, mixed> $attributes
     */
    private function mapResult(TestInfo $info, PropertyResult $result, array $attributes): TestResult
    {
        $statistics = match (true) {
            $result instanceof Passed => $result->statistics,
            $result instanceof GaveUp => $result->statistics,
            $result instanceof CoverageFailed => $result->statistics,
            $result instanceof TimeBudgetExceeded => $result->statistics,
            default => null,
        };

        if ($statistics instanceof RunStatistics) {
            $this->warnOnExcessiveSkips($info->name, $statistics->discards, $statistics->attempts);
            $this->reportClassifications($info->name, $statistics->classifications, $statistics->checks);
        }

        $failure = $result->failure();

        if (!$failure instanceof \Throwable) {
            return new TestResult(
                info: $info,
                status: Status::Passed,
                attributes: $attributes,
            );
        }

        return new TestResult(
            info: $info,
            status: Status::Failed,
            failure: $failure,
            attributes: $attributes,
        );
    }

    /**
     * The `PROPERTY_RUNS` environment variable overrides the attribute's run
     * count (handy for dialing runs up in CI). It must be a positive integer.
     */
    private function resolveRuns(int $runs): int
    {
        $env = getenv('PROPERTY_RUNS');

        if ($env === false || $env === '') {
            return $runs;
        }

        if (preg_match('/^\d+\z/', $env) !== 1 || (int) $env < 1) {
            throw new \InvalidArgumentException(sprintf('PROPERTY_RUNS must be a positive integer, got "%s"', $env));
        }

        return (int) $env;
    }

    /**
     * `PROPERTY_VERBOSE` (any value except '' and '0') logs every run's
     * generated arguments — for debugging a property whose failure depends on
     * inputs you cannot see in the counterexample alone.
     */
    private function resolveVerbose(): bool
    {
        $env = getenv('PROPERTY_VERBOSE');

        return !in_array($env, [false, '', '0'], strict: true);
    }

    /**
     * The attribute seed wins; otherwise `PROPERTY_SEED` fixes the seed for the
     * whole suite (handy for replaying a CI failure); otherwise a random seed is
     * drawn. `PROPERTY_SEED`, when set, must be an integer.
     */
    private function resolveSeed(?int $attributeSeed): int
    {
        if ($attributeSeed !== null) {
            return $attributeSeed;
        }

        $env = getenv('PROPERTY_SEED');

        if ($env === false || $env === '') {
            return random_int(0, PHP_INT_MAX);
        }

        if (preg_match('/^-?\d+\z/', $env) !== 1) {
            throw new \InvalidArgumentException(sprintf('PROPERTY_SEED must be an integer, got "%s"', $env));
        }

        return (int) $env;
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    private function resolveGenerators(\ReflectionMethod $testMethod, TestInfo $info, Property $property): array
    {
        $methodName = $property->generators ?? $testMethod->getName() . 'Generators';
        $class = $testMethod->getDeclaringClass();

        if (!$class->hasMethod($methodName)) {
            throw new \InvalidArgumentException(sprintf(
                'Property "%s" requires a generators method "%s" on %s returning array<string, ArbitraryInterface>',
                $testMethod->getName(),
                $methodName,
                $class->getName(),
            ));
        }

        $generatorMethod = $class->getMethod($methodName);

        /** @var mixed $generators */
        $generators = $generatorMethod->isStatic()
            ? $generatorMethod->getClosure()()
            : $generatorMethod->getClosure($info->caseInfo->instance?->getInstance())();

        if (!is_array($generators)) {
            throw new \InvalidArgumentException(sprintf(
                'Generators method "%s" must return an array, got %s',
                $methodName,
                get_debug_type($generators),
            ));
        }

        /** @var array<string, ArbitraryInterface> $typed */
        $typed = [];
        foreach ($generators as $name => $generator) {
            if (!$generator instanceof ArbitraryInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Generators method "%s" must return array<string, ArbitraryInterface>, got %s for key "%s"',
                    $methodName,
                    get_debug_type($generator),
                    (string) $name,
                ));
            }
            $typed[(string) $name] = $generator;
        }

        return $typed;
    }

    /**
     * Resolves the property's explicit examples: the attribute's `examples`
     * method name, or the `<testMethod>Examples` convention when that method
     * exists. Each yielded array becomes a list of positional arguments.
     *
     * @return list<list<mixed>>
     */
    private function resolveExamples(\ReflectionMethod $testMethod, TestInfo $info, Property $property): array
    {
        $methodName = $property->examples ?? $testMethod->getName() . 'Examples';
        $class = $testMethod->getDeclaringClass();

        if (!$class->hasMethod($methodName)) {
            if ($property->examples !== null) {
                throw new \InvalidArgumentException(sprintf(
                    'Property "%s" references examples method "%s" which does not exist on %s',
                    $testMethod->getName(),
                    $methodName,
                    $class->getName(),
                ));
            }

            return [];
        }

        $method = $class->getMethod($methodName);

        /** @var mixed $examples */
        $examples = $method->isStatic()
            ? $method->getClosure()()
            : $method->getClosure($info->caseInfo->instance?->getInstance())();

        if (!is_iterable($examples)) {
            throw new \InvalidArgumentException(sprintf(
                'Examples method "%s" must return an iterable, got %s',
                $methodName,
                get_debug_type($examples),
            ));
        }

        $expectedArity = count($testMethod->getParameters());
        $typed = [];

        foreach ($examples as $example) {
            if (!is_array($example)) {
                throw new \InvalidArgumentException(sprintf(
                    'Examples method "%s" must yield arrays of positional arguments, got %s',
                    $methodName,
                    get_debug_type($example),
                ));
            }

            $arguments = array_values($example);

            if (count($arguments) !== $expectedArity) {
                throw new \InvalidArgumentException(sprintf(
                    'Example #%d for "%s" has %d argument(s), but the property takes %d',
                    count($typed),
                    $testMethod->getName(),
                    count($arguments),
                    $expectedArity,
                ));
            }

            $typed[] = $arguments;
        }

        return $typed;
    }

    /**
     * Print the share of (passing) runs that hit each {@see \Rasuvaeff\PropertyTesting\Classify} label.
     *
     * @param array<string, int> $classifications
     */
    private function reportClassifications(string $name, array $classifications, int $checks): void
    {
        if ($classifications === [] || $checks <= 0) {
            return;
        }

        arsort($classifications);

        $parts = [];
        foreach ($classifications as $label => $count) {
            $parts[] = sprintf(
                '%s %d%% (%d/%d)',
                $label,
                (int) round(((float) $count / (float) $checks) * 100.0),
                $count,
                $checks,
            );
        }

        $this->messenger->log(
            Messenger::CHANNEL_STDOUT,
            sprintf('Property "%s" distribution: %s', $name, implode(', ', $parts)),
            Level::Info,
        );
    }

    private function warnOnExcessiveSkips(string $name, int $skips, int $attempts): void
    {
        if ($attempts <= 0 || ($skips / $attempts) <= self::SKIP_RATE_WARNING_THRESHOLD) {
            return;
        }

        $this->messenger->log(
            Messenger::CHANNEL_STDERR,
            sprintf(
                'Property "%s" discarded %d of %d attempt(s) (%d%%); consider narrowing the generators',
                $name,
                $skips,
                $attempts,
                (int) round(((float) $skips / (float) $attempts) * 100.0),
            ),
            Level::Warning,
        );
    }
}
