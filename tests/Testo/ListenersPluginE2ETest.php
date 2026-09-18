<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Rasuvaeff\PropertyTesting\Event\PropertyFinished;
use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Testo\Tests\Fixture\AssumeDiscardFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\CollectingListener;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\ListenersPlugin;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Core\Value\Status;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Testo\Testing\Attribute\TestingSuite;
use Testo\Testing\Helper\TestRunner;

/**
 * The README recipe for `PropertyInterceptor::__construct(listeners:)`, run
 * through the real Testo runner: a plugin registers an interceptor built with
 * the listeners, and that instance — not the one the attribute would create —
 * runs the property.
 */
#[Test]
#[CoversNothing]
#[TestingSuite(path: __DIR__ . '/../Fixture', plugins: [ListenersPlugin::class])]
final class ListenersPluginE2ETest
{
    /** @var \Closure(): void */
    private \Closure $restoreCorpusEnv;

    #[BeforeTest]
    public function isolateFromAnAmbientCorpus(): void
    {
        $this->restoreCorpusEnv = Env::isolateProperty();
    }

    #[AfterTest]
    public function restoreTheAmbientCorpus(): void
    {
        ($this->restoreCorpusEnv)();
        ListenersPlugin::$listener = null;
    }

    public function aListenerRegisteredThroughAPluginObservesTheProperty(): void
    {
        $listener = new CollectingListener();
        ListenersPlugin::$listener = $listener;

        $result = TestRunner::runTest([AssumeDiscardFixture::class, 'holdsOnlyForPositiveValues']);

        Assert::same($result->status, Status::Passed);
        // The nested application runs every fixture in the directory, so the
        // listener saw them all; the one asked for is among them exactly once.
        $id = AssumeDiscardFixture::class . '::holdsOnlyForPositiveValues';
        $started = array_filter($listener->ofType(PropertyStarted::class), static fn(PropertyStarted $e): bool => $e->propertyId === $id);
        $finished = array_filter($listener->ofType(PropertyFinished::class), static fn(PropertyFinished $e): bool => $e->propertyId === $id);

        Assert::same(count($started), 1);
        Assert::same(count($finished), 1);
    }
}
