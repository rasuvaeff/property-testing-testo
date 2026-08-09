<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Internal\Path;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\PropertyTesting\AssumptionSkipped;
use Rasuvaeff\PropertyTesting\CounterExample;
use Rasuvaeff\PropertyTesting\DeadlineExceededException;
use Rasuvaeff\PropertyTesting\Event\CorpusPruned;
use Rasuvaeff\PropertyTesting\Event\CorpusReplayed;
use Rasuvaeff\PropertyTesting\Event\CorpusStored;
use Rasuvaeff\PropertyTesting\Event\ExampleFinished;
use Rasuvaeff\PropertyTesting\Event\ExampleStarted;
use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\Event\PropertyFinished;
use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Event\RunPassed;
use Rasuvaeff\PropertyTesting\Event\ShrinkAccepted;
use Rasuvaeff\PropertyTesting\Event\ShrinkTried;
use Rasuvaeff\PropertyTesting\GaveUpException;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\RegressionViolationException;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Testo\PropertyInterceptor;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\CollectingListener;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\FakeClock;
use Testo\Application\Internal\MessengerHub;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\Identity\SuiteIdentity;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Definition\CaseDefinition;
use Testo\Core\Definition\TestDefinition;
use Testo\Core\Value\Status;
use Testo\Test;

/**
 * Characterization of the exact event sequence per lifecycle — pass, discard,
 * falsification with shrinking, examples, and the corpus phases. The runner
 * split (stages D–E of the evolution plan) is compared against these
 * sequences.
 *
 * Deliberately asserted on exact shapes: an extra, missing or reordered event
 * is an observable engine change, not an implementation detail.
 */
#[Test]
#[Covers(PropertyInterceptor::class)]
#[Covers(PropertyRunner::class)]
final class EventOrderTest
{
    public function passingPropertyEmitsStartRunPairsFinish(): void
    {
        $listener = new CollectingListener();

        $result = $this->interceptor($listener)->runTest($this->info(PassingStub::class, 'check'), $this->pass());

        Assert::same($result->status, Status::Passed);
        Assert::same($listener->shapes(), [
            'PropertyStarted',
            'RunStarted', 'RunPassed',
            'RunStarted', 'RunPassed',
            'RunStarted', 'RunPassed',
            'RunStarted', 'RunPassed',
            'RunStarted', 'RunPassed',
            'PropertyFinished',
        ]);

        $started = $listener->ofType(PropertyStarted::class)[0];
        Assert::same($started->propertyId, PassingStub::class . '::check');
        Assert::same($started->seed, 1);
        Assert::same($started->runs, 5);

        Assert::same(
            array_map(static fn(RunPassed $run): int => $run->attempt, $listener->ofType(RunPassed::class)),
            [1, 2, 3, 4, 5],
        );
        Assert::null($listener->ofType(PropertyFinished::class)[0]->failure);
    }

    public function discardedRunsEmitRunDiscardedUntilGivingUp(): void
    {
        $listener = new CollectingListener();
        $next = static fn(TestInfo $info): TestResult => new TestResult(
            info: $info,
            status: Status::Error,
            failure: new AssumptionSkipped(),
        );

        $result = $this->interceptor($listener)->runTest($this->info(DiscardBudgetStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, GaveUpException::class);
        Assert::same($listener->shapes(), [
            'PropertyStarted',
            'RunStarted', 'RunDiscarded',
            'RunStarted', 'RunDiscarded',
            'RunStarted', 'RunDiscarded',
            'RunStarted', 'RunDiscarded',
            'PropertyFinished',
        ]);
        Assert::instanceOf($listener->ofType(PropertyFinished::class)[0]->failure, GaveUpException::class);
    }

    /**
     * Seed 1 falsifies on the first attempt and shrinks 100 -> 51 in a single
     * accepted trial (the golden falsified message pins the same descent).
     */
    public function falsificationEmitsRunFailedThenShrinkEventsThenFinish(): void
    {
        $listener = new CollectingListener();

        $result = $this->interceptor($listener)->runTest($this->info(FalsifyingStub::class, 'check'), $this->failAboveFifty());

        Assert::instanceOf($result->failure, PropertyViolationException::class);
        Assert::same($listener->shapes(), [
            'PropertyStarted',
            'RunStarted', 'RunFailed',
            'ShrinkTried', 'ShrinkAccepted',
            'PropertyFinished',
        ]);

        $tried = $listener->ofType(ShrinkTried::class)[0];
        Assert::true($tried->accepted);
        Assert::same($tried->candidate, 51);

        $acceptedStep = $listener->ofType(ShrinkAccepted::class)[0];
        Assert::same($acceptedStep->step, 1);
        Assert::same($acceptedStep->parameter, 'x');
        Assert::same($acceptedStep->before, 100);
        Assert::same($acceptedStep->after, 51);
    }

    public function examplesEmitBeforeTheRandomPhase(): void
    {
        $listener = new CollectingListener();

        $result = $this->interceptor($listener)->runTest($this->info(ConventionExampleStub::class, 'check'), $this->pass());

        Assert::same($result->status, Status::Passed);
        Assert::same($listener->shapes(), [
            'PropertyStarted',
            'ExampleStarted', 'ExampleFinished',
            'ExampleStarted', 'ExampleFinished',
            'RunStarted', 'RunPassed',
            'RunStarted', 'RunPassed',
            'RunStarted', 'RunPassed',
            'PropertyFinished',
        ]);
        Assert::same($listener->ofType(ExampleStarted::class)[0]->arguments, [100]);
        Assert::same(
            array_map(static fn(ExampleFinished $e): ?\Throwable => $e->failure, $listener->ofType(ExampleFinished::class)),
            [null, null],
        );
    }

    public function failingExampleShortCircuitsTheLifecycle(): void
    {
        $listener = new CollectingListener();
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] >= 100
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('too big'))
            : new TestResult(info: $info, status: Status::Passed);

        $this->interceptor($listener)->runTest($this->info(ConventionExampleStub::class, 'check'), $next);

        Assert::same($listener->shapes(), [
            'PropertyStarted',
            'ExampleStarted', 'ExampleFinished',
            'PropertyFinished',
        ]);
        Assert::instanceOf($listener->ofType(ExampleFinished::class)[0]->failure, \RuntimeException::class);
    }

    /**
     * A timed-out example must not be announced as successful: ExampleFinished
     * carries the deadline failure the property then finishes with.
     */
    public function timedOutExampleAnnouncesItsDeadlineFailure(): void
    {
        $listener = new CollectingListener();
        $interceptor = new PropertyInterceptor($this->createMessenger(), new FakeClock(6_000_000), [$listener]);

        $result = $interceptor->runTest($this->info(DeadlineExampleStub::class, 'check'), $this->pass());

        Assert::instanceOf($result->failure, DeadlineExceededException::class);
        Assert::same($listener->shapes(), [
            'PropertyStarted',
            'ExampleStarted', 'ExampleFinished',
            'PropertyFinished',
        ]);
        Assert::instanceOf($listener->ofType(ExampleFinished::class)[0]->failure, DeadlineExceededException::class);
    }

    public function reproducedRegressionEmitsReplayThenFinish(): void
    {
        $listener = new CollectingListener();

        $this->withCorpusEntry(function (string $dir) use ($listener): void {
            $result = $this->interceptor($listener)->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $this->failAboveFifty());

            Assert::instanceOf($result->failure, RegressionViolationException::class);
        });

        Assert::same($listener->shapes(), [
            'PropertyStarted',
            'CorpusReplayed',
            'PropertyFinished',
        ]);

        $replayed = $listener->ofType(CorpusReplayed::class)[0];
        Assert::true($replayed->isValues);
        Assert::same($replayed->arguments, ['x' => 51]);
        Assert::same($replayed->seed, 7);
    }

    public function fixedRegressionEmitsPruneThenRunsTheRandomPhase(): void
    {
        $listener = new CollectingListener();

        $this->withCorpusEntry(function (string $dir) use ($listener): void {
            $result = $this->interceptor($listener)->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $this->pass());

            Assert::same($result->status, Status::Passed);
        });

        Assert::same($listener->shapes(), [
            'PropertyStarted',
            'CorpusReplayed',
            'CorpusPruned',
            'RunStarted', 'RunPassed',
            'PropertyFinished',
        ]);
        Assert::true($listener->ofType(CorpusPruned::class)[0]->isValues);
    }

    public function recordedFalsificationEmitsCorpusStoredBeforeFinish(): void
    {
        $listener = new CollectingListener();
        $dir = sys_get_temp_dir() . '/event-corpus-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, recursive: true);
        $restoreEnv = Env::set('PROPERTY_DB', $dir);

        try {
            $result = $this->interceptor($listener)->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $this->failAboveFifty());

            Assert::instanceOf($result->failure, PropertyViolationException::class);

            $shapes = $listener->shapes();
            Assert::same(array_slice($shapes, -2), ['CorpusStored', 'PropertyFinished']);
            Assert::same(
                $listener->ofType(CorpusStored::class)[0]->counterExample,
                $result->failure->getCounterExample(),
            );
        } finally {
            $restoreEnv();
            $this->cleanup($dir);
        }
    }

    /**
     * A listener observes the run; its failure is an infrastructure failure of
     * the run itself and must not be swallowed.
     */
    #[ExpectException(\DomainException::class)]
    public function listenerExceptionAbortsTheRun(): void
    {
        $throwing = new class implements PropertyListener {
            #[\Override]
            public function onEvent(PropertyEvent $event): void
            {
                throw new \DomainException('listener broke');
            }
        };

        $this->interceptor($throwing)->runTest($this->info(PassingStub::class, 'check'), $this->pass());
    }

    /**
     * The built-in verbose trace is the exception to the policy above: a bug
     * in it must not fail every consumer running with PROPERTY_VERBOSE.
     */
    public function verboseListenerSwallowsItsOwnFailure(): void
    {
        $restoreEnv = Env::set('PROPERTY_VERBOSE', '1');

        try {
            $messenger = new MessengerHub(new class implements EventDispatcherInterface {
                #[\Override]
                public function dispatch(object $event): object
                {
                    throw new \LogicException('messenger down');
                }
            });

            $result = (new PropertyInterceptor($messenger))->runTest($this->info(PassingStub::class, 'check'), $this->pass());

            Assert::same($result->status, Status::Passed);
        } finally {
            $restoreEnv();
        }
    }

    /**
     * @param \Closure(string): void $scenario Receives the corpus directory with
     *   one recorded values entry (x=51, seed 7) for NoSeedFalsifyingStub.
     */
    private function withCorpusEntry(\Closure $scenario): void
    {
        $dir = sys_get_temp_dir() . '/event-corpus-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, recursive: true);
        $restoreEnv = Env::set('PROPERTY_DB', $dir);

        try {
            (new FilesystemCorpus($dir))->remember(
                NoSeedFalsifyingStub::class . '::check',
                new CounterExample(
                    seed: 7,
                    runsBeforeFailure: 0,
                    originalArguments: ['x' => 51],
                    shrunkArguments: ['x' => 51],
                ),
                ['x'],
            );

            $scenario($dir);
        } finally {
            $restoreEnv();
            $this->cleanup($dir);
        }
    }

    private function cleanup(string $dir): void
    {
        array_map(unlink(...), array_merge(glob($dir . '/*.json') ?: [], glob($dir . '/*.lock') ?: []));
        rmdir($dir);
    }

    /**
     * @return \Closure(TestInfo): TestResult
     */
    private function failAboveFifty(): \Closure
    {
        return static fn(TestInfo $info): TestResult => $info->arguments[0] > 50
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('x>50'))
            : new TestResult(info: $info, status: Status::Passed);
    }

    /**
     * @return \Closure(TestInfo): TestResult
     */
    private function pass(): \Closure
    {
        return static fn(TestInfo $info): TestResult => new TestResult(info: $info, status: Status::Passed);
    }

    private function interceptor(PropertyListener $listener): PropertyInterceptor
    {
        return new PropertyInterceptor($this->createMessenger(), null, [$listener]);
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

    private function createMessenger(): MessengerHub
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
