<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

/**
 * `redis://host[:port][/key-prefix]`, taken apart.
 *
 * Parsing lives in its own type because it is the part with answers worth
 * asserting — a default port and a default prefix are decisions, and a
 * resolver that also builds clients hides them behind a connection.
 *
 * @internal Driven by {@see CorpusFromEnv}.
 */
final readonly class RedisDsn
{
    public const int DEFAULT_PORT = 6379;

    /** The engine's own default, so a DSN without a path behaves like the plain constructor. */
    public const string DEFAULT_PREFIX = 'property-testing:corpus:';

    /**
     * @param non-empty-string $host
     * @param non-empty-string $prefix
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $prefix,
    ) {}

    /**
     * The connection parameters predis takes.
     *
     * Here rather than at the call site so the shape is something a test can
     * assert: an array literal built where the client is constructed can only
     * be checked by connecting to a server.
     *
     * @return array{scheme: 'tcp', host: non-empty-string, port: int}
     */
    public function toPredisParameters(): array
    {
        return ['scheme' => 'tcp', 'host' => $this->host, 'port' => $this->port];
    }

    /**
     * @param string $dsn The value of `PROPERTY_DB`, already known to use the `redis` scheme.
     */
    public static function parse(string $dsn): self
    {
        $parts = parse_url($dsn);

        if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
            // Reject credentials rather than drop them silently: parse_url would
            // discard the userinfo, so the connection would go without AUTH
            // while the operator believes it authenticated. The message never
            // echoes the DSN — it would carry the password into the CI log.
            throw new \InvalidArgumentException(
                'PROPERTY_DB carries credentials in its userinfo, which is not supported; configure Redis AUTH out of band',
            );
        }

        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (!is_string($host) || $host === '') {
            // Safe to quote the DSN: a credentialed one was already rejected
            // above, so whatever reaches here carries no userinfo.
            throw new \InvalidArgumentException(sprintf(
                'PROPERTY_DB="%s" is not a usable Redis DSN; expected redis://host[:port][/key-prefix]',
                $dsn,
            ));
        }

        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;
        $prefix = is_string($path) ? ltrim($path, '/') : '';

        return new self(
            host: $host,
            port: is_int($port) ? $port : self::DEFAULT_PORT,
            prefix: $prefix === '' ? self::DEFAULT_PREFIX : $prefix,
        );
    }
}
