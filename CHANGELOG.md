# Changelog

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
