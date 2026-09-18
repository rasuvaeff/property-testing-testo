# Examples

Runnable scripts demonstrating `rasuvaeff/property-testing-testo`.

| Script | Shows | Needs server? |
|---|---|---|
| `property_test.php` | Canonical `#[Property]` usage as a real Testo test case, including an in-body dependent draw with `Gen::draw()`, signature-derived generators with `auto: true` and a per-run expected exception with `throws:` | No |
| `state_machine.php` | Stateful / model-based testing: a `Command` interface, `Gen::commands()`, and `StateMachine::check()` driving command sequences against a stack (marked `#[ExpectNoAssertions]` — the check throws rather than asserting) | No |

## Running

The examples are `#[Property]` test classes in a Testo suite of their own,
`Examples` (see `testo.php`). Run them from the package root after
`composer install`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/testo --suite=Examples
```

Each file is also a plain PHP script — it loads the Composer autoloader and,
executed directly, prints what it defined:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/property_test.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/state_machine.php
```
