<?php

namespace Diana\IO\Event;

use Diana\Contracts\ContainerContract;
use Diana\Contracts\EventManagerContract;
use Diana\Runtime\Framework;

class NullEventManager implements EventManagerContract
{
    public function __construct(
        protected ContainerContract $container
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
