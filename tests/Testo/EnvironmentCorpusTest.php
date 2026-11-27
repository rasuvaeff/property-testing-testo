<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Testo;

use Rasuvaeff\PropertyTesting\Runner\CorpusFactory;
use Rasuvaeff\PropertyTesting\Runner\Environment;
use Rasuvaeff\PropertyTesting\Testo\EnvironmentCorpus;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\Env;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * `PROPERTY_DB_PASSWORD` reaches the corpus factory. Core hands out one corpus
 * per distinct DSN and password within a process, so the identity of the
 * returned corpus shows which password it was resolved with.
 */
#[Test]
#[Covers(EnvironmentCorpus::class)]
final class EnvironmentCorpusTest
{
    /** @var list<\Closure(): void> */
    private array $restore = [];

    private string $dsn;

    #[BeforeTest]
    public function isolate(): void
    {
        $this->restore = [Env::isolateProperty()];
        $this->dsn = sys_get_temp_dir() . '/prop-db-password-' . bin2hex(random_bytes(6));
    }

    #[AfterTest]
    public function restore(): void
    {
        foreach (array_reverse($this->restore) as $undo) {
            $undo();
        }
    }

    public function noCorpusWithoutPropertyDb(): void
    {
        $this->set('secret');

        Assert::null(EnvironmentCorpus::of(new Environment()));
    }

    public function passesThePasswordToTheCorpusFactory(): void
    {
        $this->set('secret');

        $corpus = EnvironmentCorpus::of(new Environment(db: $this->dsn));

        Assert::true($corpus === CorpusFactory::fromDsn(dsn: $this->dsn, password: 'secret'));
        Assert::false($corpus === CorpusFactory::fromDsn(dsn: $this->dsn));
    }

    public function resolvesWithoutAPasswordWhenTheVariableIsUnset(): void
    {
        $this->set(null);

        $corpus = EnvironmentCorpus::of(new Environment(db: $this->dsn));

        Assert::true($corpus === CorpusFactory::fromDsn(dsn: $this->dsn));
        Assert::false($corpus === CorpusFactory::fromDsn(dsn: $this->dsn, password: 'secret'));
    }

    public function anEmptyPasswordMeansNone(): void
    {
        $this->set('');

        Assert::null(EnvironmentCorpus::password());
        Assert::true(EnvironmentCorpus::of(new Environment(db: $this->dsn)) === CorpusFactory::fromDsn(dsn: $this->dsn));
    }

    public function anUnsetPasswordMeansNone(): void
    {
        $this->set(null);

        Assert::null(EnvironmentCorpus::password());
    }

    public function readsThePasswordVerbatim(): void
    {
        $this->set('0');

        Assert::same(EnvironmentCorpus::password(), '0');
    }

    private function set(?string $password): void
    {
        $this->restore[] = Env::set('PROPERTY_DB_PASSWORD', $password);
    }
}
