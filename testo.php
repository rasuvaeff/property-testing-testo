<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            // tests/Fixture holds #[Property] fixtures that fail on purpose; they
            // are run in a nested application by PropertyRunnerE2ETest, so the
            // Unit suite must not pick them up directly.
            location: new FinderConfig(include: ['tests'], exclude: ['tests/Fixture']),
        ),
        new SuiteConfig(
            name: 'Benchmarks',
            location: ['benchmarks'],
        ),
        // The runnable examples (`vendor/bin/testo --suite=Examples`), in a
        // suite of their own so that `composer test` (Unit) leaves them alone.
        new SuiteConfig(
            name: 'Examples',
            location: ['examples'],
        ),
    ],
);
