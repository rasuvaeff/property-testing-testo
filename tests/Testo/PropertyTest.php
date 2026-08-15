<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
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

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsTimeoutBelowOneMillisecond(): void
    {
        new Property(timeoutMs: 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsBudgetBelowOneMillisecond(): void
    {
        new Property(budgetMs: 0);
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

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsRunsBelowOne(): void
    {
        new Property(runs: 0);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeMaxShrinks(): void
    {
        new Property(maxShrinks: -1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNegativeMaxDiscards(): void
    {
        new Property(maxDiscards: -1);
    }

    public function acceptsAShrinkBudgetOfOneMillisecond(): void
    {
        Assert::same((new Property(shrinkBudgetMs: 1))->shrinkBudgetMs, 1);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsAShrinkBudgetBelowOneMillisecond(): void
    {
        new Property(shrinkBudgetMs: 0);
    }

    public function acceptsAPathBesideASeed(): void
    {
        Assert::same((new Property(seed: 7, path: 'x:1'))->path, 'x:1');
    }

    public function rejectsAPathWithoutASeed(): void
    {
        // The steps of a descent mean nothing against another run, so a path
        // without the seed that produced it is a mistake worth naming at the
        // attribute rather than in the config built from it.
        try {
            new Property(path: 'x:1');

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'Path replay requires an explicit seed');
        }
    }
}
