# rasuvaeff/property-testing-testo

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/property-testing-testo/v)](https://packagist.org/packages/rasuvaeff/property-testing-testo)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/property-testing-testo/downloads)](https://packagist.org/packages/rasuvaeff/property-testing-testo)
[![Build](https://github.com/rasuvaeff/property-testing-testo/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-testo/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/property-testing-testo/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-testo/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/property-testing-testo/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/property-testing-testo/php)](https://packagist.org/packages/rasuvaeff/property-testing-testo)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

[Русская версия](README.ru.md)

[Testo](https://github.com/php-testo/testo) adapter for the
[property-testing engine](https://github.com/rasuvaeff/property-testing-core):
the `#[Property]` attribute, reflection conventions, and environment overrides —
a **drop-in replacement for the frozen `rasuvaeff/property-testing` 2.x**.
Generate hundreds of random inputs per test, find the failing one, and shrink it
to a minimal counterexample you can actually read.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Part of the property-testing family

| Package | Use it when |
|---|---|
| [`rasuvaeff/property-testing-core`](https://github.com/rasuvaeff/property-testing-core) | You drive the engine yourself: a custom harness, CI guard, CLI checker, or another framework adapter |
| **`rasuvaeff/property-testing-testo`** (this package) | You test with [Testo](https://github.com/php-testo/testo) — the classic `#[Property]` attribute |
| [`rasuvaeff/property-testing-phpunit`](https://github.com/rasuvaeff/property-testing-phpunit) | You test with PHPUnit — a `PropertyTesting` trait with a fluent `forAll()->check()` API |

## Migrating from rasuvaeff/property-testing 2.x

The frozen `rasuvaeff/property-testing` package is superseded by this adapter.
Migration is **one Composer command** — your PHP code does not change:

```bash
composer remove --dev rasuvaeff/property-testing
composer require --dev rasuvaeff/property-testing-testo
```

Everything is preserved:

- the FQCN of every public class — `Rasuvaeff\PropertyTesting\Property`,
  `Gen`, `ArbitraryInterface`, `Assume`, `Classify`, the exceptions, the state
  machine: **no import changes**;
- the `<method>Generators()` / `<method>Examples()` conventions;
- the `PROPERTY_RUNS` / `PROPERTY_SEED` / `PROPERTY_VERBOSE` / `PROPERTY_DB`
  environment variables;
- the counterexample message format;
- a regression corpus written by 2.8 (`PROPERTY_DB`) is read as-is;
- seed determinism: a seed recorded under 2.8 reproduces the same inputs.

The engine now lives in `rasuvaeff/property-testing-core` (pulled in
automatically), which `conflict`s with the old package — Composer will refuse a
mixed installation rather than let two copies of the namespace collide.

## Requirements

- PHP 8.3+
- [`rasuvaeff/property-testing-core`](https://packagist.org/packages/rasuvaeff/property-testing-core) `^0.8 || ^0.9`
- [`testo/testo`](https://packagist.org/packages/testo/testo) `^0.10.39 || ^1.0`

## Installation

```bash
composer require --dev rasuvaeff/property-testing-testo
```

No plugin registration is needed: the `#[Property]` attribute self-registers
with Testo through the framework's interceptor discovery.

## Usage

Mark a test method with `#[Property]` and point it at a generators method that
maps each parameter name to a `Gen` factory. The runner generates random
arguments, runs the property `runs` times, and on the first failure shrinks the
counterexample to a minimal one.

```php
use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Test;

#[Test]
final class RetryPolicyPropertyTest
{
    #[Property(runs: 500)]
    public function delayNeverExceedsCap(int $baseSeconds, int $cap, int $attempts): void
    {
        Assume::that($cap >= $baseSeconds);

        $policy = RetryPolicy::exponential($baseSeconds, $cap);

        Assert::true($policy->nextDelaySeconds($attempts) <= $cap);
    }

    /** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
    public static function delayNeverExceedsCapGenerators(): array
    {
        return [
            'baseSeconds' => Gen::intBetween(1, 300),
            'cap' => Gen::intBetween(1, 86_400),
            'attempts' => Gen::intBetween(1, 100),
        ];
    }
}
```

On failure, the counterexample is rendered into the test output:

```
Property falsified after 246 successful run(s); seed=7382910
  Original: baseSeconds=91, cap=847, attempts=23
  Shrunk:   baseSeconds=848, cap=847, attempts=1 (12 shrink step(s), 41 trial(s))
  Changed:  baseSeconds=91 -> 848, attempts=23 -> 1
```

Reproduce the exact run by passing the reported seed back to the attribute:
`#[Property(runs: 500, seed: 7382910)]`. The message also ends with a `Path:`
line — the shrink steps that were accepted — and passing that back beside the
seed (`#[Property(seed: 7382910, path: 'attempts:1/attempts:3')]`, or
`PROPERTY_SEED=… PROPERTY_PATH=…`) follows the descent instead of searching for
it again. The excerpt above is trimmed; a real run prints `Failure:` and
`Path:` too.

### Conventions

PHP attribute arguments must be constant expressions, so generators cannot be
passed inline. Name a method returning `array<string, ArbitraryInterface>`
keyed by parameter name; when the `generators` argument is omitted the adapter
falls back to `<testMethod>Generators`. The same pattern applies to fixed
examples: `<testMethod>Examples` (or `#[Property(examples: 'method')]`) returns
positional argument tuples that run **before** the random inputs and are never
shrunk.

Declare generators and examples methods **`public static`** (`public` if the
body needs `$this`): their only call site is this adapter's reflection, so
Rector's dead-code set would delete private ones.

What the adapter does and does not combine with:

- **Lifecycle hooks run on every property run.** The interceptor sits inside
  Testo's lifecycle interceptor, so `#[BeforeTest]`/`#[AfterTest]` execute
  once per generated input, not once per test (PHPUnit's `setUp` runs once).
  A hook that throws is that run's failure and is shrunk like any other.
- **A data provider cannot be combined with `#[Property]`** — the generators
  supply the arguments — and `#[Property]` on a function-based case is
  refused: both are reported as an error of the test with a message, as is
  any other misconfiguration (a missing generators method, a bad
  `PROPERTY_RUNS`, an unknown phase).
- **`#[ExpectException]` does not see the body's exception**: the expectation
  interceptor runs outside this one and observes the property's aggregate
  failure. Assert on exceptions inside the body instead.
- **A `SkipTest` thrown from the body or a hook skips the run**; when every
  run skipped, the property is reported as a skipped test. Partly skipped runs
  are discards and count against `maxDiscards`. Unlike an `Assume::that()`
  discard, a skip says nothing about the input, so a recorded regression whose
  replay only skipped stays in the corpus instead of being pruned.

### Callable providers

`generators` and `examples` also accept a callable. Strings are resolved as a
method on the property test class first; only when no such method exists are
they treated as external callable names. Non-string callables are stored and
invoked when the property is resolved, so an invokable provider can be written
directly in a PHP 8.3 attribute:

```php
final readonly class DelayGenerators
{
    /** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
    public function __invoke(): array
    {
        return [
            'base' => Gen::intBetween(1, 300),
            'cap' => Gen::intBetween(1, 86_400),
        ];
    }
}

#[Property(generators: new DelayGenerators())]
public function delayNeverExceedsCap(int $base, int $cap): void
{
}
```

Reusable static providers can use either
`[Provider::class, 'method']` or `'Provider::method'`. PHP 8.5 additionally
allows an inline `static function (): array { ... }` or a first-class callable
such as `Provider::method(...)` in the attribute. A method on the test class
named like a global function (for example `range`) still wins over that global
function.

The flip side of string resolution: a misspelled method name that happens to
match a global function (`'count'`, `'range'`) is invoked as that function —
typically failing with its own `ArgumentCountError` from the zero-argument
call, or, when the function takes no arguments, with the result validation
rejecting its return. A typo matching nothing fails immediately with
`neither a method on … nor a callable`.

### Auto-derived generators (`auto: true`)

When the parameters are fully described by their types, the provider method can
go away entirely: `auto: true` derives a generator for every parameter from the
property's own signature via
[`Gen::forParameters()`](https://github.com/rasuvaeff/property-testing-core) —
the `@param` psalm type when there is one (`int<1, 300>`, `non-empty-string`,
`list<T>`, `'a'|'b'`), the native type otherwise:

```php
/**
 * @param int<1, 300> $base
 * @param int<1, 86400> $cap
 */
#[Property(auto: true)]
public function delayNeverExceedsCap(int $base, int $cap): void
{
}
```

The provider — explicit or the `<testMethod>Generators` convention — becomes
the **overrides** and may be partial: the parameters it names are taken as
given, the rest are derived. That is the escape hatch for domains no psalm type
can express (a float range, a dependent pair built with `Gen::flatMap()`):

```php
/** @param int<1, 40> $attempt */
#[Property(generators: 'provide', auto: true)]
public function delayIsMonotonic(float $multiplier, int $attempt): void { /* … */ }

/** @return array<string, ArbitraryInterface> */
public static function provide(): array
{
    return ['multiplier' => Gen::floatBetween(1.0, 4.0)];   // the rest is derived
}
```

Rules worth knowing:

- **Strictly opt-in.** `auto` defaults to `false` and will never become the
  default: a bare `int` or `float` derives its full native domain, and only
  the property's author knows whether that is the intended one. Annotate or
  override anything narrower.
- A type the deriver cannot read (a bare `array`, `mixed`, an untyped or
  variadic parameter) fails with an error naming the method and the parameter
  — never a silently widened guess.
- With `auto: true` a provider key that is not a parameter of the property is
  an error: merge semantics would otherwise silently replace a typoed entry
  with a signature-derived generator.
- A full provider plus `auto: true` is legal — auto derives nothing; that is
  the transitional state while a test migrates.
- There is deliberately no `PROPERTY_AUTO` environment variable: the
  environment dials the suite (runs, phases), while `auto` changes what one
  property's arguments mean — attribute territory.

### Attribute parameters

| Parameter | Meaning |
|---|---|
| `runs` | Successful checks to complete (default 100). Discarded runs do not count |
| `seed` | Pins the random phase for reproduction. Also disables corpus replay for this property — the pinned run wins |
| `generators` | Method name or `callable(): array<string, ArbitraryInterface>`; default `<testMethod>Generators` |
| `examples` | Method name or `callable(): iterable<array<mixed>>`; default `<testMethod>Examples` |
| `maxShrinks` | Cap on accepted shrink steps; `0` disables shrinking |
| `maxDiscards` | Discard budget before the property fails with `GaveUpException`; default `runs * 10` |
| `timeoutMs` | Wall-clock deadline for a single run — exceeding it fails the property with `DeadlineExceededException` |
| `budgetMs` | Wall-clock budget for the whole random phase — running out fails with `TimeBudgetExceededException` |
| `shrink` | `ShrinkMode::Full` (default), `Off` (report the input as generated) or `Bounded` with a budget |
| `shrinkBudgetMs` | Wall-clock budget for the descent — the one knob that costs determinism, since how far it gets depends on how long the body takes |
| `phases` | Stages to perform (`Phase::Examples`, `Corpus`, `Random`, `Shrink`); a subset trades coverage for time on purpose |
| `derandomize` | Derives an unset seed from the property id instead of drawing one; an attribute `seed` still wins |
| `path` | Replays a recorded shrink descent (`CounterExample::$path`) instead of searching for it; requires `seed` |
| `edgeCases` | `EdgeCases::None` turns off the numeric boundary bias — for a property the edges only cost runs |
| `auto` | Derives generators from the property's signature for every parameter the provider does not cover; the provider becomes partial overrides. Off by default, and stays off |

### Environment overrides

One rule decides who wins: **the environment dials the suite, the attribute
pins the property.** `PROPERTY_RUNS`, `PROPERTY_PHASES` and
`PROPERTY_DERANDOMIZE` are CI knobs and override the attribute;
`PROPERTY_SEED` and `PROPERTY_PATH` replay one specific failure and yield to
what the attribute wrote down.

| Variable | Effect |
|---|---|
| `PROPERTY_RUNS` | Positive integer that overrides every property's run count (dial runs up in CI) |
| `PROPERTY_SEED` | Integer seed for any property whose attribute omits `seed` (replay a whole suite). An explicit attribute `seed` still wins |
| `PROPERTY_VERBOSE` | Any value except `''`/`'0'` logs every run's generated arguments and each accepted shrink step |
| `PROPERTY_DB` | Directory path enabling the regression corpus, or a `redis://host[:port][/db][?prefix=key-prefix]` DSN (`rediss://` for TLS) for a corpus shared between CI and developers. Unset means off, nothing is written |
| `PROPERTY_PHASES` | Comma-separated stage list (`examples,corpus,random,shrink`, case-insensitive) that overrides the attribute — an unknown name throws rather than skipping a stage. `examples,corpus` is the fast pull-request gate |
| `PROPERTY_DERANDOMIZE` | Any value except `''`/`'0'` derives every unset seed from the property id, making a whole suite reproducible without editing it |
| `PROPERTY_PATH` | A recorded shrink descent replayed instead of searched for. **Requires a pinned seed** — `PROPERTY_SEED` or the attribute's — and is refused without one, because an unseeded property gets a random seed and the path would replay a run that never happened. An attribute `path` wins. It describes one failure, so run it with a filter on that one test — every other property would report the path as stale |
| `PROPERTY_EDGE_CASES` | `mixin` or `none` (case-insensitive) — the numeric boundary bias for the whole suite, overriding the attribute. An unknown value throws |

### Regression corpus

`PROPERTY_DB` takes either a directory or a Redis DSN:

```bash
PROPERTY_DB=/tmp/corpus                           vendor/bin/testo   # one machine
PROPERTY_DB=redis://127.0.0.1:6379                vendor/bin/testo   # shared
PROPERTY_DB=redis://redis:6379/2?prefix=suite-a:  vendor/bin/testo   # shared server, database 2, own prefix
PROPERTY_DB=rediss://redis.example.com            vendor/bin/testo   # TLS
```

The DSN has the shape everything else gives it (the IANA registration, predis,
Symfony): the path is the database index, the key prefix is the `prefix`
query parameter, `rediss://` is TLS. The pre-0.7 form with the prefix in the
path (`redis://host/suite-a:`) is refused with the new spelling in the
message. The value is parsed by the engine's `CorpusFactory`, shared with the
PHPUnit adapter.

A directory remembers a counterexample for whoever owns it — in CI, a machine
deleted when the job ends. The Redis form is the same corpus, in the same
document, shared: a failure found on a laptop replays in CI and one found in CI
replays on the next laptop. It needs `ext-redis` or `predis/predis`; neither
installed is an error rather than a silent fall back to the filesystem, because
a suite told to share its corpus and quietly writing where nobody reads is
worse than one that stops. A `PROPERTY_DB` with any other scheme — a `Rediss://`
typo, another backend — is likewise an error, never a directory named after the
scheme. Credentials in the DSN (`redis://user:pass@host`) are rejected rather
than silently dropped; configure Redis AUTH out of band.

#### How entries are recorded

Set `PROPERTY_DB` to a directory and every falsified property records its
failure there. On the next run the recorded failures are replayed **first**
(unless the attribute pins its own `seed`): one that still fails is reported
immediately — as a `RegressionViolationException` for a stored-values entry —
and one that no longer fails is pruned. The storage format is exactly the one
`rasuvaeff/property-testing` 2.8 wrote, so existing CI corpora keep working
after the migration. Storage details live in the
[core documentation](https://github.com/rasuvaeff/property-testing-core#regression-corpus).

### Coverage attributes

The adapter aggregates the per-run `TestResult` attributes of every executed
body — Testo codecov's `CoverageResult` among them — onto the single
`TestResult` a property test reports. Property tests therefore appear in
per-test coverage, and Infection runs them against mutants like any other test.

### Stateful / model-based testing

The engine's state machine works unchanged under `#[Property]`:

```php
#[Property(runs: 200)]
public function stackBehavesLikeItsModel(CommandSequence $sequence): void
{
    StateMachine::check($sequence, static fn(): Stack => new Stack());
}

/** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
public static function stackBehavesLikeItsModelGenerators(): array
{
    return ['sequence' => Gen::commands([], [
        Gen::map(Gen::intBetween(0, 99), static fn(int $v): Command => new Push($v)),
        Gen::constant(new Pop()),
    ])];
}
```

See [`examples/state_machine.php`](examples/state_machine.php) for the full
runnable stack example.

### Generators

The full generator catalog (`Gen::int()` … `Gen::subset()`, `Gen::regex()`,
`Gen::commands()`, `Gen::draw()`, writing your own `ArbitraryInterface`) is the
engine's API and is documented in the
[core README](https://github.com/rasuvaeff/property-testing-core#generators).
Everything there is usable from a `#[Property]` test as-is.

## Public API of this package

| Type | Role |
|---|---|
| `Rasuvaeff\PropertyTesting\Property` | The attribute — the same FQCN 2.x shipped |
| `Rasuvaeff\PropertyTesting\Testo\PropertyInterceptor` | Testo interceptor: resolves reflection conventions and environment into a core `PropertyDefinition`, maps the structured result to one `TestResult` |

`TestoTrialExecutor` and `VerboseListener` are `@internal`: the interceptor's
implementation, not a contract. The environment variables and the `PROPERTY_DB`
DSN are parsed by the engine (`EnvironmentOverrides`, `CorpusFactory`), so
they mean the same thing under the PHPUnit adapter.

## Security

Generated values are pseudo-random (seeded MT19937), not cryptographic. Seeds
are not secrets — they are printed in failure output by design. Treat
`PROPERTY_DB` corpus files as test artifacts: they contain generated inputs
verbatim, so do not point the variable at a directory that gets published.

## Examples

See [examples/](examples/) — `#[Property]` test cases run through
`vendor/bin/testo`.

## Development

```bash
make install     # composer install (Docker)
make build       # validate + normalize + require-checker + cs + psalm + tests
make cs-fix      # apply code style
make mutation    # infection mutation testing
```

## License

[BSD-3-Clause](LICENSE.md)
