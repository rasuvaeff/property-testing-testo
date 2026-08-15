<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

use Rasuvaeff\PropertyTesting\Runner\Redis\CorpusClient;
use Rasuvaeff\PropertyTesting\Runner\Redis\PhpRedisCorpusClient;

/**
 * `ext-redis`, connected on first use rather than on construction.
 *
 * Core's {@see PhpRedisCorpusClient} takes an already-connected `\Redis`,
 * which is the right contract for a class that does not own the connection —
 * but resolving `PROPERTY_DB` must not open a socket. A suite that names a
 * corpus it never touches (every property pinned by an explicit seed, say)
 * would otherwise fail at startup against a server it never needed, and CI
 * proved it: the eager version was red everywhere the extension was installed
 * and Redis was not running.
 *
 * predis is lazy by construction, so only this side needed the wrapper.
 *
 * @internal Driven by {@see CorpusFromEnv}.
 */
final class LazyPhpRedisCorpusClient implements CorpusClient
{
    private ?PhpRedisCorpusClient $client = null;

    public function __construct(
        private readonly RedisDsn $dsn,
    ) {}

    #[\Override]
    public function get(string $key): ?string
    {
        return $this->client()->get($key);
    }

    #[\Override]
    public function compareAndSet(string $key, ?string $expected, ?string $document): bool
    {
        return $this->client()->compareAndSet($key, $expected, $document);
    }

    private function client(): PhpRedisCorpusClient
    {
        if ($this->client instanceof PhpRedisCorpusClient) {
            return $this->client;
        }

        $redis = new \Redis();
        $redis->connect($this->dsn->host, $this->dsn->port);

        return $this->client = new PhpRedisCorpusClient($redis);
    }
}
