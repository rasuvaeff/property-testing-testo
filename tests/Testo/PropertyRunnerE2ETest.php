<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Rasuvaeff\PropertyTesting\CoverageViolationException;
use Rasuvaeff\PropertyTesting\DeadlineExceededException;
use Rasuvaeff\PropertyTesting\ExampleViolationException;
use Rasuvaeff\PropertyTesting\GaveUpException;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\RegressionViolationException;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Testo\Tests\Fixture\AssumeDiscardFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Fixture\CorpusRegressionFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Fixture\CoverageFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Fixture\DeadlineFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Fixture\DrawPropertyFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Fixture\ExampleFailingFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Fixture\ExhaustedFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Fixture\FalsifyingPropertyFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Fixture\GaveUpFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\CoreCompat;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\Env;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Core\Value\Status;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Testo\Testing\Attribute\TestingSuite;
use Testo\Testing\Helper\TestRunner;

/**
 * End-to-end coverage of the falsify / shrink / Assume loop driven through the
 * real Testo runner — not hand-built TestResult mocks like
 * {@see \Rasuvaeff\PropertyTesting\Testo\Tests\PropertyInterceptorTest}.
 *
 * It proves three things that only the real pipeline can confirm: the
 * #[Property] attribute self-registers (no plugin wiring), a thrown assertion is
 * routed into a {@see PropertyViolationException} that survives Testo's
 * Error->Failed conversion, and {@see \Rasuvaeff\PropertyTesting\Assume::that()}
 * discards runs without failing the property.
 */
#[Test]
#[CoversNothing]
#[TestingSuite(path: __DIR__ . '/../Fixture')]
final class PropertyRunnerE2ETest
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

    public function falsifiesAndShrinksThroughTheRealRunner(): void
    {
        $result = TestRunner::runTest([FalsifyingPropertyFixture::class, 'everyValueIsAtMostFifty']);

        Assert::true($result->status->isFailure());
        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        // Generator draws from [51, 100]; shrinking clamps toward the range, so
        // the minimal still-failing value is the lower bound, 51.
        Assert::same($counterExample->shrunkArguments['x'], 51);
        Assert::true($counterExample->originalArguments['x'] > 50);
    }

    public function surfacesTheUnderlyingAssertionFailure(): void
    {
        $result = TestRunner::runTest([FalsifyingPropertyFixture::class, 'everyValueIsAtMostFifty']);

        Assert::instanceOf($result->failure, PropertyViolationException::class);
        // The exception chains the real Testo assertion failure as `previous`
        // and renders a "Failure:" line, so the developer sees what broke.
        Assert::same($result->failure->getPrevious(), $result->failure->getCounterExample()->failure);
        Assert::string($result->failure->getMessage())->contains('Failure:');
    }

    public function inBodyDrawsShrinkThroughTheRealRunner(): void
    {
        $result = TestRunner::runTest([DrawPropertyFixture::class, 'everyDrawnIndexIsSmall']);

        Assert::true($result->status->isFailure());
        Assert::instanceOf($result->failure, PropertyViolationException::class);

        $counterExample = $result->failure->getCounterExample();
        // The drawn index shrinks to the smallest still-failing value (4) and
        // the parameter to its lower bound; the report renders the draw as a
        // `draw#N` pseudo-argument next to the named parameter.
        Assert::same($counterExample->shrunkArguments['draw#1'], 4);
        Assert::same($counterExample->shrunkArguments['size'], 10);
        Assert::string($result->failure->getMessage())->contains('draw#1');
    }

    public function assumeDiscardsRunsThroughTheRealRunner(): void
    {
        // x ranges over [-50, 50]; non-positive draws are discarded via Assume,
        // so the property holds and the test passes. If AssumptionSkipped were
        // not recognised, those draws would hit Assert::true($x > 0) and fail.
        $result = TestRunner::runTest([AssumeDiscardFixture::class, 'holdsOnlyForPositiveValues']);

        Assert::same($result->status, Status::Passed);
    }

    public function failingExampleShortCircuitsThroughTheRealRunner(): void
    {
        $result = TestRunner::runTest([ExampleFailingFixture::class, 'everyValueIsSmall']);

        Assert::true($result->status->isFailure());
        Assert::instanceOf($result->failure, ExampleViolationException::class);
        Assert::string($result->failure->getMessage())->contains('Explicit example #0');
    }

    public function unmetCoverageFailsThroughTheRealRunner(): void
    {
        $result = TestRunner::runTest([CoverageFixture::class, 'coversValuesAboveAThousand']);

        Assert::true($result->status->isFailure());
        Assert::instanceOf($result->failure, CoverageViolationException::class);
    }

    public function allDiscardedRunsGiveUpThroughTheRealRunner(): void
    {
        $result = TestRunner::runTest([GaveUpFixture::class, 'neverChecksAnything']);

        Assert::true($result->status->isFailure());
        Assert::instanceOf($result->failure, GaveUpException::class);
        Assert::same($result->failure->successfulRuns, 0);
    }

    public function exhaustedGenerationFailsCleanlyThroughTheRealRunner(): void
    {
        $result = TestRunner::runTest([ExhaustedFixture::class, 'neverGetsAValue']);

        Assert::true($result->status->isFailure());
        Assert::instanceOf($result->failure, CoreCompat::generationExhausted());
    }

    public function overlongRunMissesItsDeadlineThroughTheRealRunner(): void
    {
        $result = TestRunner::runTest([DeadlineFixture::class, 'everyRunIsSlow']);

        Assert::true($result->status->isFailure());
        Assert::instanceOf($result->failure, DeadlineExceededException::class);
        Assert::same($result->failure->timeoutMs, 5);
    }

    /**
     * The full corpus lifecycle against the real runner: a falsified property
     * records its minimised input, the next run replays it as a regression, and
     * once the "bug" is fixed the entry replays green and is pruned.
     */
    public function corpusRecordsReplaysAndPrunesThroughTheRealRunner(): void
    {
        $dir = sys_get_temp_dir() . '/e2e-corpus-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, recursive: true);
        // FIXTURE_PASS must start unset: the fixture passes while it is set, and
        // a caller that exported it would make the first run pass and the
        // PropertyViolationException assertion below fail.
        $restoreEnv = Env::setMany(['PROPERTY_DB' => $dir, 'FIXTURE_PASS' => null]);
        $propertyId = CorpusRegressionFixture::class . '::everyValueIsAtMostFifty';

        try {
            $recorded = TestRunner::runTest([CorpusRegressionFixture::class, 'everyValueIsAtMostFifty']);
            Assert::instanceOf($recorded->failure, PropertyViolationException::class);
            Assert::same(count((new FilesystemCorpus($dir))->recall($propertyId, ['x'])), 1);

            $replayed = TestRunner::runTest([CorpusRegressionFixture::class, 'everyValueIsAtMostFifty']);
            Assert::instanceOf($replayed->failure, RegressionViolationException::class);
            Assert::same(
                $replayed->failure->getArguments(),
                $recorded->failure->getCounterExample()->shrunkArguments,
            );

            putenv('FIXTURE_PASS=1');   // undone by $restoreEnv below
            $fixed = TestRunner::runTest([CorpusRegressionFixture::class, 'everyValueIsAtMostFifty']);
            Assert::same($fixed->status, Status::Passed);
            // The replay passed, so the entry served its purpose and is gone.
            Assert::same((new FilesystemCorpus($dir))->recall($propertyId, ['x']), []);
        } finally {
            $restoreEnv();
            array_map(unlink(...), array_merge(glob($dir . '/*.json') ?: [], glob($dir . '/*.lock') ?: [], glob($dir . '/.corpus.lock') ?: []));
            rmdir($dir);
        }
    }
}
