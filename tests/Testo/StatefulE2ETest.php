<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\Testo\Tests\Fixture\StatefulPropertyFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\Env;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Testo\Testing\Attribute\TestingSuite;
use Testo\Testing\Helper\TestRunner;

/**
 * End-to-end coverage of stateful / model-based testing driven through the real
 * Testo runner: a #[Property] over {@see \Rasuvaeff\PropertyTesting\Gen::commands()}
 * falsifies a buggy stack and the runner shrinks the failing command sequence to
 * its minimal form.
 */
#[Test]
#[CoversNothing]
#[TestingSuite(path: __DIR__ . '/../Fixture')]
final class StatefulE2ETest
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

    public function falsifiesAndShrinksACommandSequence(): void
    {
        $result = TestRunner::runTest([StatefulPropertyFixture::class, 'buggyStackPreservesLifoOrder']);

        Assert::true($result->status->isFailure());
        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        $shrunk = $counterExample->shrunkArguments['sequence'];

        // The FIFO-vs-LIFO bug needs two distinct values pushed then popped, so
        // the minimal failing sequence is exactly three commands: Push, Push, Pop.
        Assert::instanceOf($shrunk, CommandSequence::class);
        Assert::same(count($shrunk->commands), 3);

        $original = $counterExample->originalArguments['sequence'];
        Assert::instanceOf($original, CommandSequence::class);
        Assert::true(count($original->commands) >= count($shrunk->commands));
    }

    public function rendersTheFailingSequenceAsAReadableTrace(): void
    {
        $result = TestRunner::runTest([StatefulPropertyFixture::class, 'buggyStackPreservesLifoOrder']);

        Assert::instanceOf($result->failure, PropertyViolationException::class);
        // The Stringable CommandSequence renders as its command trace, not
        // "[N element(s)]" — both the shrunk-argument line and the underlying
        // postcondition failure name the popping step.
        $message = $result->failure->getMessage();
        Assert::string($message)->contains('Push(');
        Assert::string($message)->contains('Pop()');
    }
}
