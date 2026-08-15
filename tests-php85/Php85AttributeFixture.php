<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Php85;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Test;

final class Php85AttributeFixture
{
    #[Test]
    #[Property(
        runs: 1,
        seed: 1,
        generators: static function (): array {
            return ['value' => Gen::constant(value: 10)];
        },
    )]
    public function inlineClosure(int $value): void
    {
        Assert::same($value, 10);
    }

    #[Test]
    #[Property(
        runs: 1,
        seed: 1,
        generators: Php85Provider::generators(...),
    )]
    public function firstClassCallable(int $value): void
    {
        Assert::same($value, 11);
    }
}
