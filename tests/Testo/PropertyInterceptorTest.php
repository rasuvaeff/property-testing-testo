<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Internal\Path;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\PropertyTesting\AssumptionSkipped;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\CoverageViolationException;
use Rasuvaeff\PropertyTesting\DeadlineExceededException;
use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\ExampleViolationException;
use Rasuvaeff\PropertyTesting\GaveUpException;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\RegressionViolationException;
use Rasuvaeff\PropertyTesting\Runner\DeadlineExceeded;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\RegressionFailed;
use Rasuvaeff\PropertyTesting\Runner\TimeBudgetExceeded;
use Rasuvaeff\PropertyTesting\Testo\PropertyInterceptor;
use Rasuvaeff\PropertyTesting\Testo\TestoTrialExecutor;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\CollectingListener;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\TimeBudgetExceededException;
use Testo\Application\Internal\MessengerHub;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Common\Messenger;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\Identity\SuiteIdentity;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Definition\CaseDefinition;
use Testo\Core\Definition\TestDefinition;
use Testo\Core\Log\Message;
use Testo\Core\Value\Status;
use Testo\Test;

#[Test]
#[Covers(PropertyInterceptor::class)]
#[Covers(PropertyRunner::class)]
#[Covers(TestoTrialExecutor::class)]
#[Covers(DeadlineExceeded::class)]
#[Covers(TimeBudgetExceeded::class)]
#[Covers(RegressionFailed::class)]
#[Covers(CoverageViolationException::class)]
final class PropertyInterceptorTest
{
    public function passesEveryRunAndReportsPassed(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $calls = 0;
        $next = static function (TestInfo $info) use (&$calls): TestResult {
            ++$calls;
            $value = $info->arguments[0];

            Assert::true($value >= 1 && $value <= 10);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::same($calls, 5);
        Assert::same($result->status, Status::Passed);
    }

    public function usesConventionMethodWhenGeneratorsNameIsOmitted(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $calls = 0;
        $next = static function (TestInfo $info) use (&$calls): TestResult {
            ++$calls;

            return new TestResult(info: $info, status: Status::Passed);
        };

        $interceptor->runTest($this->info(ConventionStub::class, 'check'), $next);

        Assert::same($calls, 3);
    }

    public function resolvesAnArrayCallableProvider(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static function (TestInfo $info): TestResult {
            Assert::same($info->arguments[0], 7);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(ArrayCallableStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
    }

    public function resolvesAStringCallableProvider(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static function (TestInfo $info): TestResult {
            Assert::same($info->arguments[0], 7);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(StringCallableStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
    }

    public function resolvesAnInvokableProvider(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static function (TestInfo $info): TestResult {
            Assert::same($info->arguments[0], 8);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(InvokableCallableStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
    }

    public function resolvesCallableExamplesBeforeRandomRuns(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $seen = [];
        $next = static function (TestInfo $info) use (&$seen): TestResult {
            $seen[] = $info->arguments[0];

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(CallableExamplesStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
        Assert::same($seen, [7, 8]);
    }

    public function resolvesInvokableExamplesBeforeRandomRuns(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $seen = [];
        $next = static function (TestInfo $info) use (&$seen): TestResult {
            $seen[] = $info->arguments[0];

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(InvokableCallableExamplesStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
        Assert::same($seen, [8, 8]);
    }

    public function localGeneratorMethodWinsOverGlobalFunction(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static function (TestInfo $info): TestResult {
            Assert::same($info->arguments[0], 9);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(GlobalFunctionCollisionStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
    }

    public function localExamplesMethodWinsOverGlobalFunction(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $seen = [];
        $next = static function (TestInfo $info) use (&$seen): TestResult {
            $seen[] = $info->arguments[0];

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(ExamplesGlobalFunctionCollisionStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
        Assert::same($seen, [41, 8]);
    }

    public function throwsWhenGeneratorsStringIsNeitherMethodNorCallable(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

        try {
            $interceptor->runTest($this->info(MissingCallableGeneratorsStub::class, 'check'), $next);
        } catch (\InvalidArgumentException $exception) {
            Assert::string($exception->getMessage())
                ->contains('generators provider "definitelyNotACallable123"')
                ->contains('neither a method on');

            return;
        }

        Assert::fail('Expected an InvalidArgumentException for an unresolvable generators provider');
    }

    public function throwsWhenExamplesStringIsNeitherMethodNorCallable(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

        try {
            $interceptor->runTest($this->info(MissingCallableExamplesStub::class, 'check'), $next);
        } catch (\InvalidArgumentException $exception) {
            Assert::string($exception->getMessage())
                ->contains('examples provider "definitelyNotACallable123"')
                ->contains('neither a method on');

            return;
        }

        Assert::fail('Expected an InvalidArgumentException for an unresolvable examples provider');
    }

    public function falsifiesAndShrinksToMinimalCounterexample(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // Property fails iff x > 50; the generator always produces such values,
        // so the first run is the original counterexample and shrinking lands on
        // the smallest value in range that still fails (51).
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] > 50
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('x>50'))
            : new TestResult(info: $info, status: Status::Passed);

        $result = $interceptor->runTest($this->info(FalsifyingStub::class, 'check'), $next);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        Assert::same($counterExample->shrunkArguments['x'], 51);
        Assert::same($counterExample->originalArguments['x'] > 50, expected: true);
        Assert::same($counterExample->runsBeforeFailure, 0);
        // Every accepted step is a trial (rejected candidates add more).
        Assert::true($counterExample->shrinkTrials >= $counterExample->shrinkSteps);
        Assert::true($counterExample->shrinkTrials >= 1);
    }

    public function reportsTheFailureOfTheShrunkCounterexampleNotTheOriginal(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // The failure message encodes the failing value, so the reported failure
        // reveals which run it came from. Shrinking drives x to 51, and the
        // reported failure must be that minimal run's, not the original draw's.
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] > 50
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('failed at ' . $info->arguments[0]))
            : new TestResult(info: $info, status: Status::Passed);

        $result = $interceptor->runTest($this->info(FalsifyingStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        Assert::same($counterExample->shrunkArguments['x'], 51);
        Assert::true($counterExample->shrinkSteps >= 1);
        Assert::instanceOf($counterExample->failure, \RuntimeException::class);
        Assert::string($counterExample->failure->getMessage())->contains('failed at 51');
    }

    public function shrinksTheFailingParameterInAMultiParameterProperty(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // Fails iff the SECOND parameter exceeds 50, regardless of the first.
        // Shrinking must drive `b` to its minimal failing value (51) without
        // scrambling the positional arguments (the union-operator bug fed `a`
        // into `b`'s position, so `b` never shrank).
        $next = static fn(TestInfo $info): TestResult => $info->arguments[1] > 50
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('b>50'))
            : new TestResult(info: $info, status: Status::Passed);

        $result = $interceptor->runTest($this->info(MultiParamFalsifyingStub::class, 'check'), $next);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        Assert::same($counterExample->shrunkArguments['b'], 51);
        Assert::same($counterExample->shrunkArguments['a'], 0);
        Assert::true($counterExample->shrinkSteps >= 1);
    }


    public function mappedGeneratorShrinksThroughTheSourceDomain(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // Values are doubled ints; fails iff x > 50. The minimal even value that
        // still fails is 52 — reachable only by shrinking the source int (26)
        // and re-applying the mapping. Pre-2.0 map() reported the original
        // counterexample unshrunk.
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] > 50
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('x>50'))
            : new TestResult(info: $info, status: Status::Passed);

        $result = $interceptor->runTest($this->info(MappedFalsifyingStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);
        Assert::same($result->failure->getCounterExample()->shrunkArguments['x'], 52);
        // Reaching 52 requires rejecting candidates (50 and below pass), so the
        // trial count strictly exceeds the accepted steps.
        $counterExample = $result->failure->getCounterExample();
        Assert::true($counterExample->shrinkTrials > $counterExample->shrinkSteps);
    }

    public function flatMapGeneratorShrinksTheDependentValue(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // The dependent value m lies in [0, n]; fails iff m > 3, so the minimal
        // failing value is 4 regardless of how the source n shrinks.
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] > 3
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('m>3'))
            : new TestResult(info: $info, status: Status::Passed);

        $result = $interceptor->runTest($this->info(FlatMapFalsifyingStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);
        Assert::same($result->failure->getCounterExample()->shrunkArguments['x'], 4);
    }

    public function drawnValueFalsifiesAndShrinksToItsMinimum(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // The body draws from [51, 100] and fails for every drawn value, so the
        // minimal still-failing draw is the range's lower bound, 51.
        $next = static function (TestInfo $info): TestResult {
            $value = Gen::draw(Gen::intBetween(51, 100));

            return $value > 50
                ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('draw>50'))
                : new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(DrawFalsifyingStub::class, 'check'), $next);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        Assert::true($counterExample->originalArguments['draw#1'] > 50);
        Assert::same($counterExample->shrunkArguments['draw#1'], 51);
    }

    public function shrinksParametersAndDrawsTogether(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // Both the parameter (from [11, 100]) and the draw (from [6, 50]) satisfy
        // the failure condition on every run; shrinking must minimise both to
        // their lower bounds independently.
        $next = static function (TestInfo $info): TestResult {
            $n = $info->arguments[0];
            $drawn = Gen::draw(Gen::intBetween(6, 50));

            return ($n > 10 && $drawn > 5)
                ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('n>10 && drawn>5'))
                : new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(ParamAndDrawFalsifyingStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        Assert::same($counterExample->shrunkArguments['n'], 11);
        Assert::same($counterExample->shrunkArguments['draw#1'], 6);
    }

    public function acceptedPrefixShrinkTruncatesTheTape(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // The body draws $n values and the property always fails. Shrinking
        // drives n to its minimum (2); replaying the shorter run drops the
        // now-unused tail draws from the tape, and the remaining draws shrink
        // to 0 through their own trees.
        $next = static function (TestInfo $info): TestResult {
            $n = $info->arguments[0];

            for ($i = 0; $i < $n; ++$i) {
                Gen::draw(Gen::intBetween(0, 100));
            }

            return new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('always fails'));
        };

        $result = $interceptor->runTest($this->info(DrawCountStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        Assert::same($counterExample->shrunkArguments['n'], 2);
        Assert::same($counterExample->shrunkArguments['draw#1'], 0);
        Assert::same($counterExample->shrunkArguments['draw#2'], 0);
        Assert::false(array_key_exists('draw#3', $counterExample->shrunkArguments));
    }

    public function drawsAreReproducibleForAFixedSeed(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static function (TestInfo $info): TestResult {
            $value = Gen::draw(Gen::intBetween(51, 100));

            return $value > 50
                ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('draw>50'))
                : new TestResult(info: $info, status: Status::Passed);
        };

        $first = $interceptor->runTest($this->info(DrawFalsifyingStub::class, 'check'), $next);
        $second = $interceptor->runTest($this->info(DrawFalsifyingStub::class, 'check'), $next);

        Assert::instanceOf($first->failure, PropertyViolationException::class);
        Assert::instanceOf($second->failure, PropertyViolationException::class);
        Assert::same($first->failure->getCounterExample()->originalArguments, $second->failure->getCounterExample()->originalArguments);
        Assert::same($first->failure->getCounterExample()->shrunkArguments, $second->failure->getCounterExample()->shrunkArguments);
    }

    public function maxShrinksZeroDisablesDrawShrinking(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static function (TestInfo $info): TestResult {
            Gen::draw(Gen::intBetween(51, 100));

            return new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('always fails'));
        };

        $result = $interceptor->runTest($this->info(DrawMaxShrinksDisabledStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        Assert::same($counterExample->shrinkSteps, 0);
        Assert::same($counterExample->shrunkArguments, $counterExample->originalArguments);
        // The cap engages before the first candidate runs, so nothing is tried.
        Assert::same($counterExample->shrinkTrials, 0);
    }

    public function examplesMayDrawValues(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $calls = 0;
        $next = static function (TestInfo $info) use (&$calls): TestResult {
            ++$calls;
            $value = Gen::draw(Gen::intBetween(1, 10));

            Assert::true($value >= 1 && $value <= 10);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(DrawExampleStub::class, 'check'), $next);

        // One pinned example plus two random runs, all drawing successfully.
        Assert::same($calls, 3);
        Assert::same($result->status, Status::Passed);
    }

    public function flatMapCounterexampleIsReproducibleForAFixedSeed(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] > 3
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('m>3'))
            : new TestResult(info: $info, status: Status::Passed);

        $first = $interceptor->runTest($this->info(FlatMapFalsifyingStub::class, 'check'), $next);
        $second = $interceptor->runTest($this->info(FlatMapFalsifyingStub::class, 'check'), $next);

        Assert::instanceOf($first->failure, PropertyViolationException::class);
        Assert::instanceOf($second->failure, PropertyViolationException::class);
        Assert::same($first->failure->getCounterExample()->originalArguments, $second->failure->getCounterExample()->originalArguments);
        Assert::same($first->failure->getCounterExample()->shrunkArguments, $second->failure->getCounterExample()->shrunkArguments);
    }

    public function fixedSeedReproducesTheSameCounterexample(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] > 50
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('x>50'))
            : new TestResult(info: $info, status: Status::Passed);

        $first = $interceptor->runTest($this->info(FalsifyingStub::class, 'check'), $next);
        $second = $interceptor->runTest($this->info(FalsifyingStub::class, 'check'), $next);

        Assert::instanceOf($first->failure, PropertyViolationException::class);
        Assert::instanceOf($second->failure, PropertyViolationException::class);
        Assert::same($first->failure->getCounterExample()->seed, $second->failure->getCounterExample()->seed);
        Assert::same($first->failure->getCounterExample()->originalArguments, $second->failure->getCounterExample()->originalArguments);
        Assert::same($first->failure->getCounterExample()->shrunkArguments, $second->failure->getCounterExample()->shrunkArguments);
    }

    public function discardedRunsBeforeFailureAreTrackedSeparately(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $calls = 0;
        $next = static function (TestInfo $info) use (&$calls): TestResult {
            ++$calls;

            if ($calls === 1) {
                return new TestResult(info: $info, status: Status::Error, failure: new AssumptionSkipped());
            }

            return new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('boom'));
        };

        $result = $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        Assert::same($counterExample->runsBeforeFailure, 0);
        Assert::same($counterExample->skips, 1);
    }

    public function warnsAndGivesUpWhenEveryRunIsDiscarded(): void
    {
        $messenger = $this->createMessenger();
        $interceptor = new PropertyInterceptor($messenger);
        $next = static fn(TestInfo $info): TestResult => new TestResult(
            info: $info,
            status: Status::Error,
            failure: new AssumptionSkipped(),
        );

        $result = $interceptor->runTest($this->info(DiscardBudgetStub::class, 'check'), $next);
        $messages = $messenger->getMessages()->channel(Messenger::CHANNEL_STDERR);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, GaveUpException::class);
        Assert::same($result->failure->requiredRuns, 5);
        Assert::same($result->failure->successfulRuns, 0);
        Assert::same($result->failure->discardedRuns, 4);
        Assert::same($result->failure->attempts, 4);
        Assert::same($result->failure->maxDiscards, 3);
        Assert::same(count($messages), 1);
        Assert::string($messages[0]->content)->contains('discarded 4 of 4 attempt(s)');
    }

    public function defaultDiscardBudgetIsTenTimesTheRequiredRuns(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $calls = 0;
        $next = static function (TestInfo $info) use (&$calls): TestResult {
            ++$calls;

            return new TestResult(info: $info, status: Status::Error, failure: new AssumptionSkipped());
        };

        $result = $interceptor->runTest($this->info(DefaultDiscardBudgetStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, GaveUpException::class);
        Assert::same($result->failure->maxDiscards, 10);
        Assert::same($result->failure->discardedRuns, 11);
        Assert::same($calls, 11);
    }

    public function discardsRunsViaAssumeWithoutCountingAsFailure(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $calls = 0;
        // Discard the first run, pass the rest: a discarded run is neither a
        // failure nor a check, and the surviving checks keep the property green.
        $next = static function (TestInfo $info) use (&$calls): TestResult {
            ++$calls;

            if ($calls === 1) {
                return new TestResult(info: $info, status: Status::Error, failure: new AssumptionSkipped());
            }

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
        Assert::same($calls, 6);
    }

    public function passesThroughWhenMethodHasNoPropertyAttribute(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $called = false;
        $next = static function (TestInfo $info) use (&$called): TestResult {
            $called = true;

            return new TestResult(info: $info, status: Status::Passed);
        };

        $interceptor->runTest($this->info(PlainStub::class, 'check'), $next);

        Assert::true($called);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function throwsWhenGeneratorsMethodIsMissing(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

        $interceptor->runTest($this->info(MissingGeneratorMethodStub::class, 'check'), $next);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function throwsWhenGeneratorMissingForAParameter(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

        $interceptor->runTest($this->info(MissingParameterGeneratorStub::class, 'check'), $next);
    }

    public function maxShrinksCapsTheNumberOfAcceptedShrinkSteps(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // The property fails for every input, so without a cap shrinking would
        // accept one step per parameter (two). maxShrinks=1 stops after the first.
        $next = static fn(TestInfo $info): TestResult => new TestResult(
            info: $info,
            status: Status::Failed,
            failure: new \RuntimeException('always fails'),
        );

        $result = $interceptor->runTest($this->info(MaxShrinksCapStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        Assert::same($counterExample->shrinkSteps, 1);

        // Exactly one accepted step changes exactly one parameter.
        $changed = 0;
        foreach ($counterExample->originalArguments as $name => $original) {
            if ($counterExample->shrunkArguments[$name] !== $original) {
                ++$changed;
            }
        }
        Assert::same($changed, 1);
    }

    public function maxShrinksZeroDisablesShrinking(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => new TestResult(
            info: $info,
            status: Status::Failed,
            failure: new \RuntimeException('always fails'),
        );

        $result = $interceptor->runTest($this->info(MaxShrinksDisabledStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        Assert::same($counterExample->shrinkSteps, 0);
        Assert::same($counterExample->shrunkArguments, $counterExample->originalArguments);
        // The cap engages before the first candidate runs, so nothing is tried.
        Assert::same($counterExample->shrinkTrials, 0);
    }

    public function envPropertyRunsOverridesTheAttributeRunCount(): void
    {
        $restoreEnv = Env::set('PROPERTY_RUNS', '3');

        try {
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $calls = 0;
            $next = static function (TestInfo $info) use (&$calls): TestResult {
                ++$calls;

                return new TestResult(info: $info, status: Status::Passed);
            };

            // PassingStub declares runs: 5; the env var forces 3.
            $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

            Assert::same($calls, 3);
        } finally {
            $restoreEnv();
        }
    }

    public function envPropertySeedSuppliesTheSeedWhenTheAttributeOmitsIt(): void
    {
        $restoreEnv = Env::set('PROPERTY_SEED', '777');

        try {
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => $info->arguments[0] > 50
                ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('x>50'))
                : new TestResult(info: $info, status: Status::Passed);

            $result = $interceptor->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $next);

            Assert::instanceOf($result->failure, PropertyViolationException::class);
            Assert::same($result->failure->getCounterExample()->seed, 777);
        } finally {
            $restoreEnv();
        }
    }

    public function attributeSeedWinsOverTheEnvironmentSeed(): void
    {
        $restoreEnv = Env::set('PROPERTY_SEED', '777');

        try {
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(
                info: $info,
                status: Status::Failed,
                failure: new \RuntimeException('always'),
            );

            // FalsifyingStub declares seed: 1, which must win over the env seed.
            $result = $interceptor->runTest($this->info(FalsifyingStub::class, 'check'), $next);

            Assert::instanceOf($result->failure, PropertyViolationException::class);
            Assert::same($result->failure->getCounterExample()->seed, 1);
        } finally {
            $restoreEnv();
        }
    }

    public function shrinkOffReportsTheCounterexampleAsGenerated(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] >= 100
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('x>=100'))
            : new TestResult(info: $info, status: Status::Passed);

        $result = $interceptor->runTest($this->info(ShrinkOffStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);
        $example = $result->failure->getCounterExample();
        Assert::same($example->originalArguments, $example->shrunkArguments);
        Assert::same($example->shrinkSteps, 0);
    }

    public function phasesWithoutRandomNeverReachTheGenerators(): void
    {
        // The property below would be falsified by the first random draw; with
        // only Examples and Corpus in the list, that phase never happens.
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => new TestResult(
            info: $info,
            status: Status::Failed,
            failure: new \RuntimeException('would fail on any input'),
        );

        $result = $interceptor->runTest($this->info(ExamplesAndCorpusOnlyStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
    }

    public function envPropertyPhasesOverridesTheAttribute(): void
    {
        // The environment dials the suite: a CI gate cuts the random phase out
        // of a property that asks for it in its attribute.
        $restoreEnv = Env::set('PROPERTY_PHASES', 'examples,corpus');

        try {
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(
                info: $info,
                status: Status::Failed,
                failure: new \RuntimeException('would fail on any input'),
            );

            $result = $interceptor->runTest($this->info(FalsifyingStub::class, 'check'), $next);

            Assert::same($result->status, Status::Passed);
        } finally {
            $restoreEnv();
        }
    }

    public function envPropertyPhasesIgnoresSpacingAndCase(): void
    {
        $restoreEnv = Env::set('PROPERTY_PHASES', ' Examples , CORPUS ');

        try {
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(
                info: $info,
                status: Status::Failed,
                failure: new \RuntimeException('would fail on any input'),
            );

            $result = $interceptor->runTest($this->info(FalsifyingStub::class, 'check'), $next);

            Assert::same($result->status, Status::Passed);
        } finally {
            $restoreEnv();
        }
    }

    public function envPropertyPhasesRejectsAnUnknownStage(): void
    {
        $restoreEnv = Env::set('PROPERTY_PHASES', 'examples,rundom');

        try {
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same(
                $e->getMessage(),
                'PROPERTY_PHASES must be a comma-separated list of examples, corpus, random, shrink, got "rundom"',
            );
        } finally {
            $restoreEnv();
        }
    }

    public function derandomizeMakesAnUnseededPropertyRepeatItself(): void
    {
        // The seed is what the knob decides, and PropertyStarted reports the
        // one the engine ran with — comparing generated values would also pass
        // for a generator that ignored its seed.
        Assert::same(
            $this->resolvedSeed(DerandomizedStub::class),
            $this->resolvedSeed(DerandomizedStub::class),
        );
    }

    public function withoutDerandomizeAnUnseededPropertyDrawsAFreshSeed(): void
    {
        // The other half: without the knob the two runs draw independent seeds,
        // so the assertion above is about derandomization rather than a
        // degenerate generator.
        Assert::false($this->resolvedSeed(UnseededStub::class) === $this->resolvedSeed(UnseededStub::class));
    }

    public function envPropertyDerandomizeOverridesTheAttribute(): void
    {
        $restoreEnv = Env::set('PROPERTY_DERANDOMIZE', '1');

        try {
            Assert::same(
                $this->resolvedSeed(UnseededStub::class),
                $this->resolvedSeed(UnseededStub::class),
            );
        } finally {
            $restoreEnv();
        }
    }

    public function envPropertyDerandomizeZeroLeavesTheSuiteRandom(): void
    {
        $restoreEnv = Env::set('PROPERTY_DERANDOMIZE', '0');

        try {
            Assert::false(
                $this->resolvedSeed(UnseededStub::class) === $this->resolvedSeed(UnseededStub::class),
            );
        } finally {
            $restoreEnv();
        }
    }

    public function envPropertyPathReplaysTheRecordedDescent(): void
    {
        $failing = static fn(TestInfo $info): TestResult => $info->arguments[0] >= 100
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('x>=100'))
            : new TestResult(info: $info, status: Status::Passed);

        $interceptor = new PropertyInterceptor($this->createMessenger());
        $first = $interceptor->runTest($this->info(ShrinkPathStub::class, 'check'), $failing);
        Assert::instanceOf($first->failure, PropertyViolationException::class);

        $path = $first->failure->getCounterExample()->path;
        Assert::false($path === '');

        $restoreEnv = Env::set('PROPERTY_PATH', $path);

        try {
            $replayed = $interceptor->runTest($this->info(ShrinkPathStub::class, 'check'), $failing);

            Assert::instanceOf($replayed->failure, PropertyViolationException::class);
            Assert::same($replayed->failure->getCounterExample()->path, $path);
            Assert::same(
                $replayed->failure->getCounterExample()->shrunkArguments,
                $first->failure->getCounterExample()->shrunkArguments,
            );
            // A path is followed, not searched for: one body execution per
            // recorded step instead of one per candidate tried. The trial count
            // is what fails if the variable is ignored, since a deterministic
            // search reproduces the same path anyway.
            Assert::true(
                $replayed->failure->getCounterExample()->shrinkTrials
                    < $first->failure->getCounterExample()->shrinkTrials,
            );
        } finally {
            $restoreEnv();
        }
    }

    public function shrinkBudgetIsAcceptedByTheEngine(): void
    {
        // Generous budget: this pins the wiring, not the timing.
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

        $result = $interceptor->runTest($this->info(ShrinkBudgetStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
    }

    public function edgeCasesOffKeepsBoundaryValuesOutOfTheRun(): void
    {
        // The knob's whole purpose: a property that cannot use the edges would
        // otherwise throw away one run in five.
        Assert::same($this->edgesSeen(NoEdgeCasesStub::class), 0);
    }

    public function byDefaultTheBoundaryValuesAreStillGenerated(): void
    {
        Assert::true($this->edgesSeen(EdgeCasesStub::class) > 10);
    }

    public function envPropertyEdgeCasesOverridesTheAttribute(): void
    {
        $restoreEnv = Env::set('PROPERTY_EDGE_CASES', 'none');

        try {
            Assert::same($this->edgesSeen(EdgeCasesStub::class), 0);
        } finally {
            $restoreEnv();
        }
    }

    public function envPropertyEdgeCasesIgnoresSpacingAndCase(): void
    {
        $restoreEnv = Env::set('PROPERTY_EDGE_CASES', '  NONE  ');

        try {
            Assert::same($this->edgesSeen(EdgeCasesStub::class), 0);
        } finally {
            $restoreEnv();
        }
    }

    public function envPropertyEdgeCasesCanTurnThemBackOn(): void
    {
        $restoreEnv = Env::set('PROPERTY_EDGE_CASES', 'mixin');

        try {
            Assert::true($this->edgesSeen(NoEdgeCasesStub::class) > 10);
        } finally {
            $restoreEnv();
        }
    }

    public function envPropertyEdgeCasesRejectsAnUnknownValue(): void
    {
        $restoreEnv = Env::set('PROPERTY_EDGE_CASES', 'sometimes');

        try {
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same($e->getMessage(), 'PROPERTY_EDGE_CASES must be one of mixin, none, got "sometimes"');
        } finally {
            $restoreEnv();
        }
    }

    /**
     * How many of $stub's runs generated an in-range boundary value.
     *
     * @param class-string $stub
     */
    private function edgesSeen(string $stub): int
    {
        $listener = new CollectingListener();
        $interceptor = new PropertyInterceptor($this->createMessenger(), listeners: [$listener]);
        $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

        $interceptor->runTest($this->info($stub, 'check'), $next);

        $edges = 0;

        foreach ($listener->events as $event) {
            if ($event instanceof RunStarted && in_array($event->arguments['x'] ?? null, [0, 1, -1, -1_000_000, 1_000_000], strict: true)) {
                ++$edges;
            }
        }

        return $edges;
    }

    /**
     * The seed the engine ran $stub with, as PropertyStarted reports it — the
     * one observable that says what a seed knob decided.
     *
     * @param class-string $stub
     */
    private function resolvedSeed(string $stub): int
    {
        $listener = new CollectingListener();
        $interceptor = new PropertyInterceptor($this->createMessenger(), listeners: [$listener]);
        $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

        $interceptor->runTest($this->info($stub, 'check'), $next);

        foreach ($listener->events as $event) {
            if ($event instanceof PropertyStarted) {
                return $event->seed;
            }
        }

        Assert::fail('No PropertyStarted event was recorded');
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonNumericPropertyRuns(): void
    {
        $restoreEnv = Env::set('PROPERTY_RUNS', 'abc');

        try {
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);
        } finally {
            $restoreEnv();
        }
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function rejectsNonNumericPropertySeed(): void
    {
        $restoreEnv = Env::set('PROPERTY_SEED', 'abc');

        try {
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            $interceptor->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $next);
        } finally {
            $restoreEnv();
        }
    }

    public function reportsClassificationDistributionAfterPassingRuns(): void
    {
        $messenger = $this->createMessenger();
        $interceptor = new PropertyInterceptor($messenger);
        $next = static function (TestInfo $info): TestResult {
            Classify::label('checked');

            return new TestResult(info: $info, status: Status::Passed);
        };

        // PassingStub runs 5 times; every run records 'checked'.
        $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);
        $messages = $messenger->getMessages()->channel(Messenger::CHANNEL_STDOUT);

        Assert::same(count($messages), 1);
        Assert::string($messages[0]->content)->contains('checked 100% (5/5)');
    }

    public function coverageRequirementMetKeepsThePropertyPassing(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static function (TestInfo $info): TestResult {
            Classify::cover(condition: true, label: 'hit', minPercent: 50.0);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
    }

    public function coverageRequirementUnmetFailsThePassingProperty(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // Every run passes, but the required label never occurs: the pass is
        // vacuous and must be reported as a failure.
        $next = static function (TestInfo $info): TestResult {
            Classify::cover(condition: false, label: 'never', minPercent: 10.0);

            return (new TestResult(info: $info, status: Status::Passed))
                ->withAttribute('per-run', 'kept');
        };

        $result = $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, CoverageViolationException::class);
        Assert::string($result->failure->getMessage())->contains('"never" 0.0% < required 10.0% (0/5)');
        Assert::same($result->getAttribute('per-run'), 'kept');
    }

    public function coverageIsExactAtTheRequiredBoundary(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $calls = 0;
        // PassingStub runs 5 times; the label occurs on exactly 2 of 5 runs
        // (40%). A requirement of exactly 40% is met (strictly-below fails).
        $next = static function (TestInfo $info) use (&$calls): TestResult {
            ++$calls;
            Classify::cover($calls <= 2, 'sometimes', 40.0);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
    }

    public function coverageIgnoresDiscardedRunsInTheDenominator(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $calls = 0;
        // Three attempts are discarded, then all five required successful checks
        // carry the label => 100% of checks.
        $next = static function (TestInfo $info) use (&$calls): TestResult {
            ++$calls;

            if ($calls <= 3) {
                return new TestResult(info: $info, status: Status::Error, failure: new AssumptionSkipped());
            }

            Classify::cover(condition: true, label: 'hit', minPercent: 90.0);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
        Assert::same($calls, 8);
    }

    public function coverageWithoutAnySuccessfulRunFails(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // Every run is discarded but a requirement was registered: there is no
        // evidence the labelled case is reachable, so the property must fail.
        $next = static function (TestInfo $info): TestResult {
            Classify::cover(condition: true, label: 'unreached', minPercent: 10.0);

            return new TestResult(info: $info, status: Status::Error, failure: new AssumptionSkipped());
        };

        $result = $interceptor->runTest($this->info(DiscardBudgetStub::class, 'check'), $next);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, GaveUpException::class);
        Assert::same($result->failure->successfulRuns, 0);
    }

    public function falsificationWinsOverCoverage(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // A falsified property reports the counterexample, not the coverage.
        $next = static function (TestInfo $info): TestResult {
            Classify::cover(condition: false, label: 'never', minPercent: 10.0);

            return new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('boom'));
        };

        $result = $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);
    }

    public function coverageRequirementsDoNotLeakIntoTheNextProperty(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // First property falsifies with a registered requirement (which is
        // never assessed); the next property without cover() must pass.
        $failing = static function (TestInfo $info): TestResult {
            Classify::cover(condition: false, label: 'leftover', minPercent: 99.0);

            return new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('boom'));
        };
        $interceptor->runTest($this->info(FalsifyingStub::class, 'check'), $failing);

        $passing = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);
        $result = $interceptor->runTest($this->info(PassingStub::class, 'check'), $passing);

        Assert::same($result->status, Status::Passed);
    }

    public function verboseLogsEveryRunsArguments(): void
    {
        $restoreEnv = Env::set('PROPERTY_VERBOSE', '1');

        try {
            $messenger = $this->createMessenger();
            $interceptor = new PropertyInterceptor($messenger);
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            // PassingStub runs 5 times.
            $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);
            $messages = $messenger->getMessages()->channel(Messenger::CHANNEL_STDOUT);

            Assert::same(count($messages), 5);
            Assert::string($messages[0]->content)->contains('attempt 1: x=');
            Assert::string($messages[4]->content)->contains('attempt 5: x=');
        } finally {
            $restoreEnv();
        }
    }

    public function verboseLogsEveryAcceptedShrinkStep(): void
    {
        $restoreEnv = Env::set('PROPERTY_VERBOSE', '1');

        try {
            $messenger = $this->createMessenger();
            $interceptor = new PropertyInterceptor($messenger);
            // Fails iff x > 50; FalsifyingStub draws from [51, 100] with seed 1,
            // so shrinking descends to 51 through at least one accepted step.
            $next = static fn(TestInfo $info): TestResult => $info->arguments[0] > 50
                ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('x>50'))
                : new TestResult(info: $info, status: Status::Passed);

            $result = $interceptor->runTest($this->info(FalsifyingStub::class, 'check'), $next);

            Assert::instanceOf($result->failure, PropertyViolationException::class);
            $steps = $result->failure->getCounterExample()->shrinkSteps;

            $trace = array_values(array_filter(
                $messenger->getMessages()->channel(Messenger::CHANNEL_STDOUT),
                static fn(Message $message): bool => str_contains($message->content, 'shrink step'),
            ));

            // One trace line per accepted step; the last one lands on the minimum.
            Assert::same(count($trace), $steps);
            Assert::string($trace[0]->content)->contains('shrink step 1: x=');
            Assert::string($trace[$steps - 1]->content)->contains('-> 51');
        } finally {
            $restoreEnv();
        }
    }

    public function verboseZeroDisablesTheRunLog(): void
    {
        $restoreEnv = Env::set('PROPERTY_VERBOSE', '0');

        try {
            $messenger = $this->createMessenger();
            $interceptor = new PropertyInterceptor($messenger);
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

            Assert::same(count($messenger->getMessages()->channel(Messenger::CHANNEL_STDOUT)), 0);
        } finally {
            $restoreEnv();
        }
    }

    public function verboseRendersEveryArgumentStyle(): void
    {
        $restoreEnv = Env::set('PROPERTY_VERBOSE', '1');

        try {
            $messenger = $this->createMessenger();
            $interceptor = new PropertyInterceptor($messenger);
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            // MixedArgumentsStub generates a string, a bool, a null, an array
            // and a datetime — one run pins every branch of the formatter.
            $interceptor->runTest($this->info(MixedArgumentsStub::class, 'check'), $next);
            $messages = $messenger->getMessages()->channel(Messenger::CHANNEL_STDOUT);

            Assert::same(count($messages), 1);
            Assert::string($messages[0]->content)->contains('s="fixed"');
            Assert::string($messages[0]->content)->contains('b=false');
            Assert::string($messages[0]->content)->contains('n=null');
            Assert::string($messages[0]->content)->contains('a=[1, 2]');
            Assert::string($messages[0]->content)->contains('d=1970-01-01');
            Assert::string($messages[0]->content)->contains('i=7');
        } finally {
            $restoreEnv();
        }
    }

    public function verboseRendersStringableArgumentsViaToString(): void
    {
        $restoreEnv = Env::set('PROPERTY_VERBOSE', '1');

        try {
            $messenger = $this->createMessenger();
            $interceptor = new PropertyInterceptor($messenger);
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            $interceptor->runTest($this->info(StringableArgStub::class, 'check'), $next);
            $messages = $messenger->getMessages()->channel(Messenger::CHANNEL_STDOUT);

            Assert::same(count($messages), 1);
            Assert::string($messages[0]->content)->contains('s=STRINGABLE');
        } finally {
            $restoreEnv();
        }
    }

    public function deadlineFailsAnOverlongRun(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // The body passes but takes ~20 ms against a 5 ms deadline.
        $next = static function (TestInfo $info): TestResult {
            usleep(20_000);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(DeadlineStub::class, 'check'), $next);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, DeadlineExceededException::class);
        Assert::same($result->failure->timeoutMs, 5);
        // usleep(20_000) guarantees at least ~20 ms; the upper bound kills
        // unit-conversion mutants (ns-scale values would be astronomically big).
        Assert::true($result->failure->elapsedMs >= 19.0);
        Assert::true($result->failure->elapsedMs < 60_000.0);
        Assert::true(array_key_exists('x', $result->failure->arguments));
        Assert::string($result->failure->getMessage())->contains('deadline');
    }

    public function deadlineIgnoresRunsThatFinishInTime(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $calls = 0;
        $next = static function (TestInfo $info) use (&$calls): TestResult {
            ++$calls;

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(GenerousDeadlineStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
        Assert::same($calls, 3);
    }

    public function assertionFailureWinsOverAnOverlongRun(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // A run that is BOTH slow and failing reports the falsification — the
        // assertion failure is the actionable signal, the deadline is secondary.
        $next = static function (TestInfo $info): TestResult {
            usleep(20_000);

            return new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('boom'));
        };

        $result = $interceptor->runTest($this->info(DeadlineStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, PropertyViolationException::class);
    }

    public function budgetFailsThePhaseThatOvershoots(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // Each run takes ~5 ms against a 20 ms whole-phase budget, so the 1000
        // requested runs cannot complete.
        $next = static function (TestInfo $info): TestResult {
            usleep(5_000);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(TightBudgetStub::class, 'check'), $next);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, TimeBudgetExceededException::class);
        Assert::same($result->failure->budgetMs, 20);
        Assert::same($result->failure->requiredRuns, 1000);
        Assert::true($result->failure->successfulRuns >= 1);
        Assert::true($result->failure->successfulRuns < 1000);
        Assert::true($result->failure->elapsedMs > 20.0);
        Assert::true($result->failure->elapsedMs < 60_000.0);
    }

    public function budgetLargeEnoughDoesNotInterfere(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $calls = 0;
        $next = static function (TestInfo $info) use (&$calls): TestResult {
            ++$calls;

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(GenerousBudgetStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
        Assert::same($calls, 3);
    }

    public function deadlineAppliesToExplicitExamples(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static function (TestInfo $info): TestResult {
            usleep(20_000);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(DeadlineExampleStub::class, 'check'), $next);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, DeadlineExceededException::class);
        // The positional example is reported under the parameter's name.
        Assert::same($result->failure->arguments, ['x' => 7]);
    }

    public function reportsNoDistributionWhenNoLabelsRecorded(): void
    {
        $messenger = $this->createMessenger();
        $interceptor = new PropertyInterceptor($messenger);
        $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

        $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::same(count($messenger->getMessages()->channel(Messenger::CHANNEL_STDOUT)), 0);
    }

    public function failingExampleShortCircuitsBeforeRandomRuns(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $calls = 0;
        $next = static function (TestInfo $info) use (&$calls): TestResult {
            ++$calls;

            return $info->arguments[0] >= 100
                ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('too big'))
                : new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(ConventionExampleStub::class, 'check'), $next);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, ExampleViolationException::class);
        Assert::same($result->failure->getIndex(), 0);
        Assert::same($result->failure->getArguments(), [100]);
        // Only the first example ran; the second example and the random runs did not.
        Assert::same($calls, 1);
    }

    public function passingExamplesRunFirstThenRandomInputs(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $seen = [];
        $next = static function (TestInfo $info) use (&$seen): TestResult {
            $seen[] = $info->arguments[0];

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $interceptor->runTest($this->info(ConventionExampleStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
        // Both examples ran first, in order, before the 3 random runs.
        Assert::same($seen[0], 100);
        Assert::same($seen[1], 200);
        Assert::same(count($seen), 5);
    }

    public function attributeNamesTheExamplesMethod(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] === 5
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('five'))
            : new TestResult(info: $info, status: Status::Passed);

        $result = $interceptor->runTest($this->info(NamedExampleStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, ExampleViolationException::class);
        Assert::same($result->failure->getArguments(), [5]);
    }

    public function exampleFailureRendersIndexAndArguments(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] >= 100
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('boom'))
            : new TestResult(info: $info, status: Status::Passed);

        $result = $interceptor->runTest($this->info(ConventionExampleStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, ExampleViolationException::class);
        Assert::string($result->failure->getMessage())->contains('Explicit example #0');
        Assert::string($result->failure->getMessage())->contains('100');
        Assert::string($result->failure->getMessage())->contains('Failure:');
    }

    public function discardedExampleIsNotAFailure(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        // The first example (100) is discarded via Assume, not failed, so the
        // property proceeds and passes.
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] >= 100
            ? new TestResult(info: $info, status: Status::Error, failure: new AssumptionSkipped())
            : new TestResult(info: $info, status: Status::Passed);

        $result = $interceptor->runTest($this->info(ConventionExampleStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function throwsWhenExampleArityMismatches(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

        $interceptor->runTest($this->info(BadArityExampleStub::class, 'check'), $next);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function throwsWhenNamedExamplesMethodMissing(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

        $interceptor->runTest($this->info(MissingExampleMethodStub::class, 'check'), $next);
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function throwsWhenExampleIsNotAnArray(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

        $interceptor->runTest($this->info(NonArrayExampleStub::class, 'check'), $next);
    }

    public function recordsTheMinimisedFailureWhenStorageEnabled(): void
    {
        $dir = $this->tempStorageDir();
        $restoreEnv = Env::set('PROPERTY_DB', $dir);

        try {
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(
                info: $info,
                status: Status::Failed,
                failure: new \RuntimeException('always'),
            );

            $result = $interceptor->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $next);

            Assert::instanceOf($result->failure, PropertyViolationException::class);
            \assert($result->failure instanceof PropertyViolationException);

            // The minimised input is stored as data, so the regression replays as
            // a single run rather than a whole random phase.
            $entries = (new FilesystemCorpus($dir))->recall(NoSeedFalsifyingStub::class . '::check', ['x']);
            Assert::same(count($entries), 1);
            Assert::true($entries[0]->isValues());
            Assert::same($entries[0]->arguments, $result->failure->getCounterExample()->shrunkArguments);
            Assert::same($entries[0]->seed, $result->failure->getCounterExample()->seed);
        } finally {
            $restoreEnv();
            $this->cleanupDir($dir);
        }
    }

    public function replaysARecordedInputBeforeTheRandomPhase(): void
    {
        $dir = $this->tempStorageDir();
        $restoreEnv = Env::set('PROPERTY_DB', $dir);

        try {
            (new FilesystemCorpus($dir))->remember(
                NoSeedFalsifyingStub::class . '::check',
                $this->counterExample(['x' => 77], 4242),
                ['x'],
            );

            $seen = [];
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static function (TestInfo $info) use (&$seen): TestResult {
                $seen[] = $info->arguments;

                return new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('always'));
            };

            $result = $interceptor->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $next);

            // The recorded input is already minimal: it runs once, verbatim, and is
            // reported without shrinking.
            Assert::instanceOf($result->failure, RegressionViolationException::class);
            \assert($result->failure instanceof RegressionViolationException);
            Assert::same($result->failure->getArguments(), ['x' => 77]);
            Assert::same($result->failure->getSeed(), 4242);
            Assert::same($seen, [[77]]);
        } finally {
            $restoreEnv();
            $this->cleanupDir($dir);
        }
    }

    public function prunesARecordedInputWhenTheReplayNoLongerFails(): void
    {
        $dir = $this->tempStorageDir();
        $restoreEnv = Env::set('PROPERTY_DB', $dir);

        try {
            $storage = new FilesystemCorpus($dir);
            $id = NoSeedFalsifyingStub::class . '::check';
            $storage->remember($id, $this->counterExample(['x' => 77], 4242), ['x']);

            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            $result = $interceptor->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $next);

            Assert::same($result->status, Status::Passed);
            Assert::same($storage->recall($id, ['x']), []);
        } finally {
            $restoreEnv();
            $this->cleanupDir($dir);
        }
    }

    /**
     * A recorded input that the property now discards is out of the property's
     * domain — it can never falsify it again, so the entry goes.
     */
    public function prunesARecordedInputTheReplayDiscards(): void
    {
        $dir = $this->tempStorageDir();
        $restoreEnv = Env::set('PROPERTY_DB', $dir);

        try {
            $storage = new FilesystemCorpus($dir);
            $id = NoSeedFalsifyingStub::class . '::check';
            $storage->remember($id, $this->counterExample(['x' => 77], 4242), ['x']);

            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(
                info: $info,
                status: Status::Error,
                failure: new AssumptionSkipped(),
            );

            $result = $interceptor->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $next);

            // Every random run discards too, so the property gives up — but the
            // stale entry is gone.
            Assert::instanceOf($result->failure, GaveUpException::class);
            Assert::same($storage->recall($id, ['x']), []);
        } finally {
            $restoreEnv();
            $this->cleanupDir($dir);
        }
    }

    public function replaysARecordedSeedFirst(): void
    {
        $dir = $this->tempStorageDir();
        $restoreEnv = Env::set('PROPERTY_DB', $dir);

        try {
            // A counterexample the codec cannot represent is stored as a seed; the
            // replay phase must re-run the random phase with THAT seed.
            $this->writeSeedEntry($dir, NoSeedFalsifyingStub::class . '::check', 999);

            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(
                info: $info,
                status: Status::Failed,
                failure: new \RuntimeException('always'),
            );

            $result = $interceptor->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $next);

            Assert::instanceOf($result->failure, PropertyViolationException::class);
            Assert::same($result->failure->getCounterExample()->seed, 999);
        } finally {
            $restoreEnv();
            $this->cleanupDir($dir);
        }
    }

    public function prunesARecordedSeedWhenTheReplayNoLongerFails(): void
    {
        $dir = $this->tempStorageDir();
        $restoreEnv = Env::set('PROPERTY_DB', $dir);

        try {
            $id = NoSeedFalsifyingStub::class . '::check';
            $this->writeSeedEntry($dir, $id, 999);

            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);

            $result = $interceptor->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $next);

            Assert::same($result->status, Status::Passed);
            Assert::same((new FilesystemCorpus($dir))->recall($id, ['x']), []);
        } finally {
            $restoreEnv();
            $this->cleanupDir($dir);
        }
    }

    public function attributeSeedDisablesReplay(): void
    {
        $dir = $this->tempStorageDir();
        $restoreEnv = Env::set('PROPERTY_DB', $dir);

        try {
            // FalsifyingStub pins seed:1; a stored regression must be ignored so
            // the pinned reproducibility wins.
            (new FilesystemCorpus($dir))->remember(
                FalsifyingStub::class . '::check',
                $this->counterExample(['x' => 77], 999),
                ['x'],
            );

            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(
                info: $info,
                status: Status::Failed,
                failure: new \RuntimeException('always'),
            );

            $result = $interceptor->runTest($this->info(FalsifyingStub::class, 'check'), $next);

            Assert::instanceOf($result->failure, PropertyViolationException::class);
            Assert::same($result->failure->getCounterExample()->seed, 1);
        } finally {
            $restoreEnv();
            $this->cleanupDir($dir);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function counterExample(array $arguments, int $seed): CounterExample
    {
        return new CounterExample(
            seed: $seed,
            runsBeforeFailure: 0,
            originalArguments: $arguments,
            shrunkArguments: $arguments,
        );
    }

    /**
     * Storage writes a seed entry only for counterexamples the codec cannot
     * represent; this shortcut builds one directly.
     */
    private function writeSeedEntry(string $dir, string $id, int $seed): void
    {
        file_put_contents($dir . '/' . sha1($id) . '.json', json_encode([
            'format' => FilesystemCorpus::FORMAT_VERSION,
            'property' => $id,
            'entries' => [['kind' => 'seed', 'seed' => $seed, 'epoch' => FilesystemCorpus::SEQUENCE_EPOCH]],
        ], JSON_THROW_ON_ERROR));
    }

    public function storageDisabledWritesNothingAndDoesNotCrash(): void
    {
        $restoreEnv = Env::set('PROPERTY_DB', null);

        try {
            $interceptor = new PropertyInterceptor($this->createMessenger());
            $next = static fn(TestInfo $info): TestResult => new TestResult(
                info: $info,
                status: Status::Failed,
                failure: new \RuntimeException('always'),
            );

            $result = $interceptor->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $next);

            Assert::instanceOf($result->failure, PropertyViolationException::class);
        } finally {
            $restoreEnv();
        }
    }

    private function tempStorageDir(): string
    {
        $dir = sys_get_temp_dir() . '/prop-db-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, recursive: true);

        return $dir;
    }

    private function cleanupDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    public function passedPropertyCarriesPerRunAttributesOnAggregateResult(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $run = 0;
        // Mimics downstream per-run interceptors (e.g. codecov attaching its
        // CoverageResult): every run's result carries attributes the aggregate
        // must not lose — otherwise the property test vanishes from per-test
        // coverage and Infection never selects it for mutants.
        $next = static function (TestInfo $info) use (&$run): TestResult {
            ++$run;
            $result = (new TestResult(info: $info, status: Status::Passed))
                ->withAttribute('coverage', 'run-' . $run);

            return $run === 1 ? $result->withAttribute('only-first-run', value: true) : $result;
        };

        $result = $interceptor->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
        // Merged run-by-run: last write per key wins…
        Assert::same($result->getAttribute('coverage'), 'run-5');
        // …and a key written by an earlier run only is still preserved.
        Assert::true($result->getAttribute('only-first-run'));
    }

    public function falsifiedPropertyCarriesPerRunAttributesOnAggregateResult(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => ($info->arguments[0] > 50
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('x>50'))
            : new TestResult(info: $info, status: Status::Passed))
            ->withAttribute('coverage', 'collected');

        $result = $interceptor->runTest($this->info(FalsifyingStub::class, 'check'), $next);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, PropertyViolationException::class);
        Assert::same($result->getAttribute('coverage'), 'collected');
    }

    public function failedExampleCarriesRunAttributesOnAggregateResult(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger());
        $next = static fn(TestInfo $info): TestResult => ($info->arguments[0] >= 100
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('too big'))
            : new TestResult(info: $info, status: Status::Passed))
            ->withAttribute('coverage', 'example-run');

        $result = $interceptor->runTest($this->info(ConventionExampleStub::class, 'check'), $next);

        Assert::same($result->status, Status::Failed);
        Assert::instanceOf($result->failure, ExampleViolationException::class);
        Assert::same($result->getAttribute('coverage'), 'example-run');
    }

    private function info(string $class, string $method): TestInfo
    {
        $reflection = new \ReflectionMethod($class, $method);

        return new TestInfo(
            name: $method,
            caseInfo: new CaseInfo(
                definition: new CaseDefinition(name: 'Stub', type: 'test', file: Path::create(__FILE__)),
                suiteIdentity: new SuiteIdentity('Unit'),
            ),
            testDefinition: new TestDefinition(reflection: $reflection),
        );
    }

    private function createMessenger(): Messenger
    {
        return new MessengerHub(new class implements EventDispatcherInterface {
            #[\Override]
            public function dispatch(object $event): object
            {
                return $event;
            }
        });
    }
}
