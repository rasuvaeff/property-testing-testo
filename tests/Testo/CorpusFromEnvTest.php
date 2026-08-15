<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\Redis\PredisCorpusClient;
use Rasuvaeff\PropertyTesting\Runner\RedisCorpus;
use Rasuvaeff\PropertyTesting\Testo\CorpusFromEnv;
use Rasuvaeff\PropertyTesting\Testo\LazyPhpRedisCorpusClient;
use Rasuvaeff\PropertyTesting\Testo\Tests\Support\Env;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * `PROPERTY_DB` resolves to a corpus, and a `redis://` DSN is the one shape
 * that was previously unreachable from a suite: the engine ships
 * {@see RedisCorpus} but reads no environment, so without this the shared
 * corpus existed only for harnesses that construct the runner themselves.
 *
 * The DSN cases build a corpus without talking to a server — both clients
 * connect lazily — which is what keeps these unit tests rather than a suite
 * that needs Redis to run.
 */
#[Test]
#[Covers(CorpusFromEnv::class)]
final class CorpusFromEnvTest
{
    public function unsetMeansNoCorpusAtAll(): void
    {
        $restore = Env::set('PROPERTY_DB', null);

        try {
            Assert::null(CorpusFromEnv::resolve());
        } finally {
            $restore();
        }
    }

    public function emptyMeansNoCorpusEither(): void
    {
        $restore = Env::set('PROPERTY_DB', '');

        try {
            Assert::null(CorpusFromEnv::resolve());
        } finally {
            $restore();
        }
    }

    public function aPathIsStillADirectoryCorpus(): void
    {
        $restore = Env::set('PROPERTY_DB', sys_get_temp_dir() . '/property-db');

        try {
            Assert::instanceOf(CorpusFromEnv::resolve(), FilesystemCorpus::class);
        } finally {
            $restore();
        }
    }

    public function resolvingADsnNeverOpensASocket(): void
    {
        // The reason the phpredis client is wrapped: CI installs ext-redis and
        // runs no Redis, and an eager connect() made every job red. Nothing
        // here is running a server either — that this returns at all is the
        // assertion.
        $restore = Env::set('PROPERTY_DB', 'redis://127.0.0.1:6399/never-touched:');

        try {
            Assert::instanceOf(CorpusFromEnv::resolve(), RedisCorpus::class);
        } finally {
            $restore();
        }
    }

    public function extRedisIsPreferredWhenItIsLoaded(): void
    {
        // The documented preference, asserted in whichever environment this
        // runs: the extension needs no autoloaded dependency, so it wins when
        // present, and predis is the fallback. CI has the extension; the
        // composer image does not, and both must be right.
        $restore = Env::set('PROPERTY_DB', 'redis://127.0.0.1:6399');

        try {
            $corpus = CorpusFromEnv::resolve();
            Assert::instanceOf($corpus, RedisCorpus::class);

            $client = (new \ReflectionProperty($corpus, 'client'))->getValue($corpus);

            Assert::instanceOf(
                $client,
                extension_loaded('redis') ? LazyPhpRedisCorpusClient::class : PredisCorpusClient::class,
            );
        } finally {
            $restore();
        }
    }

    public function aRedisDsnIsASharedCorpus(): void
    {
        $restore = Env::set('PROPERTY_DB', 'redis://127.0.0.1:6379');

        try {
            Assert::instanceOf(CorpusFromEnv::resolve(), RedisCorpus::class);
        } finally {
            $restore();
        }
    }

    public function theDsnPathIsTheKeyPrefix(): void
    {
        // Two suites can share one server without sharing a corpus. What the
        // prefix parses to is RedisDsn's business and is pinned there; this
        // asserts only that the resolver hands it over.
        $restore = Env::set('PROPERTY_DB', 'redis://127.0.0.1:6379/suite-a:');

        try {
            $corpus = CorpusFromEnv::resolve();
            Assert::instanceOf($corpus, RedisCorpus::class);

            $prefix = (new \ReflectionProperty($corpus, 'prefix'))->getValue($corpus);
            Assert::same($prefix, 'suite-a:');
        } finally {
            $restore();
        }
    }

    public function anUnusableDsnSurfacesAsAConfigurationError(): void
    {
        $restore = Env::set('PROPERTY_DB', 'redis://');

        try {
            CorpusFromEnv::resolve();

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('not a usable Redis DSN');
        } finally {
            $restore();
        }
    }
}
