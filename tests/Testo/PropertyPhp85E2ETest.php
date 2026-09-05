<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Rasuvaeff\PropertyTesting\Testo\Tests\Php85\Php85AttributeFixture;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\Env;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Core\Value\Status;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Testo\Testing\Attribute\TestingSuite;
use Testo\Testing\Helper\TestRunner;

#[Test]
#[CoversNothing]
#[TestingSuite(path: __DIR__ . '/../../tests-php85')]
final class PropertyPhp85E2ETest
{
    private \Closure $restoreCorpusEnv;

    /**
     * The fixtures run through the real interceptor, so an exported
     * `PROPERTY_*` reaches them the same way it reaches every other suite here.
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

    public function supportsInlineClosureAndFirstClassCallable(): void
    {
        if (PHP_VERSION_ID < 80500) {
            Assert::true(actual: true);

            return;
        }

        require_once dirname(__DIR__, 2) . '/tests-php85/Php85Provider.php';
        require_once dirname(__DIR__, 2) . '/tests-php85/Php85AttributeFixture.php';

        $inline = TestRunner::runTest([Php85AttributeFixture::class, 'inlineClosure']);
        $firstClass = TestRunner::runTest([Php85AttributeFixture::class, 'firstClassCallable']);

        Assert::same($inline->status, Status::Passed);
        Assert::same($firstClass->status, Status::Passed);
    }
}
