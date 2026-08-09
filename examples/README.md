# Examples

Runnable scripts demonstrating `rasuvaeff/property-testing-testo`.

| Script | Shows | Needs server? |
|---|---|---|
| `property_test.php` | Canonical `#[Property]` usage as a real Testo test case, including an in-body dependent draw with `Gen::draw()` | No |
| `state_machine.php` | Stateful / model-based testing: a `Command` interface, `Gen::commands()`, and `StateMachine::check()` driving command sequences against a stack | No |

## Running

The examples are plain PHP scripts that load the package via Composer autoload
and define `#[Property]` test classes. Run them from the package root after
`composer install`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/property_test.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/state_machine.php
```

Each script prints what it defined; the properties themselves execute through
the Testo runner (`vendor/bin/testo`) like any other test.
