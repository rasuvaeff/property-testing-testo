<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo;

use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\Runner\TraceListener;
use Testo\Common\Messenger;
use Testo\Core\Log\Level;

/**
 * The `PROPERTY_VERBOSE` trace on Testo's messenger: core's
 * {@see TraceListener} words the lines (one per attempt's arguments, one per
 * attempt's in-body draws, one per target improvement, one per accepted
 * shrink step), this adapter only chooses the channel. Line formats are the
 * package's characterised output — pinned by the golden tests.
 *
 * Exception-hardened by policy, as the core listener is: a bug in the
 * built-in trace or in the messenger must not fail every verbose consumer.
 *
 * @internal
 */
final readonly class VerboseListener implements PropertyListener
{
    private TraceListener $trace;

    public function __construct(Messenger $messenger)
    {
        // On CHANNEL_STDERR, like the distribution report: Testo's terminal
        // renderer shows every other channel only at `-v`, and a trace someone
        // switched on with PROPERTY_VERBOSE must not need a second switch.
        $this->trace = new TraceListener(static function (string $line) use ($messenger): void {
            $messenger->log(Messenger::CHANNEL_STDERR, $line, Level::Info);
        });
    }

    #[\Override]
    public function onEvent(PropertyEvent $event): void
    {
        $this->trace->onEvent($event);
    }
}
