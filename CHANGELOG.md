# Changelog

## Unreleased

- `composer rector` is green again: the anonymous `CaseInstance` stub in the
  interceptor's suite is a `readonly` class, as `ReadOnlyAnonymousClassRector`
  asks. It had been red since the stub was introduced, which is `composer
  release-check` red — `composer build` does not run rector.
- Three characterizations resolve the engine's own spelling instead of pinning
  the one core 0.9 uses, so they stay exact while this package's constraint
  spans two core lines: the exception a bounded-attempt generator gives up with
  (`GenerationExhausted` in 0.9, `GenerationExhaustedException` from 0.10) and
  the counterexample field counting `Assume::that()` discards (`skips` in 0.9,
  `discards` from 0.10, where `skips` becomes the environmental count). The new
  `Tests\Support\CoreCompat` provokes the exception rather than naming it — a
  literal for either spelling is a reference to a class the other engine does
  not have — and reads the field through `CounterExample::toArray()`. It is
  temporary by construction and goes away when the constraint narrows again.

## 0.9.0 — 2026-09-05

- The `Classify` distribution line is written to `Messenger::CHANNEL_STDERR`
  instead of `CHANNEL_STDOUT`, so it survives a run that contains a failure.
  Testo's terminal renderer writes the stderr channel through as-is and streams
  every other channel only at `-v`, rescuing a stdout message at normal
  verbosity solely when the whole run holds one passing test — so in a real
  suite the distribution was never shown, and specifically not on the red run,
  which is when `Classify::when()` and `Classify::label()` are read at all. The
  level stays `Info`; only the channel decides visibility. The PHPUnit adapter
  already printed both lines unconditionally.
- Requires `rasuvaeff/property-testing-core` `^0.9`, where an environmental skip
  no longer spends the discard budget: a machine missing a dependency is no
  longer told to narrow generators that were never at fault. The excessive-
  discard warning printed here reads the engine's discard counter, so it stops
  firing on such a machine without a change of its own.

## 0.8.0 — 2026-09-04

- A skipped run — from the body or from a lifecycle hook — is reported to the
  engine as `TrialOutcome::skipped()` rather than as a plain discard, so a
  recorded regression whose replay only skipped is kept instead of pruned. A
  machine without the dependency the body guards against used to delete the
  counterexample for every machine that has it. Requires
  `rasuvaeff/property-testing-core` ^0.8.
- A `SkipTest` or `CancelTest` raised from a **lifecycle hook** now skips the
  property, the way `README.md` has always described it. The lifecycle
  interceptor is innermost, so a hook's throw never reached the handler that
  turns a skip from the body into a status: the engine took it as a
  falsification and shrank around the skip, re-running the hook on every trial.
- `PROPERTY_PATH` without a pinned seed is refused instead of doing nothing.
  The adapter draws a random seed for an unseeded property, so the engine's own
  "a path needs a seed" check never fired and the path silently described a
  descent of a run that never happened.
- An attribute argument PHP rejects with a `TypeError` or `ValueError` —
  `generators: [Provider::class, 'missingMethod']` is not a callable — is
  reported as this test's error with the reason in the message, instead of
  escaping the pipeline and coming back as `Status::Aborted` with the reason
  buried in `previous`. A generators or examples provider that is not static
  and has no test-case instance to run on says so by name.

## 0.7.3 — 2026-09-04

- Allows `rasuvaeff/property-testing-core` `^0.7`.
- Tests are isolated from a `PROPERTY_DB` exported for the whole run: the suite
  no longer replays or records an ambient corpus behind the assertions.

## 0.7.2 — 2026-09-03

- Repair the interceptor benchmark fixture so it runs through a configured
  nested Testo pipeline and compares the current implementation with a
  baseline.

## 0.7.1 — 2026-09-03

- Allows `rasuvaeff/property-testing-core` `^0.6` beside `^0.5`: the 0.6 line changes nothing the adapter calls (the corpus and environment parsing it delegates keep their API), and its `SEQUENCE_EPOCH` bump only fences off seed entries recorded under 0.5.

## 0.7.0 — 2026-09-02

- Requires `rasuvaeff/property-testing-core` `^0.5`. The corpus resolution
  and the `PROPERTY_*` parsing now come from the engine (`CorpusFactory`,
  `EnvironmentOverrides`); the adapter's own `CorpusFromEnv`, `RedisDsn` and
  `LazyPhpRedisCorpusClient` (all `@internal`) are gone. `PROPERTY_RUNS` /
  `PROPERTY_SEED` past the integer range are refused instead of saturating.
- The `PROPERTY_DB` Redis DSN has the IANA shape:
  `redis://host[:port][/db][?prefix=key-prefix]`, `rediss://` for TLS. The
  path is the database index; the pre-0.7 form with the key prefix in the
  path (`redis://host/suite-a:`) is refused with the new spelling in the
  message.
- A property whose every run skipped (`SkipTest` from the body or a hook) is
  reported as a skipped test carrying the first skip, instead of giving up
  after `maxDiscards` with the advice to narrow the generators. Partly
  skipped runs stay discards.
- A misconfigured property — a missing generators method, an unresolvable
  provider, a bad `PROPERTY_RUNS`, an unknown phase or edge-case mode — is
  reported as `Status::Error` with the exception as its failure, instead of
  being thrown through the pipeline and surfacing as an aborted run with the
  message buried in `previous`.
- `#[Property]` combined with a data provider, and `#[Property]` on a
  function-based case, are refused as errors instead of silently overwriting
  the provider's arguments or running the function once without any.
- A throw from a lifecycle hook or a downstream interceptor is the run's
  failure (shrunk like any other) instead of aborting the property without a
  counterexample; a run that ends `Aborted` or `Risky` is a failure, no
  longer a pass.
- README: what the adapter combines with — hooks run per generated input,
  `#[ExpectException]` does not see the body, `PROPERTY_PATH` is for one test.

## 0.6.2 — 2026-08-20

- Coverage is now merged across runs instead of kept from the last one. Each
  trial opens its own coverage window, so last-write-wins dropped every line a
  random input executed only sometimes, and made per-property coverage depend
  on the seed. The aggregate now merges each run's `CoverageResult`, and sums
  `duration` so the reported time is the whole property's rather than the last
  trial's.
- A per-run `Skipped`/`Cancelled` status is now a discard, not a silent pass. A
  property whose body threw `SkipTest` on every run reported green having
  asserted nothing; it now gives up like an all-discarded property.
- `PROPERTY_DB` with credentials in its userinfo (`redis://user:pass@host`) is
  rejected instead of silently dropped — `parse_url` would discard them and the
  connection would go without AUTH. The error never echoes the DSN.
- The resolved corpus is memoized per `PROPERTY_DB` value, so a suite sharing a
  Redis corpus builds one client (and opens one connection) rather than one per
  property.

## 0.6.1 — 2026-08-20

- `PROPERTY_DB` with a non-`redis` URI scheme is now a configuration error
  instead of a directory named after the scheme. Only an exact `redis://`
  prefix was recognised, so a `rediss://` typo — or any other scheme — fell
  through to `FilesystemCorpus` and silently wrote the corpus to a directory
  nobody reads, exactly the "silent fall back to the filesystem" the design
  forbids. Scheme matching is now case-insensitive (`Redis://` is a shared
  corpus) and the error names the scheme but not the DSN, which may carry
  credentials. A path with no scheme is unchanged.

## 0.6.0 — 2026-08-16

- Added `#[Property(auto: true)]`: a generator is derived from the property's
  own signature for every parameter the provider does not cover, via core
  0.4's `Gen::forParameters()` (`@param` psalm type over native type; a type
  it cannot read throws naming the method and the parameter). The provider —
  explicit or the `<testMethod>Generators` convention — becomes the overrides
  and may be partial; a full provider stays legal; with `auto` a provider key
  that is not a parameter of the property is an error. Strictly opt-in — it
  will never become the default — and deliberately without a `PROPERTY_AUTO`
  environment variable. `auto: false` behavior is unchanged.
- Requires `rasuvaeff/property-testing-core` `^0.4`.

## 0.5.0 — 2026-08-15

- `#[Property]` `generators` and `examples` now accept reusable callable
  providers: array callables, `Class::method` strings, invokable objects, and
  (on PHP 8.5) inline closures and first-class callables. Local provider
  methods keep precedence over global function names, and the existing
  convention remains the default.
- The public provider properties now expose `Closure|string|null` so callable
  providers can be retained without executing them while the attribute is
  constructed.

## 0.4.0 — 2026-08-15

- `PROPERTY_DB` now also takes a `redis://host[:port][/key-prefix]` DSN, which
  builds core 0.3's `RedisCorpus`. Until now that class existed and no suite
  could reach it: the engine reads no environment by design, and this adapter
  hardcoded the filesystem corpus — so a corpus shared between CI and
  developers was available only to harnesses that construct the runner
  themselves. A directory keeps meaning exactly what it meant. `ext-redis` is
  preferred when loaded, `predis/predis` otherwise, and neither installed is an
  error rather than a silent fall back to the filesystem.

## 0.3.0 — 2026-08-15

- Added `edgeCases` to `#[Property]` and `PROPERTY_EDGE_CASES` (`mixin` or
  `none`) to the environment, reaching core 0.3's switch for the numeric
  boundary bias. Turning it off stops a property that cannot use `0`, `±1` or a
  range's ends from spending one run in five on a value it discards. The
  variable overrides the attribute, like every other CI-facing knob, and an
  unknown value throws rather than silently keeping the bias it was told to
  drop.
- The falsification message ends with the shrink path, and this package's
  golden pins that message whole again — the transitional tolerance that let
  core ship the line is gone. Both READMEs say how to replay a descent with
  the path beside the seed.
- **Requires `rasuvaeff/property-testing-core` `^0.3`.**

## 0.2.0 — 2026-08-14

- Added the 0.2 run knobs to `#[Property]` and to the environment: `shrink`
  and `shrinkBudgetMs` (report a counterexample as generated, or bound the
  descent), `phases` (`PROPERTY_PHASES`), `derandomize`
  (`PROPERTY_DERANDOMIZE`) and `path` (`PROPERTY_PATH`, replaying a recorded
  shrink descent instead of searching for it again). Precedence follows one
  rule, now stated in the README: the environment dials the suite and wins for
  `PROPERTY_RUNS`/`PROPERTY_PHASES`/`PROPERTY_DERANDOMIZE`, while the attribute
  pins the property and wins for `seed`/`path`. A `path` without a `seed` is
  rejected by the attribute itself, so the error names the attribute rather
  than the config built from it.
- **Requires `rasuvaeff/property-testing-core` `^0.2`.** The knobs above are
  0.2 engine fields; there is no version of this adapter that offers them
  against core 0.1.

## 0.1.0 — 2026-08-09

- Initial release: the Testo adapter extracted from `rasuvaeff/property-testing`
  2.8.1 as a drop-in replacement — the `#[Property]` attribute keeps its FQCN
  (`Rasuvaeff\PropertyTesting\Property`), the interceptor, trial executor and
  verbose listener move to the `Rasuvaeff\PropertyTesting\Testo` namespace.
- The engine (generators, shrinking, runner, regression corpus, events, state
  machine) now comes from `rasuvaeff/property-testing-core`.
- Behaviour preserved from 2.8: reflection conventions
  (`<method>Generators`/`<method>Examples`), the
  `PROPERTY_RUNS`/`PROPERTY_SEED`/`PROPERTY_VERBOSE`/`PROPERTY_DB` environment
  contract, counterexample message formats, corpus format and replay
  semantics, seed determinism, and Testo coverage-attribute aggregation.
