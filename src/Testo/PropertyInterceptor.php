<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\Runner\Clock;
use Rasuvaeff\PropertyTesting\Runner\Corpus;
use Rasuvaeff\PropertyTesting\Runner\CorpusFactory;
use Rasuvaeff\PropertyTesting\Runner\CoverageFailed;
use Rasuvaeff\PropertyTesting\Runner\EnvironmentOverrides;
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
        $attributes = $reflection->getAttributes(Property::class, \ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            return $next($info);
        }

        try {
            if (!$reflection instanceof \ReflectionMethod) {
                // The generators/examples conventions and the corpus id are
                // built on a declaring class; a function-based case with the
                // attribute would otherwise run once, without arguments, and
                // pass — the silent version of "not supported".
                throw new \InvalidArgumentException(sprintf(
                    '#[Property] on "%s" is not supported: property tests must be methods of a test case class',
                    $info->name,
                ));
            }

            if ($info->arguments !== []) {
                // A data provider's set would be overwritten by the generated
                // arguments, and every set would share one corpus entry.
                throw new \InvalidArgumentException(sprintf(
                    '#[Property] on "%s" cannot be combined with a data provider: the generators supply the arguments',
                    $info->name,
                ));
            }

            $property = $attributes[0]->newInstance();
            $derandomize = EnvironmentOverrides::flag(getenv('PROPERTY_DERANDOMIZE')) ?? $property->derandomize;
            $path = $property->path ?? EnvironmentOverrides::string(getenv('PROPERTY_PATH'));
            $pinnedSeed = $property->seed ?? EnvironmentOverrides::seed(getenv('PROPERTY_SEED'));

            if ($path !== null && $pinnedSeed === null) {
                // The engine refuses a path without a seed, and the attribute
                // refuses its own combination — but neither sees this one: the
                // adapter draws a random seed for an unseeded property, so the
                // engine is handed a seed and says nothing while the path
                // replays a descent of a run that never happened. The path is
                // always copied from a message that printed the seed beside it.
                throw new \InvalidArgumentException(
                    'PROPERTY_PATH replays one recorded descent and needs the seed it was recorded with; set PROPERTY_SEED too',
                );
            }

            $definition = new PropertyDefinition(
                id: $reflection->getDeclaringClass()->getName() . '::' . $reflection->getName(),
                name: $info->name,
                generators: $this->resolveGenerators($reflection, $info, $property),
                parameterNames: array_map(
                    static fn(\ReflectionParameter $p): string => $p->getName(),
                    $reflection->getParameters(),
                ),
                config: new PropertyConfig(
                    runs: EnvironmentOverrides::runs(getenv('PROPERTY_RUNS')) ?? $property->runs,
                    seed: $this->resolveSeed($pinnedSeed, $derandomize),
                    maxShrinks: $property->maxShrinks,
                    maxDiscards: $property->maxDiscards,
                    timeoutMs: $property->timeoutMs,
                    budgetMs: $property->budgetMs,
                    shrink: $property->shrink,
                    shrinkBudgetMs: $property->shrinkBudgetMs,
                    // The environment dials the suite; the attribute pins the
                    // property. A phase list, the boundary mode and
                    // derandomization are CI knobs, so the variables win; a seed
                    // and a path replay one specific failure and yield to what
                    // the attribute wrote down.
                    phases: EnvironmentOverrides::phases(getenv('PROPERTY_PHASES')) ?? $property->phases,
                    derandomize: $derandomize,
                    path: $path,
                    edgeCases: EnvironmentOverrides::edgeCases(getenv('PROPERTY_EDGE_CASES')) ?? $property->edgeCases,
                ),
                examples: $this->resolveExamples($reflection, $info, $property),
                // A pinned attribute seed wins over the corpus: replaying recorded
                // failures would break the pinned reproducibility.
                replayRegressions: $property->seed === null,
            );

            $corpus = $this->resolveCorpus();
        } catch (\InvalidArgumentException $misconfiguration) {
            // A property that cannot be set up is an error of this test, named
            // by its message — not an aborted pipeline with the reason buried
            // in a previous exception.
            return new TestResult(info: $info, status: Status::Error, failure: $misconfiguration);
        } catch (\TypeError|\ValueError $misconfiguration) {
            // The engine's own refusals are InvalidArgumentException, but PHP
            // raises these two for the same class of mistake: an attribute
            // argument of the wrong shape (`generators: [Provider::class,
            // 'missingMethod']` is not a callable), a provider closure bound to
            // nothing. Left uncaught they escape the pipeline and come back as
            // Status::Aborted with "Error during test execution pipeline." on
            // top and the real reason buried in `previous`.
            return new TestResult(info: $info, status: Status::Error, failure: new \InvalidArgumentException(
                sprintf('#[Property] on "%s" cannot be set up: %s', $info->name, $misconfiguration->getMessage()),
                previous: $misconfiguration,
            ));
        }

        $listeners = $this->listeners;

        if (EnvironmentOverrides::flag(getenv('PROPERTY_VERBOSE')) ?? false) {
            $listeners[] = new VerboseListener($this->messenger);
        }

        $executor = new TestoTrialExecutor($info, \Closure::fromCallable($next));
        $result = $this->runner->run($definition, $executor, $listeners, $corpus);

        if ($executor->everyRunSkipped()) {
            // Every run skipped (a `SkipTest` thrown from the body or a hook):
            // the property checked nothing, and that is the test being skipped,
            // not the discard budget being exhausted.
            return new TestResult(info: $info, status: Status::Skipped, failure: $executor->firstSkip(), attributes: $executor->attributes());
        }

        return $this->mapResult($info, $result, $executor->attributes());
    }

    /**
     * The corpus `PROPERTY_DB` names, or null when it is unset (storage off,
     * nothing written). The value's meaning — a directory, a Redis DSN, an
     * error — is the engine's, shared with the PHPUnit adapter.
     */
    private function resolveCorpus(): ?Corpus
    {
        $dsn = EnvironmentOverrides::string(getenv('PROPERTY_DB'));

        return $dsn === null ? null : CorpusFactory::fromDsn($dsn);
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
     * The attribute seed wins; otherwise `PROPERTY_SEED` fixes the seed for the
     * whole suite (handy for replaying a CI failure); otherwise a random seed is
     * drawn.
     */
    private function resolveSeed(?int $pinnedSeed, bool $derandomize): ?int
    {
        if ($pinnedSeed !== null) {
            return $pinnedSeed;
        }

        // Unseeded: the adapter normally draws the seed so the value is
        // decided in one place, but a derandomized run must reach the
        // engine with none — deriving it from the property id is something
        // only the engine can do, because only it knows the id.
        return $derandomize ? null : random_int(0, PHP_INT_MAX);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    private function resolveGenerators(\ReflectionMethod $testMethod, TestInfo $info, Property $property): array
    {
        $provider = $property->generators;
        $class = $testMethod->getDeclaringClass();
        $methodName = $testMethod->getName() . 'Generators';

        if ($provider === null && !$class->hasMethod($methodName)) {
            if ($property->auto) {
                // No provider at all: every parameter comes from the signature.
                return Gen::forParameters($testMethod);
            }

            throw new \InvalidArgumentException(sprintf(
                'Property "%s" requires a generators method "%s" on %s returning array<string, ArbitraryInterface>',
                $testMethod->getName(),
                $methodName,
                $class->getName(),
            ));
        }

        $provider ??= $methodName;
        $providerLabel = \is_string($provider) ? sprintf('method "%s"', $provider) : 'callable provider';

        /** @var mixed $generators */
        $generators = $this->resolveProvider($testMethod, $info, $provider, 'generators')();

        if (!is_array($generators)) {
            throw new \InvalidArgumentException(sprintf(
                'Generators %s must return an array, got %s',
                $providerLabel,
                get_debug_type($generators),
            ));
        }

        /** @var array<string, ArbitraryInterface> $typed */
        $typed = [];
        foreach ($generators as $name => $generator) {
            if (!$generator instanceof ArbitraryInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Generators %s must return array<string, ArbitraryInterface>, got %s for key "%s"',
                    $providerLabel,
                    get_debug_type($generator),
                    (string) $name,
                ));
            }
            $typed[(string) $name] = $generator;
        }

        if (!$property->auto) {
            return $typed;
        }

        // Under auto the provider is the overrides, and a key naming something
        // the property does not take must be an error: merge semantics would
        // otherwise silently replace a typoed entry with a signature-derived
        // generator, and the property would run green in the wrong domain.
        $parameters = [];
        foreach ($testMethod->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = true;
        }

        foreach (array_keys($typed) as $name) {
            if (!isset($parameters[$name])) {
                throw new \InvalidArgumentException(sprintf(
                    'Property "%s": generators %s covers "%s", which is not a parameter of the property',
                    $testMethod->getName(),
                    $providerLabel,
                    $name,
                ));
            }
        }

        // The provider covers the parameters it names; the signature covers
        // the rest — the forClass(overrides) model applied to the property.
        return Gen::forParameters($testMethod, $typed);
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
        $provider = $property->examples;
        $class = $testMethod->getDeclaringClass();
        $methodName = $testMethod->getName() . 'Examples';

        if ($provider === null && !$class->hasMethod($methodName)) {
            return [];
        }

        $provider ??= $methodName;
        $providerLabel = \is_string($provider) ? sprintf('method "%s"', $provider) : 'callable provider';

        /** @var mixed $examples */
        $examples = $this->resolveProvider($testMethod, $info, $provider, 'examples')();

        if (!is_iterable($examples)) {
            throw new \InvalidArgumentException(sprintf(
                'Examples %s must return an iterable, got %s',
                $providerLabel,
                get_debug_type($examples),
            ));
        }

        $expectedArity = count($testMethod->getParameters());
        $typed = [];

        foreach ($examples as $example) {
            if (!is_array($example)) {
                throw new \InvalidArgumentException(sprintf(
                    'Examples %s must yield arrays of positional arguments, got %s',
                    $providerLabel,
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

    private function resolveProvider(
        \ReflectionMethod $testMethod,
        TestInfo $info,
        \Closure|string $provider,
        string $kind,
    ): \Closure {
        if ($provider instanceof \Closure) {
            return $provider;
        }

        $class = $testMethod->getDeclaringClass();
        if ($class->hasMethod($provider)) {
            $method = $class->getMethod($provider);

            if ($method->isStatic()) {
                return $method->getClosure();
            }

            // Not gated on hasInstance(): the case instance is created lazily,
            // so asking that first would refuse the very fixture this branch
            // exists for. Only the absence of a provider is the mistake.
            $instance = $info->caseInfo->instance?->getInstance();

            if ($instance === null) {
                // getClosure(null) on an instance method is a ValueError, which
                // reads as an internal failure. Name what is wrong instead: the
                // provider has to be static unless the case is instantiated.
                throw new \InvalidArgumentException(sprintf(
                    'Property "%s" references %s provider "%s", which is not static and has no test-case instance to run on; make it static',
                    $testMethod->getName(),
                    $kind,
                    $provider,
                ));
            }

            return $method->getClosure($instance);
        }

        if (is_callable($provider)) {
            return \Closure::fromCallable($provider);
        }

        throw new \InvalidArgumentException(sprintf(
            'Property "%s" references %s provider "%s" which is neither a method on %s nor a callable',
            $testMethod->getName(),
            $kind,
            $provider,
            $class->getName(),
        ));
    }

    /**
     * Print the share of (passing) runs that hit each {@see \Rasuvaeff\PropertyTesting\Classify} label.
     *
     * @param array<array-key, int> $classifications Keyed by label — `array-key` because PHP stores
     *        a numeric label such as `'42'` under an integer key, which is what the engine's own
     *        counters declare since core 0.2.
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
