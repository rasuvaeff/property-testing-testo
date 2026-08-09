<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;

/**
 * Fixtures for {@see \Rasuvaeff\PropertyTesting\Testo\Tests\PropertyInterceptorTest}.
 * Not covered by coverage: they exist only to carry #[Property] attributes and
 * generators methods discoverable via reflection.
 */
final class PassingStub
{
    #[Property(runs: 5, seed: 1, generators: 'provide')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}

final class DiscardBudgetStub
{
    #[Property(runs: 5, seed: 1, generators: 'provide', maxDiscards: 3)]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}

final class DefaultDiscardBudgetStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}

final class ConventionStub
{
    #[Property(runs: 3, seed: 1)]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function checkGenerators(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}

final class FalsifyingStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(51, 100)];
    }
}

final class MultiParamFalsifyingStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(int $a, int $b): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['a' => Gen::intBetween(0, 10), 'b' => Gen::intBetween(51, 100)];
    }
}

final class PlainStub
{
    public function check(int $x): void {}
}

final class MissingParameterGeneratorStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(int $x, int $y): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::int()];
    }
}

final class MissingGeneratorMethodStub
{
    #[Property(runs: 1, seed: 1, generators: 'doesNotExist')]
    public function check(int $x): void {}
}

final class MaxShrinksCapStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide', maxShrinks: 1)]
    public function check(int $a, int $b): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['a' => Gen::intBetween(10, 100), 'b' => Gen::intBetween(10, 100)];
    }
}

final class MaxShrinksDisabledStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide', maxShrinks: 0)]
    public function check(int $a): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['a' => Gen::intBetween(10, 100)];
    }
}

final class NoSeedFalsifyingStub
{
    #[Property(runs: 1, generators: 'provide')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(51, 100)];
    }
}

final class MixedArgumentsStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(string $s, bool $b, mixed $n, array $a, \DateTimeImmutable $d, int $i): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return [
            's' => Gen::constant('fixed'),
            'b' => Gen::constant(value: false),
            'n' => Gen::constant(null),
            'a' => Gen::constant([1, 2]),
            'd' => Gen::datetime(new \DateTimeImmutable('@5'), new \DateTimeImmutable('@5')),
            'i' => Gen::constant(7),
        ];
    }
}

final class StringableArgStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(mixed $s): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['s' => Gen::constant(new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'STRINGABLE';
            }
        })];
    }
}

final class MappedFalsifyingStub
{
    #[Property(runs: 50, seed: 1, generators: 'provide')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        // Doubled ints: with integrated shrinking the mapped value shrinks
        // through the source int's tree (pre-2.0, map() did not shrink at all).
        return ['x' => Gen::map(Gen::intBetween(0, 100), static fn(int $n): int => $n * 2)];
    }
}

final class FlatMapFalsifyingStub
{
    #[Property(runs: 50, seed: 1, generators: 'provide')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        // Dependent generator: the value's domain [0, n] depends on the source n.
        return ['x' => Gen::flatMap(
            Gen::intBetween(1, 10),
            static fn(int $n): ArbitraryInterface => Gen::intBetween(0, $n),
        )];
    }
}

final class DrawFalsifyingStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return [];
    }
}

final class ParamAndDrawFalsifyingStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(int $n): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['n' => Gen::intBetween(11, 100)];
    }
}

final class DrawCountStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(int $n): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['n' => Gen::intBetween(2, 5)];
    }
}

final class DrawMaxShrinksDisabledStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide', maxShrinks: 0)]
    public function check(): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return [];
    }
}

final class DrawExampleStub
{
    #[Property(runs: 2, seed: 1, generators: 'provide')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }

    /** @return list<list<int>> */
    public static function checkExamples(): array
    {
        return [[5]];
    }
}

final class ConventionExampleStub
{
    #[Property(runs: 3, seed: 1, generators: 'provide')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }

    /** @return list<list<int>> */
    public static function checkExamples(): array
    {
        return [[100], [200]];
    }
}

final class NamedExampleStub
{
    #[Property(runs: 3, seed: 1, generators: 'provide', examples: 'cases')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }

    /** @return list<list<int>> */
    public static function cases(): array
    {
        return [[5]];
    }
}

final class BadArityExampleStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }

    /** @return list<list<int>> */
    public static function checkExamples(): array
    {
        return [[1, 2]];
    }
}

final class MissingExampleMethodStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide', examples: 'nope')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}

final class NonArrayExampleStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }

    /** @return list<int> */
    public static function checkExamples(): array
    {
        return [1, 2];
    }
}

final class DeadlineStub
{
    #[Property(runs: 3, seed: 1, generators: 'provide', timeoutMs: 5)]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}

final class GenerousDeadlineStub
{
    #[Property(runs: 3, seed: 1, generators: 'provide', timeoutMs: 60_000)]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}

final class TightBudgetStub
{
    #[Property(runs: 1000, seed: 1, generators: 'provide', budgetMs: 20)]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}

final class GenerousBudgetStub
{
    #[Property(runs: 3, seed: 1, generators: 'provide', budgetMs: 600_000)]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }
}

final class DeadlineExampleStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide', timeoutMs: 5)]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::intBetween(1, 10)];
    }

    /** @return list<list<int>> */
    public static function checkExamples(): array
    {
        return [[7]];
    }
}

final class ExhaustedStub
{
    #[Property(runs: 1, seed: 1, generators: 'provide')]
    public function check(int $x): void {}

    /** @return array<string, ArbitraryInterface> */
    public static function provide(): array
    {
        return ['x' => Gen::filter(Gen::intBetween(1, 10), static fn(int $n): bool => $n > 10)];
    }
}
