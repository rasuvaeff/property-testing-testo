<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\Event\RunDiscarded;
use Rasuvaeff\PropertyTesting\Event\RunFailed;
use Rasuvaeff\PropertyTesting\Event\RunPassed;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Event\ShrinkAccepted;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\ValueRenderer;
use Testo\Common\Messenger;
use Testo\Core\Log\Level;

/**
 * The `PROPERTY_VERBOSE` trace as a listener: one line per attempt's
 * arguments, one per attempt's in-body draws, one per accepted shrink step.
 * Line formats are the package's characterised output — change them only with
 * the golden tests, in the same commit.
 *
 * Exception-hardened by policy: a listener exception normally aborts the run
 * as an infrastructure failure, but a bug in this built-in trace must not fail
 * every verbose consumer, so it swallows its own errors instead.
 *
 * @internal
 */
final readonly class VerboseListener implements PropertyListener
{
    public function __construct(
        private Messenger $messenger,
    ) {}

    #[\Override]
    public function onEvent(PropertyEvent $event): void
    {
        try {
            if ($event instanceof RunStarted) {
                $this->log(sprintf(
                    'Property "%s" attempt %d: %s',
                    $this->name($event->propertyId),
                    $event->attempt,
                    $this->formatArguments($event->arguments),
                ));
            } elseif ($event instanceof RunPassed || $event instanceof RunFailed || $event instanceof RunDiscarded) {
                if ($event->draws !== []) {
                    $this->log(sprintf(
                        'Property "%s" attempt %d draws: %s',
                        $this->name($event->propertyId),
                        $event->attempt,
                        $this->formatArguments($event->draws),
                    ));
                }
            } elseif ($event instanceof ShrinkAccepted) {
                $this->log(sprintf(
                    'Property "%s" shrink step %d: %s=%s -> %s',
                    $this->name($event->propertyId),
                    $event->step,
                    $event->parameter,
                    ValueRenderer::render($event->before),
                    ValueRenderer::render($event->after),
                ));
            }
        } catch (\Throwable) {
            // Deliberately swallowed — see the class docblock.
        }
    }

    /**
     * The display name the package's messages use: the method part of a
     * `Class::method` property id.
     */
    private function name(string $propertyId): string
    {
        $separator = strrpos($propertyId, '::');

        return $separator === false ? $propertyId : substr($propertyId, $separator + 2);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function formatArguments(array $arguments): string
    {
        $pairs = array_map(
            static fn(mixed $value, mixed $name): string => $name . '=' . ValueRenderer::render($value),
            $arguments,
            array_keys($arguments),
        );

        return implode(', ', $pairs);
    }

    /**
     * @param non-empty-string $line
     */
    private function log(string $line): void
    {
        $this->messenger->log(Messenger::CHANNEL_STDOUT, $line, Level::Info);
    }
}
