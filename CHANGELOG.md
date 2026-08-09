# Changelog

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
