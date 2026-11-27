<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

use Rasuvaeff\PropertyTesting\Runner\Corpus;
use Rasuvaeff\PropertyTesting\Runner\CorpusFactory;
use Rasuvaeff\PropertyTesting\Runner\Environment;

/**
 * The regression corpus the environment names: `PROPERTY_DB` (parsed by
 * core's {@see Environment}) plus the Redis `AUTH` password from
 * `PROPERTY_DB_PASSWORD`, which core leaves to the adapter so that it stays
 * out of the DSN — and out of every diagnostic that quotes the DSN.
 *
 * @internal
 */
final readonly class EnvironmentCorpus
{
    private function __construct() {}

    /**
     * @throws \InvalidArgumentException For a `PROPERTY_DB` core cannot resolve.
     */
    public static function of(Environment $environment): ?Corpus
    {
        return $environment->db === null
            ? null
            : CorpusFactory::fromDsn(dsn: $environment->db, password: self::password());
    }

    /**
     * The password, or null when the variable is unset or empty: an
     * exported-but-empty variable is not a password.
     *
     * @return non-empty-string|null
     */
    public static function password(): ?string
    {
        $password = getenv('PROPERTY_DB_PASSWORD');

        return $password === false || $password === '' ? null : $password;
    }
}
