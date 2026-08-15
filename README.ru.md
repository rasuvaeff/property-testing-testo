# rasuvaeff/property-testing-testo

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/property-testing-testo/v)](https://packagist.org/packages/rasuvaeff/property-testing-testo)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/property-testing-testo/downloads)](https://packagist.org/packages/rasuvaeff/property-testing-testo)
[![Build](https://github.com/rasuvaeff/property-testing-testo/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-testo/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/property-testing-testo/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-testo/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/property-testing-testo/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/property-testing-testo/php)](https://packagist.org/packages/rasuvaeff/property-testing-testo)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

[English version](README.md)

Адаптер [property-testing engine](https://github.com/rasuvaeff/property-testing-core)
для [Testo](https://github.com/php-testo/testo): атрибут `#[Property]`,
reflection-конвенции и env-переменные — **drop-in замена замороженного
`rasuvaeff/property-testing` 2.x**. Сотни случайных входов на тест, поиск
падающего и shrink до минимального читаемого контрпримера.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный API-справочник для модели.

## Семейство property-testing

| Пакет | Когда использовать |
|---|---|
| [`rasuvaeff/property-testing-core`](https://github.com/rasuvaeff/property-testing-core) | Вы управляете движком сами: собственный harness, CI-guard, CLI-проверка или адаптер другого фреймворка |
| **`rasuvaeff/property-testing-testo`** (этот пакет) | Вы тестируете через [Testo](https://github.com/php-testo/testo) — классический атрибут `#[Property]` |
| [`rasuvaeff/property-testing-phpunit`](https://github.com/rasuvaeff/property-testing-phpunit) | Вы тестируете через PHPUnit — trait `PropertyTesting` с fluent-API `forAll()->check()` |

## Миграция с rasuvaeff/property-testing 2.x

Замороженный пакет `rasuvaeff/property-testing` заменяется этим адаптером.
Миграция — **одна команда Composer**, PHP-код не меняется:

```bash
composer remove --dev rasuvaeff/property-testing
composer require --dev rasuvaeff/property-testing-testo
```

Сохраняется всё:

- FQCN каждого публичного класса — `Rasuvaeff\PropertyTesting\Property`,
  `Gen`, `ArbitraryInterface`, `Assume`, `Classify`, исключения, state
  machine: **импорты не меняются**;
- конвенции `<method>Generators()` / `<method>Examples()`;
- переменные окружения `PROPERTY_RUNS` / `PROPERTY_SEED` / `PROPERTY_VERBOSE`
  / `PROPERTY_DB`;
- формат сообщения о контрпримере;
- корпус регрессий, записанный 2.8 (`PROPERTY_DB`), читается как есть;
- детерминизм seed: seed, записанный под 2.8, воспроизводит те же входы.

Движок теперь живёт в `rasuvaeff/property-testing-core` (ставится
автоматически) и объявляет `conflict` со старым пакетом — Composer откажется
от смешанной установки, а не позволит двум копиям namespace столкнуться.

## Требования

- PHP 8.3+
- [`rasuvaeff/property-testing-core`](https://packagist.org/packages/rasuvaeff/property-testing-core) `^0.1`
- [`testo/testo`](https://packagist.org/packages/testo/testo) `^0.10.39 || ^1.0`

## Установка

```bash
composer require --dev rasuvaeff/property-testing-testo
```

Регистрация плагина не нужна: атрибут `#[Property]` саморегистрируется через
механизм обнаружения интерцепторов Testo.

## Использование

Пометьте тестовый метод атрибутом `#[Property]` и укажите метод генераторов,
который сопоставляет каждому имени параметра фабрику `Gen`. Раннер генерирует
случайные аргументы, выполняет свойство `runs` раз и при первом падении
shrink-ает контрпример до минимального.

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

При падении контрпример выводится в отчёт теста:

```
Property falsified after 246 successful run(s); seed=7382910
  Original: baseSeconds=91, cap=847, attempts=23
  Shrunk:   baseSeconds=848, cap=847, attempts=1 (12 shrink step(s), 41 trial(s))
  Changed:  baseSeconds=91 -> 848, attempts=23 -> 1
```

Точный прогон воспроизводится передачей seed обратно в атрибут:
`#[Property(runs: 500, seed: 7382910)]`. Последняя строка сообщения — `Path:`,
принятые шаги shrink; если передать и его рядом с seed
(`#[Property(seed: 7382910, path: 'attempts:1/attempts:3')]` или
`PROPERTY_SEED=… PROPERTY_PATH=…`), прогон пройдёт по спуску, а не будет искать
его заново. Выдержка выше сокращена: реальный прогон печатает ещё `Failure:` и
`Path:`.

### Конвенции

Аргументы PHP-атрибутов — константные выражения, поэтому генераторы нельзя
передать инлайн. Именуйте метод, возвращающий
`array<string, ArbitraryInterface>` по именам параметров; когда аргумент
`generators` опущен, адаптер ищет `<testMethod>Generators`. Тот же паттерн для
фиксированных примеров: `<testMethod>Examples` (или
`#[Property(examples: 'method')]`) возвращает позиционные кортежи аргументов,
которые выполняются **до** случайных входов и никогда не shrink-аются.

Методы генераторов и примеров объявляйте **`public static`** (`public`, если
телу нужен `$this`): их единственный вызов — рефлексия этого адаптера, поэтому
dead-code-набор Rector удалил бы приватные.

### Параметры атрибута

| Параметр | Значение |
|---|---|
| `runs` | Число успешных проверок (по умолчанию 100). Discarded-прогоны не считаются |
| `seed` | Пин случайной фазы для воспроизведения. Также отключает replay корпуса для этого свойства — запиненный прогон важнее |
| `generators` | Имя метода генераторов; по умолчанию `<testMethod>Generators` |
| `examples` | Имя метода примеров; по умолчанию `<testMethod>Examples` |
| `maxShrinks` | Лимит принятых shrink-шагов; `0` отключает shrinking |
| `maxDiscards` | Бюджет discard-ов до провала с `GaveUpException`; по умолчанию `runs * 10` |
| `timeoutMs` | Wall-clock дедлайн одного прогона — превышение валит свойство с `DeadlineExceededException` |
| `budgetMs` | Wall-clock бюджет всей случайной фазы — исчерпание валит с `TimeBudgetExceededException` |
| `shrink` | `ShrinkMode::Full` (по умолчанию), `Off` (сообщить вход как сгенерирован) или `Bounded` с бюджетом |
| `shrinkBudgetMs` | Бюджет спуска по стенным часам — единственная ручка, стоящая детерминизма: докуда дойдёт спуск, зависит от длительности тела |
| `phases` | Какие стадии выполнять (`Phase::Examples`, `Corpus`, `Random`, `Shrink`); подмножество осознанно меняет покрытие на время |
| `derandomize` | Выводит незаданный seed из id property вместо случайного; `seed` в атрибуте всё равно побеждает |
| `path` | Воспроизводит записанный спуск shrink (`CounterExample::$path`) вместо поиска; требует `seed` |
| `edgeCases` | `EdgeCases::None` выключает граничное смещение числовых генераторов — для property, которой края стоят только прогонов |

### Переменные окружения

Кто побеждает, решает одно правило: **окружение крутит сьют, атрибут
закрепляет property.** `PROPERTY_RUNS`, `PROPERTY_PHASES` и
`PROPERTY_DERANDOMIZE` — ручки CI, они перекрывают атрибут; `PROPERTY_SEED` и
`PROPERTY_PATH` воспроизводят одно конкретное падение и уступают тому, что
написано в атрибуте.

| Переменная | Эффект |
|---|---|
| `PROPERTY_RUNS` | Положительное целое, переопределяет число прогонов каждого свойства (поднять в CI) |
| `PROPERTY_SEED` | Целый seed для свойств без атрибутного `seed` (replay всей suite). Явный seed атрибута важнее |
| `PROPERTY_VERBOSE` | Любое значение кроме `''`/`'0'` логирует аргументы каждого прогона и каждый принятый shrink-шаг |
| `PROPERTY_DB` | Путь к каталогу, включающий регрессионный корпус, либо DSN `redis://host[:port][/key-prefix]` для корпуса, общего между CI и разработчиками. Не задан — выключен, ничего не пишется |
| `PROPERTY_PHASES` | Список стадий через запятую (`examples,corpus,random,shrink`, регистр не важен), перекрывающий атрибут; неизвестное имя — исключение, а не пропуск стадии. `examples,corpus` — быстрый гейт для pull request |
| `PROPERTY_DERANDOMIZE` | Любое значение, кроме `''`/`'0'`, выводит каждый незаданный seed из id property: весь сьют становится воспроизводимым без правки кода |
| `PROPERTY_PATH` | Записанный спуск shrink воспроизводится вместо поиска. Нужен seed того прогона; `path` в атрибуте побеждает |
| `PROPERTY_EDGE_CASES` | `mixin` или `none` (регистр не важен) — граничное смещение для всего сьюта, перекрывает атрибут. Неизвестное значение — исключение |

### Корпус регрессий

`PROPERTY_DB` принимает либо каталог, либо Redis-DSN:

```bash
PROPERTY_DB=/tmp/corpus                  vendor/bin/testo   # одна машина
PROPERTY_DB=redis://127.0.0.1:6379       vendor/bin/testo   # общий
PROPERTY_DB=redis://redis:6379/suite-a:  vendor/bin/testo   # общий сервер, свой префикс
```

Каталог помнит контрпример для того, кто им владеет, — а в CI это машина,
которую удаляют вместе с job'ом. Redis-форма — тот же корпус в том же
документе, но общий: падение, найденное на ноутбуке, воспроизводится в CI, а
найденное в CI — на следующем ноутбуке. Нужен `ext-redis` или
`predis/predis`; отсутствие обоих — ошибка, а не тихий откат на файловую
систему: сьют, которому велели делиться корпусом и который молча пишет туда,
куда никто не смотрит, хуже остановившегося.


Задайте `PROPERTY_DB`, и каждое фальсифицированное свойство запишет туда своё
падение. При следующем прогоне записанные падения реплеятся **первыми** (если
атрибут не пинит собственный `seed`): всё ещё падающее сообщается сразу — как
`RegressionViolationException` для values-записи, — а переставшее падать
удаляется. Формат хранения — ровно тот, что писал `rasuvaeff/property-testing`
2.8, так что существующие CI-корпуса продолжают работать после миграции.
Детали хранения — в
[документации core](https://github.com/rasuvaeff/property-testing-core#regression-corpus).

### Coverage-атрибуты

Адаптер агрегирует per-run атрибуты `TestResult` каждого выполненного тела —
включая `CoverageResult` от Testo codecov — на единственный `TestResult`
property-теста. Поэтому property-тесты видны в per-test coverage, и Infection
гоняет их против мутантов как любой другой тест.

### Stateful / model-based тестирование

State machine движка работает под `#[Property]` без изменений:

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

Полный исполняемый пример со стеком —
[`examples/state_machine.php`](examples/state_machine.php).

### Генераторы

Полный каталог генераторов (`Gen::int()` … `Gen::subset()`, `Gen::regex()`,
`Gen::commands()`, `Gen::draw()`, собственные `ArbitraryInterface`) — это API
движка, документирован в
[README core](https://github.com/rasuvaeff/property-testing-core#generators).
Всё оттуда доступно из `#[Property]`-теста как есть.

## Публичный API пакета

| Тип | Роль |
|---|---|
| `Rasuvaeff\PropertyTesting\Property` | Атрибут — тот же FQCN, что и в 2.x |
| `Rasuvaeff\PropertyTesting\Testo\PropertyInterceptor` | Интерцептор Testo: разрешает reflection-конвенции и окружение в core `PropertyDefinition`, маппит структурированный результат в один `TestResult` |
| `Rasuvaeff\PropertyTesting\Testo\TestoTrialExecutor` | Выполняет тело свойства через pipeline Testo, агрегируя per-run атрибуты `TestResult` |
| `Rasuvaeff\PropertyTesting\Testo\VerboseListener` | Вывод `PROPERTY_VERBOSE` как exception-hardened listener движка |

## Безопасность

Генерируемые значения псевдослучайны (seeded MT19937), не криптографические.
Seed — не секрет: он печатается в выводе падения намеренно. Файлы корпуса
`PROPERTY_DB` — тестовые артефакты: они содержат сгенерированные входы как
есть, не указывайте переменную на публикуемый каталог.

## Примеры

См. [examples/](examples/) — `#[Property]`-тесты, запускаемые через
`vendor/bin/testo`.

## Разработка

```bash
make install     # composer install (Docker)
make build       # validate + normalize + require-checker + cs + psalm + tests
make cs-fix      # применить code style
make mutation    # мутационное тестирование infection
```

## Лицензия

[BSD-3-Clause](LICENSE.md)
