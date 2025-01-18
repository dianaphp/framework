<?php

namespace Diana\IO\Event;

use Diana\Contracts\Core\Container;
use Diana\Contracts\Event\Dispatcher;

class NullDispatcher implements Dispatcher
{
    public function __construct(
        protected Container $container
    ) {
    }

    public function addEventListener(
        string $event,
        callable $eventListener,
        array $before = [],
        array $after = []
    ): callable {
        return $eventListener;
    }

    public function addSingleEventListener(
        string $event,
        callable $eventListener,
        array $before = [],
        array $after = []
    ): callable {
        return $eventListener;
    }

    public function dispatch(object $event): void
    {
    }

    public function removeEventListener(string $event, callable $eventListener): bool
    {
        return true;
    }
}
