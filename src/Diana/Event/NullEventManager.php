<?php

namespace Diana\Event;

use Diana\Contracts\ContainerContract;
use Diana\Event\EventInterface;
use Diana\Contracts\EventManagerContract;
use Diana\Contracts\EventListenerContract;
use Diana\Runtime\Framework;

class NullEventManager implements EventManagerContract
{
    public function __construct(
        protected Framework $app,
        protected ContainerContract $container
    ) {
    }

    public function createEventListener(
        string $event,
        array|string $callable,
        array $before = [],
        array $after = []
    ): EventListenerContract {
        return $this->container->make(
            EventListenerContract::class,
            compact('event', 'callable', 'before', 'after')
        );
    }

    public function addNewEventListener(
        string $event,
        array|string $callable,
        array $before = [],
        array $after = []
    ): EventListenerContract {
        $eventListener = $this->createEventListener($event, $callable, $before, $after);
        $this->addEventListener($eventListener);
        return $eventListener;
    }

    public function addNewSingleEventListener(
        string $event,
        array|string $callable,
        array $before = [],
        array $after = []
    ): EventListenerContract {
        $eventListener = $this->createEventListener($event, $callable, $before, $after);
        $this->addSingleEventListener($eventListener);
        return $eventListener;
    }

    public function addEventListener(EventListenerContract $eventListener): void
    {
    }

    public function addSingleEventListener(EventListenerContract $eventListener): void
    {
    }

    public function removeEventListener(EventListenerContract $eventListener): void
    {
    }

    public function fire(EventInterface $event): void
    {
    }
}
