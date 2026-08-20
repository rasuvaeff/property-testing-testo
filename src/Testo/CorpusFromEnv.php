<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

use Rasuvaeff\PropertyTesting\Runner\Corpus;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\Redis\CorpusClient;
use Rasuvaeff\PropertyTesting\Runner\Redis\PredisCorpusClient;
use Rasuvaeff\PropertyTesting\Runner\RedisCorpus;

/**
 * `PROPERTY_DB`, as a {@see Corpus}.
 *
 * The engine deliberately reads no environment — resolving where a corpus
 * lives is the adapter's job, which is why this class exists here rather than
 * in core. A directory keeps meaning what it always meant; a `redis://` DSN
 * means the corpus is shared, so a falsification found on a laptop replays in
 * CI and one found in CI replays on the next laptop.
 *
 * ```bash
 * PROPERTY_DB=/tmp/corpus            vendor/bin/testo
 * PROPERTY_DB=redis://127.0.0.1:6379 vendor/bin/testo
 * PROPERTY_DB=redis://redis:6379/my-suite: vendor/bin/testo
 * ```
 *
 * @internal Driven by the property interceptor.
 */
final class CorpusFromEnv
{
    /**
     * A leading URI scheme: `redis` in `redis://host`. A value without one is
     * a directory path; a value with a scheme that is not `redis` is a typo
     * (`rediss://`, `Redis://`) or the wrong backend — never a directory.
     */
    private const string SCHEME_PATTERN = '#^([a-zA-Z][a-zA-Z0-9+.\-]*)://#';

    private function __construct()
    {
        // Static helper; not instantiable.
    }

    /**
     * The corpus `PROPERTY_DB` asks for, or null when it is unset (storage
     * off, nothing written).
     */
    public static function resolve(): ?Corpus
    {
        $dsn = getenv('PROPERTY_DB');

        if ($dsn === false || $dsn === '') {
            return null;
        }

        if (preg_match(self::SCHEME_PATTERN, $dsn, $matches) !== 1) {
            return new FilesystemCorpus($dsn);
        }

        if (strtolower($matches[1]) !== 'redis') {
            // Not a directory: a suite told to share its corpus, quietly
            // writing to a directory nobody reads, is worse than one that
            // stops. The DSN itself is not echoed — it may carry credentials.
            throw new \InvalidArgumentException(sprintf(
                'PROPERTY_DB uses an unsupported scheme "%s://"; use redis:// for a shared corpus or a plain directory path for a local one',
                $matches[1],
            ));
        }

        $parsed = RedisDsn::parse($dsn);

        return new RedisCorpus(self::client($parsed, $dsn), $parsed->prefix);
    }

    /**
     * A client for the DSN, preferring `ext-redis` when it is loaded because
     * it needs no autoloaded dependency at all.
     *
     * Neither available is a configuration error, not a silent fall back to
     * the filesystem: a suite told to share its corpus and quietly writing to
     * a directory nobody reads is worse than one that stops.
     */
    private static function client(RedisDsn $dsn, string $raw): CorpusClient
    {
        if (extension_loaded('redis')) {
            // Lazily: resolving PROPERTY_DB must not open a socket, or a suite
            // that names a corpus it never touches fails at startup.
            return new LazyPhpRedisCorpusClient($dsn);
        }

        if (class_exists(\Predis\Client::class)) {
            return new PredisCorpusClient(new \Predis\Client($dsn->toPredisParameters()));
        }

        throw new \InvalidArgumentException(sprintf(
            'PROPERTY_DB="%s" needs a Redis client: install ext-redis or require predis/predis',
            $raw,
        ));
    }

}
