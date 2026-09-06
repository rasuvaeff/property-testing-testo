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
use Rasuvaeff\PropertyTesting\ExampleViolationException;
use Rasuvaeff\PropertyTesting\GaveUpException;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\RegressionViolationException;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Testo\PropertyInterceptor;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\CoreCompat;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\FakeClock;
use Rasuvaeff\PropertyTesting\Testo\VerboseListener;
use Rasuvaeff\PropertyTesting\TimeBudgetExceededException;
use Testo\Application\Internal\MessengerHub;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Common\Messenger;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\Identity\SuiteIdentity;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Definition\CaseDefinition;
use Testo\Core\Definition\TestDefinition;
use Testo\Core\Log\Level;
use Testo\Core\Value\Status;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Characterization of every outcome's exact human-facing message, produced by
 * driving the real interceptor with fixed seeds (and a {@see FakeClock} where
 * elapsed time appears in the text).
 *
 * These are golden strings on purpose: the runner split (see the evolution
 * plan, stage B) is compared against them, and the framework adapters must
 * keep rendering the same report. A deliberate wording change updates the
 * golden here in the same commit.
 *
 * Multi-line goldens are written with escaped "\n", never heredocs: git's
 * autocrlf checkout on the Windows CI runner rewrites heredoc newlines to
 * \r\n, and the goldens would drift from the runtime output.
 */
#[Test]
#[Covers(PropertyInterceptor::class)]
#[Covers(VerboseListener::class)]
#[Covers(PropertyRunner::class)]
#[Covers(PropertyViolationException::class)]
#[Covers(ExampleViolationException::class)]
#[Covers(GaveUpException::class)]
#[Covers(CoverageViolationException::class)]
#[Covers(DeadlineExceededException::class)]
#[Covers(TimeBudgetExceededException::class)]
#[Covers(RegressionViolationException::class)]
final class GoldenMessagesTest
{
    /** @var \Closure(): void */
    private \Closure $restoreCorpusEnv;

    /**
     * Any `PROPERTY_*` exported by the developer or the CI job would otherwise
     * reach every test here, and each of them rewrites something this suite
     * asserts on: `PROPERTY_DB` makes the interceptor replay and record a
     * corpus the assertions know nothing about (a falsification becomes a
     * `RegressionViolationException`, and corpus events join the pinned event
     * order), while `PROPERTY_RUNS`, `PROPERTY_SEED`, `PROPERTY_EDGE_CASES`
     * and the rest change the counts, seeds and messages being pinned. Tests
     * that want one set it themselves, after this.
     */
    #[BeforeTest]
    public function isolateFromAnAmbientCorpus(): void
    {
        $this->restoreCorpusEnv = Env::isolateProperty();
    }

    #[AfterTest]
    public function restoreTheAmbientCorpus(): void
    {
        ($this->restoreCorpusEnv)();
    }

    public function falsifiedMessage(): void
    {
        $result = $this->interceptor()->runTest($this->info(FalsifyingStub::class, 'check'), $this->failAboveFifty());

        Assert::instanceOf($result->failure, PropertyViolationException::class);

        Assert::same(
            $result->failure->getMessage(),
            "Property falsified after 0 successful run(s); seed=1\n"
                . "  Original: x=100\n"
                . "  Shrunk:   x=51 (1 shrink step(s), 1 trial(s))\n"
                . "  Changed:  x=100 -> 51\n"
                . "  Failure:  x>50\n"
                . '  Path:     x:0',
        );
    }

    public function exampleFailureMessage(): void
    {
        $next = static fn(TestInfo $info): TestResult => $info->arguments[0] >= 100
            ? new TestResult(info: $info, status: Status::Failed, failure: new \RuntimeException('too big'))
            : new TestResult(info: $info, status: Status::Passed);

        $result = $this->interceptor()->runTest($this->info(ConventionExampleStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, ExampleViolationException::class);
        Assert::same(
            $result->failure->getMessage(),
            "Explicit example #0 failed: [100]\n  Failure:  too big",
        );
    }

    public function gaveUpMessageAndDiscardWarning(): void
    {
        $messenger = $this->createMessenger();
        $next = static fn(TestInfo $info): TestResult => new TestResult(
            info: $info,
            status: Status::Error,
            failure: new AssumptionSkipped(),
        );

        $result = (new PropertyInterceptor($messenger))->runTest($this->info(DiscardBudgetStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, GaveUpException::class);
        Assert::same(
            $result->failure->getMessage(),
            'Property "check" gave up after 4 attempt(s): 0/5 successful run(s), 4 discarded (maximum 3). Narrow or construct the generators so inputs are valid by construction.',
        );

        $warnings = $messenger->getMessages()->channel(Messenger::CHANNEL_STDERR);
        Assert::same(count($warnings), 1);
        Assert::same(
            $warnings[0]->content,
            'Property "check" discarded 4 of 4 attempt(s) (100%); consider narrowing the generators',
        );
    }

    public function coverageViolationMessage(): void
    {
        $next = static function (TestInfo $info): TestResult {
            Classify::cover(condition: false, label: 'never', minPercent: 10.0);

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = $this->interceptor()->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::instanceOf($result->failure, CoverageViolationException::class);
        Assert::same(
            $result->failure->getMessage(),
            'Property "check" coverage not met: "never" 0.0% < required 10.0% (0/5)',
        );
    }

    public function classificationDistributionReport(): void
    {
        $messenger = $this->createMessenger();
        $next = static function (TestInfo $info): TestResult {
            Classify::label('small');

            return new TestResult(info: $info, status: Status::Passed);
        };

        $result = (new PropertyInterceptor($messenger))->runTest($this->info(PassingStub::class, 'check'), $next);

        Assert::same($result->status, Status::Passed);
        // Reported on STDERR, like the discard warning: Testo's renderer writes
        // that channel through as-is, while a STDOUT message is dropped at
        // normal verbosity in any run that is not a single passing test — which
        // hid the distribution from exactly the red run it is read on.
        Assert::same(count($messenger->getMessages()->channel(Messenger::CHANNEL_STDOUT)), 0);
        $lines = $messenger->getMessages()->channel(Messenger::CHANNEL_STDERR);
        Assert::same(count($lines), 1);
        Assert::same($lines[0]->content, 'Property "check" distribution: small 100% (5/5)');
        // Info, not Warning: a distribution is not a fault, and the level is
        // metadata the terminal renderer does not read.
        Assert::same($lines[0]->level, Level::Info);
    }

    /**
     * With a 6 ms step against a 5 ms deadline the elapsed time is exact, so
     * the whole message is deterministic — this is what the clock seam is for.
     */
    public function deadlineMessage(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger(), new FakeClock(6_000_000));

        $result = $interceptor->runTest($this->info(DeadlineStub::class, 'check'), $this->pass());

        Assert::instanceOf($result->failure, DeadlineExceededException::class);
        Assert::same($result->failure->elapsedMs, 6.0);
        Assert::same(
            $result->failure->getMessage(),
            'Property "check" run exceeded its 5 ms deadline (took 6.0 ms) for x=10. The input is pathological for the code under test, or the deadline is too tight.',
        );
    }

    public function budgetMessage(): void
    {
        $interceptor = new PropertyInterceptor($this->createMessenger(), new FakeClock(25_000_000));

        $result = $interceptor->runTest($this->info(TightBudgetStub::class, 'check'), $this->pass());

        Assert::instanceOf($result->failure, TimeBudgetExceededException::class);
        Assert::same($result->failure->elapsedMs, 25.0);
        Assert::same(
            $result->failure->getMessage(),
            'Property "check" exceeded its 20 ms time budget after 25.0 ms with 0/1000 successful run(s). Raise budgetMs, lower runs, or speed up the property body.',
        );
    }

    public function generationExhaustedMessage(): void
    {
        $result = $this->interceptor()->runTest($this->info(ExhaustedStub::class, 'check'), $this->pass());

        Assert::instanceOf($result->failure, CoreCompat::generationExhausted());
        Assert::same(
            $result->failure->getMessage(),
            'Gen::filter() exhausted after 100 attempt(s): the predicate rejected every generated value; widen the source arbitrary, raise the attempt budget, or build dependent values with Gen::flatMap() instead of filtering',
        );
    }

    public function regressionReplayMessage(): void
    {
        $dir = sys_get_temp_dir() . '/golden-corpus-' . bin2hex(random_bytes(6));
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

            $result = $this->interceptor()->runTest($this->info(NoSeedFalsifyingStub::class, 'check'), $this->failAboveFifty());

            Assert::instanceOf($result->failure, RegressionViolationException::class);
            Assert::same(
                $result->failure->getMessage(),
                "Recorded regression failed (originally found with seed 7): x=51\n  Failure:  x>50",
            );
        } finally {
            $restoreEnv();
            array_map(unlink(...), array_merge(glob($dir . '/*.json') ?: [], glob($dir . '/*.lock') ?: [], glob($dir . '/.corpus.lock') ?: []));
            rmdir($dir);
        }
    }

    public function verboseAttemptAndShrinkLines(): void
    {
        // PROPERTY_VERBOSE is the package's other human-facing output, and its
        // line formats are pinned here — as whole lines, so a moved quote or a
        // reordered segment fails rather than a `contains()` of the middle.
        $restoreEnv = Env::set('PROPERTY_VERBOSE', '1');

        try {
            $messenger = $this->createMessenger();
            $result = (new PropertyInterceptor($messenger))
                ->runTest($this->info(FalsifyingStub::class, 'check'), $this->failAboveFifty());

            Assert::instanceOf($result->failure, PropertyViolationException::class);

            $lines = array_map(
                static fn(object $message): string => (string) $message->content,
                $messenger->getMessages()->channel(Messenger::CHANNEL_STDOUT),
            );

            Assert::same(count($lines), 2);
            Assert::same($lines[0], 'Property "check" attempt 1: x=100');
            Assert::same($lines[1], 'Property "check" shrink step 1: x=100 -> 51');
        } finally {
            $restoreEnv();
        }
    }

    public function verboseDrawsLine(): void
    {
        // A run that draws nothing prints no draws line at all, so nothing else
        // pins this one.
        $restoreEnv = Env::set('PROPERTY_VERBOSE', '1');

        try {
            $messenger = $this->createMessenger();
            (new PropertyInterceptor($messenger))->runTest(
                $this->info(DrawGoldenStub::class, 'check'),
                static function (TestInfo $info): TestResult {
                    Gen::draw(Gen::intBetween(7, 7));

                    return new TestResult(info: $info, status: Status::Passed);
                },
            );

            $lines = array_map(
                static fn(object $message): string => (string) $message->content,
                $messenger->getMessages()->channel(Messenger::CHANNEL_STDOUT),
            );

            Assert::same(count($lines), 2);
            Assert::same($lines[0], 'Property "check" attempt 1: x=10');
            Assert::same($lines[1], 'Property "check" attempt 1 draws: draw#1=7');
        } finally {
            $restoreEnv();
        }
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

    private function interceptor(): PropertyInterceptor
    {
        return new PropertyInterceptor($this->createMessenger());
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
