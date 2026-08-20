<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Rasuvaeff\PropertyTesting\Testo\RedisDsn;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Every answer this parser gives is a decision worth pinning: the default
 * port, the default prefix, and what counts as a DSN at all.
 */
#[Test]
#[Covers(RedisDsn::class)]
final class RedisDsnTest
{
    #[DataProvider('dsnProvider')]
    public function parsesHostPortAndPrefix(string $dsn, string $host, int $port, string $prefix): void
    {
        $parsed = RedisDsn::parse($dsn);

        Assert::same($parsed->host, $host);
        Assert::same($parsed->port, $port);
        Assert::same($parsed->prefix, $prefix);
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function dsnProvider(): iterable
    {
        yield 'host only' => ['redis://127.0.0.1', '127.0.0.1', 6379, 'property-testing:corpus:'];
        yield 'host and port' => ['redis://redis:6380', 'redis', 6380, 'property-testing:corpus:'];
        yield 'prefix in the path' => ['redis://redis:6380/suite-a:', 'redis', 6380, 'suite-a:'];
        yield 'prefix without a port' => ['redis://redis/suite-b:', 'redis', 6379, 'suite-b:'];
        yield 'trailing slash is not a prefix' => ['redis://redis/', 'redis', 6379, 'property-testing:corpus:'];
        yield 'nested path keeps its separators' => ['redis://redis/team/suite:', 'redis', 6379, 'team/suite:'];
    }

    public function theConnectionParametersAreTheOnesPredisTakes(): void
    {
        // Asserted here because at the call site the same literal could only be
        // checked by connecting to a server.
        Assert::same(
            RedisDsn::parse('redis://redis:6380/suite:')->toPredisParameters(),
            ['scheme' => 'tcp', 'host' => 'redis', 'port' => 6380],
        );
    }

    public function aDsnWithoutAHostIsAConfigurationError(): void
    {
        try {
            RedisDsn::parse('redis://');

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::same(
                $e->getMessage(),
                'PROPERTY_DB="redis://" is not a usable Redis DSN; expected redis://host[:port][/key-prefix]',
            );
        }
    }

    #[DataProvider('credentialledProvider')]
    public function aDsnWithCredentialsIsRejectedWithoutEchoingThePassword(string $dsn): void
    {
        try {
            RedisDsn::parse($dsn);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('credentials');
            Assert::false(str_contains($e->getMessage(), 's3cret'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function credentialledProvider(): iterable
    {
        yield 'user and password' => ['redis://user:s3cret@redis:6379'];
        yield 'password only' => ['redis://:s3cret@redis:6379'];
        yield 'user only' => ['redis://user@redis:6379'];
    }

    #[DataProvider('malformedProvider')]
    public function aMalformedDsnIsAConfigurationError(string $dsn): void
    {
        // Each of these makes parse_url() return false outright. The message
        // still has to quote the value, because it came from an environment
        // variable somebody typed by hand.
        try {
            RedisDsn::parse($dsn);

            Assert::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains($dsn);
            Assert::string($e->getMessage())->contains('expected redis://host[:port][/key-prefix]');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'no host at all' => ['redis://'];
        yield 'port without a host' => ['redis://:6379'];
        yield 'prefix without a host' => ['redis:///prefix:'];
        yield 'port that is not a number' => ['redis://host:notaport'];
    }
}
