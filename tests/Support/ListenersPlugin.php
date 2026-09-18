<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Testo\Tests\Support;

use Internal\Container\Container;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\Testo\PropertyInterceptor;
use Testo\Common\PluginConfigurator;
use Testo\Pipeline\InterceptorCollector;

/**
 * The README recipe for attaching listeners, verbatim: a plugin registers a
 * `PropertyInterceptor` built with them, and Testo prefers that instance over
 * the one the attribute would otherwise create. Static so that a test can hand
 * its listener to a plugin Testo instantiates by class name.
 */
final class ListenersPlugin implements PluginConfigurator
{
    public static ?PropertyListener $listener = null;

    #[\Override]
    public function configure(Container $container): void
    {
        $container->get(InterceptorCollector::class)->addInterceptor(
            $container->make(PropertyInterceptor::class, [
                'listeners' => self::$listener instanceof PropertyListener ? [self::$listener] : [],
            ]),
        );
    }
}
