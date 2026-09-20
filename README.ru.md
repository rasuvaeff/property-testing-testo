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
- [`rasuvaeff/property-testing-core`](https://packagist.org/packages/rasuvaeff/property-testing-core) `^1.0`
- [`testo/testo`](https://packagist.org/packages/testo/testo) `^0.10.39`

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

С чем адаптер сочетается, а с чем нет:

- **Lifecycle-хуки выполняются на каждый прогон property.** Интерцептор стоит
  внутри lifecycle-интерцептора Testo, поэтому `#[BeforeTest]`/`#[AfterTest]`
  исполняются на каждый сгенерированный вход, а не один раз на тест (в
  PHPUnit `setUp` — один раз). Хук, бросивший исключение, — падение этого
  прогона, оно shrink-ается как любое другое.
- **Data provider не сочетается с `#[Property]`** — аргументы дают
  генераторы; `#[Property]` на function-based кейсе отклоняется. Оба
  случая, как и любая другая ошибка конфигурации (нет метода генераторов,
  плохой `PROPERTY_RUNS`, неизвестная фаза, `runs: 0`, ключ провайдера, не
  являющийся параметром property), сообщаются как ошибка теста с текстом
  причины. Сам атрибут ничего не проверяет: Testo создаёт его до запуска
  интерцептора, и только интерцептор может назвать property в сообщении.
- **`#[ExpectException]` не сочетается с `#[Property]`** и отклоняется как
  ошибка теста: интерцептор ожиданий стоит снаружи и наблюдает агрегатное
  падение property — `PropertyViolationException`, то есть
  `RuntimeException`, — поэтому `#[ExpectException(\RuntimeException::class)]`
  удовлетворила бы любая фальсификация, включая упавший ассерт. Ожидание на
  каждый прогон задаёт [`throws:`](#ожидаемые-исключения-throws) — и не
  `Expect::exception()` в теле: он регистрирует ожидание, которое тот же
  внешний интерцептор сверяет с агрегатом, и ни один guard этого не видит.
- **`SkipTest` из тела или хука пропускает прогон**; если пропущены все
  прогоны, property сообщается как пропущенный тест. Частично пропущенные
  прогоны тратят собственный бюджет, отдельный от `maxDiscards`: skip — не
  discard, и при исчерпании этого бюджета сообщение называет
  окружение, а не советует сузить генераторы. В отличие от discard'а по
  `Assume::that()`, skip ничего не говорит о входе, поэтому записанная
  регрессия, чей реплей только скипнулся, остаётся в корпусе, а не вычищается.

### Ожидаемые исключения (`throws:`)

Исключение из тела никогда не доходит до `#[ExpectException]`: терминальный
обработчик Testo превращает его в упавший результат этого прогона раньше, чем
его увидит любой внешний интерцептор. `throws:` задаёт ожидание там, где оно
проверяется:

```php
#[Property(runs: 200, throws: ImageUploadException::class)]
public function anImageNarrowerThanTheProfileIsRejected(int $width, int $minWidth): void
{
    $this->validator->validate(imageWithWidth($width), profileWithMinWidth($minWidth));
}
```

Семантика на каждый прогон:

- Бросает класс (подкласс подходит) — прогон **проходит**. Совпавшее
  исключение записывается как ассерт — так же Testo записывает собственное
  выполненное ожидание, — поэтому тело, которое больше ничего не проверяет,
  не будет помечено как risky.
- Возвращается нормально — прогон **падает** с `Expected <class> to be
  thrown, but it was not`, и вход shrink-ается как любой другой контрпример.
- Бросает другой класс — это исключение и есть падение, ровно как без
  `throws:`.
- Упавшая ассерция — никогда не ожидаемый бросок. Ассерции Testo наследуют
  `\LogicException`, поэтому `throws:` с классом, экземпляром которого они
  являются — `\LogicException`, `\Exception`, `\Throwable`, — отвергается
  при настройке свойства (`a failed assertion is an instance of`); называйте
  исключение, которое бросает тело. Упавший `Assert` в теле валит прогон,
  какой бы класс ни ожидался.
- `SkipTest` из тела или хука по-прежнему пропускает прогон, а discard по
  `Assume::that()` по-прежнему отбрасывает его: вердикт окружения о прогоне
  никогда не становится «проходом», заработанным исключением.

Класс, не являющийся `Throwable`, — ошибка конфигурации, сообщаемая как
ошибка теста. Та же ручка в PHPUnit-адаптере — `PropertyCheck::throws()`.

### Callable-провайдеры

`generators` и `examples` также принимают callable. Строка сначала разрешается
как имя метода тест-класса; только если такого метода нет, она считается именем
внешнего callable. Нестрочные callable сохраняются и вызываются при разрешении
property, поэтому invokable-провайдер можно передать прямо в атрибуте уже на
PHP 8.3:

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

Переиспользуемые static-провайдеры можно передать как
`[Provider::class, 'method']` или `'Provider::method'`. В PHP 8.5 дополнительно
доступны inline `static function (): array { ... }` и first-class callable,
например `Provider::method(...)`. Метод тест-класса с именем глобальной функции
(`range`, например) всё равно имеет приоритет над глобальной функцией.

Обратная сторона строкового разрешения: опечатка в имени метода, совпавшая с
глобальной функцией (`'count'`, `'range'`), приведёт к вызову этой функции —
обычно это её собственный `ArgumentCountError` от вызова без аргументов, а
для функции без обязательных аргументов — отказ валидации результата.
Опечатка, не совпавшая ни с чем, падает сразу с
`neither a method on … nor a callable`.

### Автогенераторы из сигнатуры (`auto: true`)

Когда параметры полностью описаны типами, provider-метод можно не писать
вовсе: `auto: true` строит генератор для каждого параметра из сигнатуры самой
property через
[`Gen::forParameters()`](https://github.com/rasuvaeff/property-testing-core) —
psalm-тип из `@param`, если он есть (`int<1, 300>`, `non-empty-string`,
`list<T>`, `'a'|'b'`), иначе нативный:

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

Провайдер — явный или конвенционный `<testMethod>Generators` — становится
**overrides** и может быть частичным: перечисленные в нём параметры берутся
как есть, остальные достраиваются. Это выход для доменов, которые psalm-типом
не выразить (float-диапазон, зависимая пара через `Gen::flatMap()`):

```php
/** @param int<1, 40> $attempt */
#[Property(generators: 'provide', auto: true)]
public function delayIsMonotonic(float $multiplier, int $attempt): void { /* … */ }

/** @return array<string, ArbitraryInterface> */
public static function provide(): array
{
    return ['multiplier' => Gen::floatBetween(1.0, 4.0)];   // остальное достраивается
}
```

Правила, которые стоит знать:

- **Строго opt-in.** `auto` по умолчанию `false` и дефолтом не станет никогда:
  голый `int` или `float` достраивается до полного нативного домена, и только
  автор property знает, тот ли это домен. Всё более узкое — аннотировать или
  переопределять.
- Нечитаемый тип (голый `array`, `mixed`, параметр без типа, variadic) — ошибка
  с именем метода и параметра, никогда не «угаданный генератор пошире».
- Ключ провайдера, не являющийся параметром property, — ошибка с `auto` и
  без него: проигнорированный, опечатанный ключ оставляет свой параметр без
  генератора, а при `auto` merge-семантика молча заменила бы его генератором
  из сигнатуры. Провайдер, общий для двух property разной арности, поэтому
  отклоняется — у каждой property свой.
- Полный провайдер плюс `auto: true` легален — auto ничего не достраивает; это
  переходное состояние мигрирующего теста.
- Переменной окружения `PROPERTY_AUTO` нет намеренно: окружение крутит сьют
  (runs, phases), а `auto` меняет смысл аргументов конкретной property —
  территория атрибута.

### Параметры атрибута

| Параметр | Значение |
|---|---|
| `runs` | Число успешных проверок (по умолчанию 100). Discarded-прогоны не считаются |
| `seed` | Пин случайной фазы для воспроизведения. Также отключает replay корпуса для этого свойства — запиненный прогон важнее |
| `generators` | Имя метода или `callable(): array<string, ArbitraryInterface>`; по умолчанию `<testMethod>Generators` |
| `examples` | Имя метода или `callable(): iterable<array<mixed>>`; по умолчанию `<testMethod>Examples` |
| `maxShrinks` | Лимит принятых shrink-шагов; `0` отключает shrinking |
| `maxDiscards` | Порог для бюджета discard'ов **и** бюджета skip'ов. Оставленные неявными, они различаются: `runs * 10` для discard'ов и `runs` для skip'ов окружения |
| `timeoutMs` | Wall-clock дедлайн одного прогона — превышение валит свойство с `DeadlineExceededException` |
| `budgetMs` | Wall-clock бюджет всей случайной фазы — исчерпание валит с `TimeBudgetExceededException` |
| `shrink` | `ShrinkMode::Full` (по умолчанию), `Off` (сообщить вход как сгенерирован) или `Bounded` с бюджетом |
| `shrinkBudgetMs` | Бюджет спуска по стенным часам — единственная ручка, стоящая детерминизма: докуда дойдёт спуск, зависит от длительности тела |
| `phases` | Какие стадии выполнять (`Phase::Examples`, `Corpus`, `Random`, `Shrink`); подмножество осознанно меняет покрытие на время |
| `derandomize` | Выводит незаданный seed из id property вместо случайного; `seed` в атрибуте всё равно побеждает |
| `path` | Воспроизводит записанный спуск shrink (`CounterExample::$path`) вместо поиска; требует `seed` |
| `edgeCases` | `EdgeCases::None` выключает граничное смещение числовых генераторов — для property, которой края стоят только прогонов |
| `auto` | Достраивает генераторы из сигнатуры property для параметров, не покрытых провайдером; провайдер становится частичными overrides. По умолчанию выключен и дефолтом не станет |
| `throws` | Класс исключения, который обязан бросить каждый прогон: бросивший его проходит, вернувшийся нормально или бросивший другой класс падает и shrink-ается. Замена `#[ExpectException]` на уровне прогона; сам `#[ExpectException]` на property отклоняется |
| `exhaustive` | Обойти весь домен параметров вместо выборки, когда каждый генератор — `Enumerable`, а произведение умещается в `exhaustiveBudget`; иначе фаза делает выборку, а предупреждение говорит почему. `runs` при обходе игнорируется — см. [README core](https://github.com/rasuvaeff/property-testing-core#exhaustive-mode) |
| `exhaustiveBudget` | Наибольший домен, который обходит `exhaustive` (по умолчанию 10 000) |
| `flakyReplays` | Повторные исполнения минимизированного контрпримера (по умолчанию 2); прошедший помечает контрпример flaky, со строкой `Flaky:` в сообщении. `0` выключает — см. [детекцию flaky](https://github.com/rasuvaeff/property-testing-core#flaky-detection) |
| `searchRuns` | Сколько тел может исполнить целевой поиск после random-фазы, если тело вызывает `Target::maximize()`/`minimize()` (по умолчанию 0 — без поиска) — см. [целевой поиск](https://github.com/rasuvaeff/property-testing-core#targeted-search-target) |

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
| `PROPERTY_VERBOSE` | Логирует аргументы каждого прогона и каждый принятый shrink-шаг. Выключено для `''`, `0`, `false`, `off` и `no` (регистр не важен, пробелы обрезаются); любое другое значение включает. |
| `PROPERTY_DB` | Путь к каталогу, включающий регрессионный корпус, либо DSN `redis://host[:port][/db][?prefix=key-prefix]` (`rediss://` для TLS) для корпуса, общего между CI и разработчиками. Не задан — выключен, ничего не пишется |
| `PROPERTY_PHASES` | Список стадий через запятую (`examples,corpus,random,shrink`, регистр не важен), перекрывающий атрибут; неизвестное имя — исключение, а не пропуск стадии. `examples,corpus` — быстрый гейт для pull request |
| `PROPERTY_DERANDOMIZE` | Выводит каждый незаданный seed из id property: весь сьют становится воспроизводимым без правки кода. `''` оставляет атрибут в силе; `0`, `false`, `off` и `no` (регистр не важен, пробелы обрезаются) принудительно выключают, перекрывая `derandomize: true`; любое другое значение принудительно включает. |
| `PROPERTY_PATH` | Записанный спуск shrink воспроизводится вместо поиска. **Требует закреплённого seed** — `PROPERTY_SEED` или атрибутного — и без него отвергается: у property без seed адаптер рисует случайный, и путь воспроизводил бы спуск прогона, которого не было. `path` в атрибуте побеждает. Он описывает одно падение, поэтому запускайте с фильтром на этот один тест — любое другое property сообщит, что путь устарел |
| `PROPERTY_EDGE_CASES` | `mixin` или `none` (регистр не важен) — граничное смещение для всего сьюта, перекрывает атрибут. Неизвестное значение — исключение |
| `PROPERTY_EXHAUSTIVE` | Включает исчерпывающий режим для каждой property, чей домен умещается в её бюджет (ночной прогон, доказывающий малые домены). Выключают те же слова, что и `PROPERTY_DERANDOMIZE` |
| `PROPERTY_SEARCH_RUNS` | Неотрицательное целое, перекрывающее `searchRuns` каждой property — больший бюджет поиска на ночном прогоне или `0`, чтобы выключить его. Испорченное значение — исключение |

### Корпус регрессий

`PROPERTY_DB` принимает либо каталог, либо Redis-DSN:

```bash
PROPERTY_DB=/tmp/corpus                           vendor/bin/testo   # одна машина
PROPERTY_DB=redis://127.0.0.1:6379                vendor/bin/testo   # общий
PROPERTY_DB=redis://redis:6379/2?prefix=suite-a:  vendor/bin/testo   # общий сервер, база 2, свой префикс
PROPERTY_DB=rediss://redis.example.com            vendor/bin/testo   # TLS
```

Форма DSN — та же, что у всех остальных (регистрация IANA, predis, Symfony):
путь — индекс базы, префикс ключей — query-параметр `prefix`, `rediss://` —
TLS. Форма до 0.7 с префиксом в пути (`redis://host/suite-a:`) отклоняется с
подсказкой нового написания. Значение разбирает `CorpusFactory` движка,
общий с PHPUnit-адаптером.

Каталог помнит контрпример для того, кто им владеет, — а в CI это машина,
которую удаляют вместе с job'ом. Redis-форма — тот же корпус в том же
документе, но общий: падение, найденное на ноутбуке, воспроизводится в CI, а
найденное в CI — на следующем ноутбуке. Нужен `ext-redis` или
`predis/predis`; отсутствие обоих — ошибка, а не тихий откат на файловую
систему: сьют, которому велели делиться корпусом и который молча пишет туда,
куда никто не смотрит, хуже остановившегося. `PROPERTY_DB` с любой другой
схемой — опечатка `rediss://`, другой бэкенд — тоже ошибка, а не каталог с
именем схемы. Учётные данные в DSN (`redis://user:pass@host`) отклоняются, а не
молча отбрасываются; настраивайте Redis AUTH отдельно.

#### Как записи попадают в корпус

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

Rule-фасад работает так же — `#[Rule]`, `#[Precondition]` и `#[Invariant]` на
одном классе машины, `Gen::rules(QueueMachine::class)` как генератор и
`$sequence->run(static fn () => new QueueMachine(new Queue()))` в теле; полный
пример — в [README движка](https://github.com/rasuvaeff/property-testing-core#rule-машины-genrules).

Интерцептор печатает рядом со строкой распределения то, что измерил движок:
каждую таблицу `Classify::tabulate()` с долями тегов и парами, встретившимися
вместе, обошёл ли исчерпывающий режим домен или почему сделал выборку, и отчёт
поиска (`evaluations`, по метке — лучшая оценка и число улучшений).
`PROPERTY_VERBOSE` дополнительно логирует каждое событие `TargetImproved` с
давшим его входом.

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

`TestoTrialExecutor` и `VerboseListener` — `@internal`: реализация
интерцептора, а не контракт. Переменные окружения и DSN `PROPERTY_DB`
разбирает движок (`EnvironmentOverrides`, `CorpusFactory`), поэтому под
PHPUnit-адаптером они значат то же самое.

### Слушатели

`PropertyInterceptor::__construct(Messenger $messenger, ?Clock $clock = null,
iterable $listeners = [])` принимает `PropertyListener` — наблюдателей
событий жизненного цикла движка (`PropertyStarted`, `RunFailed`,
`ShrinkAccepted`, …), аналог `listeners(...)` PHPUnit-адаптера. Атрибут сам
регистрирует интерцептор, который собирает контейнер Testo, ничего не знающий
о ваших слушателях, — поэтому передайте ему свой экземпляр из плагина в
`testo.php`; сконфигурированный интерцептор Testo предпочитает тому, который
создал бы атрибут:

```php
use Internal\Container\Container;
use Rasuvaeff\PropertyTesting\Testo\PropertyInterceptor;
use Testo\Application\Config\ApplicationConfig;
use Testo\Common\PluginConfigurator;
use Testo\Pipeline\InterceptorCollector;

return new ApplicationConfig(
    suites: [/* … */],
    plugins: [
        new class implements PluginConfigurator {
            public function configure(Container $container): void
            {
                $container->get(InterceptorCollector::class)->addInterceptor(
                    $container->make(PropertyInterceptor::class, ['listeners' => [new MyListener()]]),
                );
            }
        },
    ],
);
```

`make()` собирает интерцептор, разрешая остальные зависимости через
контейнер. Слушатель трассы `PROPERTY_VERBOSE` добавляется автоматически.
Слушатель, бросивший исключение, прерывает прогон — политика движка.

## Безопасность

Генерируемые значения псевдослучайны (seeded MT19937), не криптографические.
Seed — не секрет: он печатается в выводе падения намеренно. Файлы корпуса
`PROPERTY_DB` — тестовые артефакты: они содержат сгенерированные входы как
есть, не указывайте переменную на публикуемый каталог.

## Примеры

См. [examples/](examples/) — `#[Property]`-тесты в отдельном сьюте Testo:
`vendor/bin/testo --suite=Examples`.

## Разработка

```bash
make install     # composer install (Docker)
make build       # validate + normalize + require-checker + cs + psalm + tests
make cs-fix      # применить code style
make mutation    # мутационное тестирование infection
```

## Лицензия

[BSD-3-Clause](LICENSE.md)
