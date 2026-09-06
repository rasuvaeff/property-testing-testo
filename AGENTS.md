# AGENTS.md — property-testing-testo

Guidance for AI agents working on this package. Read before changing code.

## What this is

The Testo adapter of the property-testing family — a thin layer over
`rasuvaeff/property-testing-core` and a **drop-in replacement for the frozen
`rasuvaeff/property-testing` 2.x**. It ships exactly four classes:

- `Rasuvaeff\PropertyTesting\Property` — the attribute, on the same FQCN 2.x
  used (this is what makes the migration a dependency swap with zero code
  changes);
- `Rasuvaeff\PropertyTesting\Testo\PropertyInterceptor` — self-registering
  Testo interceptor: resolves the reflection conventions
  (`<method>Generators`/`<method>Examples`) and the environment table below
  into a core `PropertyDefinition`/`PropertyConfig`/`Corpus`, runs the engine,
  and maps the structured `PropertyResult` onto one Testo `TestResult`
  (printing the distribution report / discard warning via `Messenger`);
- `Rasuvaeff\PropertyTesting\Testo\TestoTrialExecutor` — executes the property
  body through Testo's pipeline and merges every run's `TestResult` attributes;
- `Rasuvaeff\PropertyTesting\Testo\VerboseListener` — `PROPERTY_VERBOSE`
  output as an engine event listener.

Everything algorithmic — generators, shrinking, the runner, the corpus, the
state machine, events — lives in `rasuvaeff/property-testing-core`. Engine
invariants are documented in THAT package's AGENTS.md; do not fix engine
behaviour from here.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Preserve the drop-in contract.** Every public FQCN and message format
   from `rasuvaeff/property-testing` 2.8 must keep working — the E2E fixtures
   (`tests/Fixture`) and the golden-message/event-order characterization tests
   are the definition of that contract. A diff there is a behaviour change,
   not a refactor.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

`rasuvaeff/property-testing-core` resolves from Packagist — a plain
`composer install` is enough. Only when testing an **unreleased** core change
does the sibling checkout need a temporary path repository. Run it from the
monorepo root, with the whole root mounted so the sibling package is visible
inside the container:

```bash
docker run --rm -v "$PWD":/repo -w /repo/property-testing-testo composer:2 sh -c '
    composer config repositories.core "{\"type\":\"path\",\"url\":\"../property-testing-core\",\"options\":{\"versions\":{\"rasuvaeff/property-testing-core\":\"0.9.0\"}}}"
    composer update
    composer config --unset repositories.core
    rm composer.lock
'
```

Never commit that `repositories` key or a `composer.lock`.

Otherwise, as usual:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.
`composer.lock` is gitignored (library).

## Environment contract

The exact semantics of every supported variable. This resolution is THIS
adapter's job (`PropertyInterceptor` resolves the table into a
`PropertyConfig`/`Corpus`; the core `PropertyRunner` never reads the process
environment). The table must stay verbatim-equivalent to what
`rasuvaeff/property-testing` 2.8 did — it is part of the drop-in contract.

| Variable | Read when | Accepts | Effect | Invalid value |
|---|---|---|---|---|
| `PROPERTY_RUNS` | Always (`false`/`''` = unset) | `/^\d+\z/`, `>= 1` | Overrides every property's run count, including the attribute's | `InvalidArgumentException` |
| `PROPERTY_SEED` | Only when the attribute omits `seed` (attribute wins) | `/^-?\d+\z/` | Seeds every unseeded property; unset means `random_int(0, PHP_INT_MAX)` per property | `InvalidArgumentException` |
| `PROPERTY_VERBOSE` | Always | Any value except `''`, `'0'`, `'false'`, `'off'`, `'no'` (case-insensitive, trimmed) enables | Logs every run's arguments/draws and each accepted shrink step to stdout | n/a (falsy values disable) |
| `PROPERTY_DB` | Always (`false`/`''` = off, nothing written) | Directory path (created on demand) **or** `redis://host[:port][/db][?prefix=key-prefix]` (`rediss://` = TLS; core `CorpusFactory`) | Regression corpus via core's `CorpusFactory::fromDsn()` (`CorpusFromEnv` was removed in 0.7.0): a path builds a `FilesystemCorpus`, a DSN a `RedisCorpus` (ext-redis preferred, else predis). An attribute `seed` disables replay for that property | `InvalidArgumentException` — an unusable DSN, or no Redis client installed. Never a silent fall back to the filesystem |
| `PROPERTY_PHASES` | Always (`false`/`''` = unset) | Comma-separated phase names, case-insensitive: `examples`, `corpus`, `random`, `shrink` | Stages of every run, in run order — **overrides** the attribute | `InvalidArgumentException` naming the accepted values |
| `PROPERTY_DERANDOMIZE` | Always | Any value except `''`, `'0'`, `'false'`, `'off'`, `'no'` (case-insensitive, trimmed) enables | Derives every unset seed from the property id — **overrides** the attribute | n/a (falsy values disable) |
| `PROPERTY_PATH` | Only when the attribute omits `path` | A recorded `CounterExample::$path` | Replays that shrink descent instead of searching for it; needs the seed of the run that produced it | adapter rejects a path with no pinned seed — the engine cannot, because the adapter has already drawn a random one for it |
| `PROPERTY_EDGE_CASES` | Always (`false`/`''` = unset) | `mixin` or `none`, case-insensitive, trimmed | Numeric boundary bias for every run — **overrides** the attribute | `InvalidArgumentException` naming the accepted values |

The split is deliberate and worth stating: **the environment dials the suite,
the attribute pins the property.** `PROPERTY_RUNS`, `PROPERTY_PHASES` and
`PROPERTY_DERANDOMIZE` are CI knobs and win; `PROPERTY_SEED` and
`PROPERTY_PATH` replay one specific failure and yield to the attribute. The
same table, with the same messages, is the PHPUnit adapter's — parity is a
golden rule, and these three were added to both in the same wave.

**Why this suite has no regression corpus in CI.** The monorepo rule wires
`PROPERTY_DB` plus a cache restore/save pair into `build.yml` for every package
that consumes a `rasuvaeff/property-testing-*` adapter. This package is the
adapter, and its Unit suite holds no `#[Property]` method of its own: the
`#[Property]` fixtures under `tests/Fixture` are executed in nested
applications, and the tests that exercise the corpus point `PROPERTY_DB` at
their own temporary directory. A workflow-level `PROPERTY_DB` would record
nothing and would reach every other test — a falsification would come back as a
`RegressionViolationException` and the pinned event orders would grow corpus
events. The lifecycle hooks that call `Env::isolateProperty()` per test keep
the suite hermetic against a developer who exported any `PROPERTY_*`; do not add
the CI steps on top. Isolating only `PROPERTY_DB` is not enough — this suite
asserts on seeds, run counts and printed messages, and an exported
`PROPERTY_RUNS=7` alone reddened 76 tests with no hint of the cause.

**Diagnostics print in a fixed order: the discard warning first, then the
distribution.** Both adapters emit the same two lines for the same run, and a
log that merges the two streams must not show them in different orders. The
order is pinned here and in the PHPUnit adapter's `AGENTS.md`; changing it means
changing both.

**Both lines go to `Messenger::CHANNEL_STDERR`.** Testo's terminal renderer
writes that channel through as-is; every other channel streams only at
`Verbosity::Verbose`, rescued at normal verbosity solely when the whole run
holds one passing test. The distribution used to go to `CHANNEL_STDOUT` and so
vanished from any real suite — and specifically from the red run, which is when
`Classify::when()` / `label()` are read. Do not move it back for the sake of a
tidier stdout.

One asymmetry the code makes explicit: this adapter normally draws the seed
itself, so `PropertyConfig::$seed` is never null — except under
derandomization, where it must be, because deriving a seed from the property
id is something only the engine can do (only it knows the id).

`maxDiscards` has no env override: unset means `runs * 10`, saturating to
`PHP_INT_MAX` when `runs > PHP_INT_MAX / 10`.

Note the asymmetry the tests pin: an **attribute** `seed` disables corpus
replay (`PropertyDefinition::$replayRegressions = false`), the **env**
`PROPERTY_SEED` does not — this cannot be derived from `config->seed` alone.

## Invariants & gotchas

- **Aggregate results must carry per-run `TestResult` attributes.** Downstream
  interceptors attach per-run attributes to each `$next()` result — Testo
  codecov's `CoverageResult` among them (`InterceptorOptions::ORDER_COVERAGE`,
  well inside this interceptor's `ORDER_CLOSE_TO_TEST`; the innermost of all is
  the lifecycle interceptor at `PHP_INT_MAX`, which is why a hook runs inside
  `$next()` on every trial). `TestoTrialExecutor` merges every executed run's attributes
  (last write per key wins) and the interceptor puts that aggregate on the one
  `TestResult` it returns. Dropping that merge makes property tests vanish
  from per-test coverage, and Infection then never runs them against mutants.
  The merge covers shrink trials and passing examples too.
- **A skip from a lifecycle hook arrives as a throw, not as a status.** The
  lifecycle interceptor is innermost (`PHP_INT_MAX`), so it runs `#[BeforeTest]`
  inside `$next()` and lets a `SkipTest` travel outward; only a skip from the
  *body* reaches the terminal handler that turns it into `Status::Skipped`.
  `TestoTrialExecutor` therefore catches `SkipTest`/`CancelTest` itself and
  folds them into the same discard-and-remember path as the status. Folded into
  a failure instead, the engine falsified the property and shrank around the
  skip, re-running the hook on every trial — while `README.md` promises a skip
  from the body *or a hook* skips the run.
- **`VerboseListener` is exception-hardened by design.** User listeners abort
  the run on exception (engine policy); the built-in verbose output swallows
  its own errors so a trace bug cannot fail every `PROPERTY_VERBOSE` consumer.
  Its line formats are pinned by `GoldenMessagesTest`.
- **Characterizations are the contract.** `EventOrderTest` pins the exact
  event sequence per outcome; `GoldenMessagesTest` pins failure messages and
  verbose output; `PropertyRunnerE2ETest`/`StatefulE2ETest` drive real
  `#[Property]` fixtures through the actual Testo runner. An extra, missing or
  reordered event / changed message is an observable behaviour change — update
  those tests in the same commit, never loosen them.
- **`tests/Fixture` is excluded from the Unit suite** (`testo.php`
  `FinderConfig(exclude: ['tests/Fixture'])`): the fixtures fail on purpose
  and are executed in a nested application via
  `Testo\Testing\Helper\TestRunner::runTest()`. Fixtures must be PSR-4
  one-class-per-file — `runTest` autoloads the class before running the nested
  app, so a combined file fails to load.
- Generators/examples methods must be **public static** (public when the body
  needs `$this`): their only call site is reflection, so Rector's
  `RemoveUnusedPrivateMethodRector` deletes private ones. The rector config
  additionally skips the dead-code rules on `tests/`.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: the scripts are `#[Property]`
  test cases run through `vendor/bin/testo`, not plain PHP scripts. Keep them
  runnable and update `examples/README.md` when usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
