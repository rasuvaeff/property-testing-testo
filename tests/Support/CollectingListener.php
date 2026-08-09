<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Support;

use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\PropertyListener;

/**
 * Records every event in arrival order, for asserting on exact lifecycles.
 */
final class CollectingListener implements PropertyListener
{
    /** @var list<PropertyEvent> */
    public array $events = [];

    #[\Override]
    public function onEvent(PropertyEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * The short class names of the recorded events, in order — the shape of a
     * lifecycle without its payloads.
     *
     * @return list<string>
     */
    public function shapes(): array
    {
        return array_map(
            static fn(PropertyEvent $event): string => substr($event::class, strrpos($event::class, '\\') + 1),
            $this->events,
        );
    }

    /**
     * The recorded events of one type.
     *
     * @template T of PropertyEvent
     * @param class-string<T> $type
     * @return list<T>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter(
            $this->events,
            static fn(PropertyEvent $event): bool => $event instanceof $type,
        ));
    }
}
