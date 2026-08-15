<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Php85;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

final class Php85Provider
{
    /** @return array<string, ArbitraryInterface> */
    public static function generators(): array
    {
        return ['value' => Gen::constant(value: 11)];
    }
}
