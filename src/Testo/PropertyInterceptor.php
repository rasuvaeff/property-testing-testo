<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Generate;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\PropertyTestingException;
use Rasuvaeff\PropertyTesting\Runner\Clock;
use Rasuvaeff\PropertyTesting\Runner\Corpus;
use Rasuvaeff\PropertyTesting\Runner\DiagnosticLevel;
use Rasuvaeff\PropertyTesting\Runner\DiagnosticLines;
use Rasuvaeff\PropertyTesting\Runner\Environment;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyResult;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\RunStatistics;
use Testo\Assert\State\Assertion\AssertionException;
use Testo\Assert\State\Expectation\ExpectationFailed;
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
 * It wraps Testo's assertion interceptors so they run once per generated input,
 * while remaining inside data-provider, repeat and retry policies so it owns
 * argument generation for property tests.
 *
 * @api
 */
#[InterceptorOptions(
    // Keep the property loop outside per-run assertion/expectation interceptors
    // (including understudy-testo's verification), so each generated input
    // receives its own assertion and double lifecycle.
    order: InterceptorOptions::ORDER_ASSERTIONS - 200,
    testType: TestType::Test,
)]
final readonly class PropertyInterceptor implements TestRunInterceptor
{
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
            $this->validate($property, $info->name);
            $environment = Environment::fromVariables($this->variables());
            $derandomize = $environment->derandomize ?? $property->derandomize;
            $path = $property->path ?? $environment->path;
            $pinnedSeed = $property->seed ?? $environment->seed;

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
                // The environment dials the suite; the attribute pins the
                // property (core's Environment::config() applies that table).
                config: $environment->config(
                    runs: $property->runs,
                    seed: $this->resolveSeed($pinnedSeed, $derandomize),
                    maxShrinks: $property->maxShrinks,
                    maxDiscards: $property->maxDiscards,
                    timeoutMs: $property->timeoutMs,
                    budgetMs: $property->budgetMs,
                    shrink: $property->shrink,
                    shrinkBudgetMs: $property->shrinkBudgetMs,
                    phases: $property->phases,
                    derandomize: $property->derandomize,
                    path: $property->path,
                    edgeCases: $property->edgeCases,
                    exhaustive: $property->exhaustive,
                    exhaustiveBudget: $property->exhaustiveBudget,
                    flakyReplays: $property->flakyReplays,
                    searchRuns: $property->searchRuns,
                ),
                examples: $this->resolveExamples($reflection, $info, $property),
                // A pinned attribute seed wins over the corpus: replaying recorded
                // failures would break the pinned reproducibility.
                replayRegressions: $property->seed === null,
            );

            $corpus = EnvironmentCorpus::of($environment);
        } catch (\InvalidArgumentException $misconfiguration) {
            // A property that cannot be set up is an error of this test, named
            // by its message — not an aborted pipeline with the reason buried
            // in a previous exception.
            return new TestResult(info: $info, status: Status::Error, failure: $misconfiguration);
        } catch (\TypeError|\ValueError $misconfiguration) {
            // A provider invoked with the wrong arity, a closure bound to
            // nothing: PHP's spelling of the same class of mistake.
            return new TestResult(info: $info, status: Status::Error, failure: new \InvalidArgumentException(
                sprintf('#[Property] on "%s" cannot be set up: %s', $info->name, $misconfiguration->getMessage()),
                previous: $misconfiguration,
            ));
        }

        $listeners = $this->listeners;

        if ($environment->verbose) {
            $listeners[] = new VerboseListener($this->messenger);
        }

        $executor = new TestoTrialExecutor($info, \Closure::fromCallable($next), $property->throws, $property->failOn);

        try {
            $result = $this->runner->run($definition, $executor, $listeners, $corpus);
        } catch (PropertyTestingException $engineError) {
            // Thrown, not returned, by the engine: core 1.4's
            // GenerationErrorException for a generator that threw (the seed and
            // attempt are in its message). An error of this test — not an
            // aborted pipeline with the reason buried in a previous exception.
            return new TestResult(info: $info, status: Status::Error, failure: $engineError, attributes: $executor->attributes());
        }

        if ($executor->everyRunSkipped()) {
            // Every run skipped (a `SkipTest` thrown from the body or a hook):
            // the property checked nothing, and that is the test being skipped,
            // not the discard budget being exhausted.
            return new TestResult(info: $info, status: Status::Skipped, failure: $executor->firstSkip(), attributes: $executor->attributes());
        }

        return $this->mapResult($info, $result, $executor->attributes());
    }

    /**
     * The raw `PROPERTY_*` values core's {@see Environment} parses: read here,
     * because the engine never reads the process environment.
     *
     * @return array<string, string|false>
     */
    private function variables(): array
    {
        $variables = [];

        foreach (Environment::VARIABLES as $name) {
            $variables[$name] = getenv($name);
        }

        return $variables;
    }

    /**
     * The attribute is a data holder — Testo instantiates it long before this
     * interceptor runs, so a constructor that threw would abort the pipeline
     * with the reason buried in `previous`. The engine's config repeats these
     * checks, but without the property's name.
     */
    private function validate(Property $property, string $name): void
    {
        $reason = match (true) {
            $property->runs < 1 => 'runs must be greater than or equal to 1',
            $property->maxShrinks !== null && $property->maxShrinks < 0 => 'maxShrinks must be greater than or equal to 0',
            $property->maxDiscards !== null && $property->maxDiscards < 0 => 'maxDiscards must be greater than or equal to 0',
            $property->timeoutMs !== null && $property->timeoutMs < 1 => 'timeoutMs must be greater than or equal to 1 millisecond',
            $property->budgetMs !== null && $property->budgetMs < 1 => 'budgetMs must be greater than or equal to 1 millisecond',
            $property->shrinkBudgetMs !== null && $property->shrinkBudgetMs < 1 => 'shrinkBudgetMs must be greater than or equal to 1 millisecond',
            $property->exhaustiveBudget < 1 => 'exhaustiveBudget must be greater than or equal to 1',
            $property->flakyReplays < 0 => 'flakyReplays must be greater than or equal to 0',
            $property->searchRuns < 0 => 'searchRuns must be greater than or equal to 0',
            $property->failOn < 0 => 'failOn must be greater than or equal to 0',
            // The engine says the same thing, but the adapter draws a seed for
            // an unseeded property before the engine sees it, and an
            // environment seed replays a suite, not the run this path came from.
            $property->path !== null && $property->seed === null => 'path replays a recorded descent and requires the seed it was recorded with',
            $property->throws !== null && !is_a($property->throws, \Throwable::class, allow_string: true) => sprintf('throws names "%s", which is not a Throwable', $property->throws),
            $property->throws !== null && (is_a(AssertionException::class, $property->throws, allow_string: true) || is_a(ExpectationFailed::class, $property->throws, allow_string: true)) => sprintf('throws names "%s", which a failed assertion is an instance of — a falsified body would pass; name the exception the body throws', $property->throws),
            default => null,
        };

        if ($reason !== null) {
            throw new \InvalidArgumentException(sprintf('#[Property] on "%s" cannot be set up: %s', $name, $reason));
        }
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
        $statistics = RunStatistics::of($result);

        if ($statistics instanceof RunStatistics) {
            // Testo's terminal renderer writes CHANNEL_STDERR through as-is,
            // while every other channel streams only at Verbosity::Verbose —
            // rescued at normal verbosity solely when the whole run holds one
            // passing test. The distribution is read on the red run, which is
            // exactly where it used to disappear; the level, not the channel,
            // says which line is a warning.
            foreach (DiagnosticLines::of($info->name, $statistics) as $line) {
                $this->messenger->log(
                    Messenger::CHANNEL_STDERR,
                    $line->text,
                    $line->level === DiagnosticLevel::Warning ? Level::Warning : Level::Info,
                );
            }
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

            if ($this->carriesGenerate($testMethod)) {
                // No provider and no auto, but generators written on the
                // parameters: they have to cover every one of them.
                return Gen::forParameters($testMethod, derive: false);
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

        // A key naming something the property does not take is an error, with
        // or without auto: ignored, a typoed entry leaves its parameter without
        // a generator, and under auto merge semantics would silently replace
        // it with a signature-derived one — green in the wrong domain.
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

        // The provider covers the parameters it names and #[Generate] the ones
        // that carry it; under auto the signature covers the rest — the
        // forClass(overrides) model applied to the property — and without it
        // a parameter left uncovered is refused by name.
        return Gen::forParameters($testMethod, $typed, derive: $property->auto);
    }

    private function carriesGenerate(\ReflectionMethod $testMethod): bool
    {
        foreach ($testMethod->getParameters() as $parameter) {
            if ($parameter->getAttributes(Generate::class) !== []) {
                return true;
            }
        }

        return false;
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

    /**
     * @param \Closure|array<array-key, mixed>|string $provider
     */
    private function resolveProvider(
        \ReflectionMethod $testMethod,
        TestInfo $info,
        \Closure|array|string $provider,
        string $kind,
    ): \Closure {
        if ($provider instanceof \Closure) {
            return $provider;
        }

        $class = $testMethod->getDeclaringClass();

        if (is_array($provider)) {
            // The attribute keeps a non-callable array as written — the only
            // array that reaches here — so that it is refused by name.
            throw new \InvalidArgumentException(sprintf(
                'Property "%s" references %s provider [%s] which is not a callable',
                $testMethod->getName(),
                $kind,
                implode(', ', array_map(
                    static fn(mixed $part): string => \is_string($part) ? sprintf('"%s"', $part) : get_debug_type($part),
                    $provider,
                )),
            ));
        }

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
}
