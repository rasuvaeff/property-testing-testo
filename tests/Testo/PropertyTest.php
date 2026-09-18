<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Property::class)]
final class PropertyTest
{
    public function defaultsAreSane(): void
    {
        $property = new Property();

        Assert::same($property->runs, 100);
        Assert::null($property->seed);
        Assert::null($property->generators);
        Assert::null($property->maxShrinks);
        Assert::null($property->maxDiscards);
        Assert::null($property->timeoutMs);
        Assert::null($property->budgetMs);
    }

    public function retainsConstructorArguments(): void
    {
        $property = new Property(runs: 250, seed: 42, generators: 'provide', maxShrinks: 5, maxDiscards: 20, timeoutMs: 100, budgetMs: 5_000);

        Assert::same($property->runs, 250);
        Assert::same($property->seed, 42);
        Assert::same($property->generators, 'provide');
        Assert::same($property->maxShrinks, 5);
        Assert::same($property->maxDiscards, 20);
        Assert::same($property->timeoutMs, 100);
        Assert::same($property->budgetMs, 5_000);
    }

    public function retainsCallableProvidersAsClosures(): void
    {
        $generator = static fn(): array => [];
        $example = static fn(): array => [];
        $property = new Property(generators: $generator, examples: $example);

        Assert::instanceOf($property->generators, \Closure::class);
        Assert::instanceOf($property->examples, \Closure::class);
        Assert::same($property->generators, $generator);
        Assert::same($property->examples, $example);
    }

    public function convertsInvokableProvidersWithoutExecutingThem(): void
    {
        $calls = 0;
        $provider = new class ($calls) {
            public function __construct(private int &$calls) {}

            public function __invoke(): array
            {
                ++$this->calls;

                return [];
            }
        };

        $property = new Property(generators: $provider);

        Assert::instanceOf($property->generators, \Closure::class);
        Assert::same($calls, 0);
        ($property->generators)();
        Assert::same($calls, 1);

        $examplesProperty = new Property(examples: $provider);

        Assert::instanceOf($examplesProperty->examples, \Closure::class);
        ($examplesProperty->examples)();
        Assert::same($calls, 2);
    }

    public function keepsANonCallableArrayProviderAsWritten(): void
    {
        // Testo instantiates the attribute before the interceptor runs, so the
        // attribute refuses nothing: the interceptor names the mistake.
        $method = new \ReflectionMethod(NonCallableArrayProviderStub::class, 'check');

        $property = $method->getAttributes(Property::class)[0]->newInstance();

        Assert::same($property->generators, [SharedCallableProvider::class, 'missingMethod']);
    }

    /**
     * The attribute is a data holder: a value the engine would refuse is kept
     * and reported by the interceptor with the property's name, instead of
     * aborting the pipeline from a constructor Testo calls first.
     */
    #[DataProvider('outOfRangeValuesProvider')]
    public function keepsAnOutOfRangeValueForTheInterceptorToRefuse(string $parameter, int $value): void
    {
        $property = new Property(...[$parameter => $value]);

        Assert::same($property->{$parameter}, $value);
    }

    public static function outOfRangeValuesProvider(): iterable
    {
        yield 'runs: 0' => ['runs', 0];
        yield 'maxShrinks: -1' => ['maxShrinks', -1];
        yield 'maxDiscards: -1' => ['maxDiscards', -1];
        yield 'timeoutMs: 0' => ['timeoutMs', 0];
        yield 'budgetMs: 0' => ['budgetMs', 0];
        yield 'shrinkBudgetMs: 0' => ['shrinkBudgetMs', 0];
    }

    public function acceptsZeroMaxShrinks(): void
    {
        Assert::same((new Property(maxShrinks: 0))->maxShrinks, 0);
    }

    public function acceptsZeroMaxDiscards(): void
    {
        Assert::same((new Property(maxDiscards: 0))->maxDiscards, 0);
    }

    public function acceptsTimeoutOfOneMillisecond(): void
    {
        Assert::same((new Property(timeoutMs: 1))->timeoutMs, 1);
    }

    public function acceptsBudgetOfOneMillisecond(): void
    {
        Assert::same((new Property(budgetMs: 1))->budgetMs, 1);
    }

    public function acceptsRunsOfOne(): void
    {
        Assert::same((new Property(runs: 1))->runs, 1);
    }

    public function acceptsAShrinkBudgetOfOneMillisecond(): void
    {
        Assert::same((new Property(shrinkBudgetMs: 1))->shrinkBudgetMs, 1);
    }

    public function acceptsAPathBesideASeed(): void
    {
        Assert::same((new Property(seed: 7, path: 'x:1'))->path, 'x:1');
    }

    public function keepsAPathWithoutASeed(): void
    {
        $property = new Property(path: 'x:1');

        Assert::same($property->path, 'x:1');
        Assert::null($property->seed);
    }

    public function throwsDefaultsToNullAndKeepsTheClassAsWritten(): void
    {
        Assert::null((new Property())->throws);
        Assert::same((new Property(throws: \DomainException::class))->throws, \DomainException::class);
        // Not validated here either; the interceptor refuses a non-Throwable.
        Assert::same((new Property(throws: \stdClass::class))->throws, \stdClass::class);
    }
}
