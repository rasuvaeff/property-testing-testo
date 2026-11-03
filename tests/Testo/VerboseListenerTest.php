<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests;

use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Event\TargetImproved;
use Rasuvaeff\PropertyTesting\Runner\TargetDirection;
use Rasuvaeff\PropertyTesting\Testo\VerboseListener;
use Testo\Application\Internal\MessengerHub;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Common\Messenger;
use Testo\Test;

/**
 * The branches of the verbose trace the adapter cannot reach on its own. The
 * line formats themselves are pinned by {@see GoldenMessagesTest}; here are the
 * input no interceptor produces: a property id that is not `Class::method`.
 * The swallow-everything policy is pinned by {@see EventOrderTest}.
 */
#[Test]
#[Covers(VerboseListener::class)]
final class VerboseListenerTest
{
    public function aPropertyIdWithoutAClassIsPrintedWhole(): void
    {
        // The adapter always builds `Class::method`, so only a direct engine
        // consumer reaches this: the id is the display name as it stands.
        $messenger = $this->messenger();

        (new VerboseListener($messenger))->onEvent(new RunStarted('bare-id', 1, ['x' => 7]));

        $lines = $this->lines($messenger);

        Assert::same(count($lines), 1);
        Assert::same($lines[0], 'Property "bare-id" attempt 1: x=7');
    }

    public function aTargetImprovementIsPrintedWithItsDirectionAndPreviousBest(): void
    {
        $messenger = $this->messenger();
        $listener = new VerboseListener($messenger);

        $listener->onEvent(new TargetImproved('Cls::prop', 'depth', TargetDirection::Maximize, 3.0, null, ['x' => 7]));
        $listener->onEvent(new TargetImproved('Cls::prop', 'size', TargetDirection::Minimize, 1.0, 2.0, ['x' => 5]));

        $lines = $this->lines($messenger);

        Assert::same($lines, [
            'Property "prop" target depth max: - -> 3 (x=7)',
            'Property "prop" target size min: 2 -> 1 (x=5)',
        ]);
    }

    /**
     * @return list<string>
     */
    private function lines(MessengerHub $messenger): array
    {
        return array_map(
            static fn(object $message): string => (string) $message->content,
            $messenger->getMessages()->channel(Messenger::CHANNEL_STDOUT),
        );
    }

    private function messenger(): MessengerHub
    {
        return new MessengerHub(new class implements EventDispatcherInterface {
            #[\Override]
            public function dispatch(object $event): object
            {
                return $event;
            }
        });
    }
}
