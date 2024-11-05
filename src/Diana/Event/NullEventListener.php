<?php

namespace Diana\Event;

use Diana\Event\EventInterface;
use Diana\Contracts\EventListenerContract;

class NullEventListener implements EventListenerContract
{
    public function __construct(
        protected string $event,
        array|string $callable,
        array $before = [],
        array $after = []
    ) {
    }

    public function getBefore(): array
    {
        return [];
    }

    public function getAfter(): array
    {
        return [];
    }

    public function setCallable(callable|string $callable): void
    {
    }

    public function getCallable(): string|callable
    {
        return function () {
        };
    }

    public function getEvent(): string
    {
        return $this->event;
    }
}
