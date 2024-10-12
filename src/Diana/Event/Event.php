<?php

namespace Diana\Event;

use Diana\Drivers\EventInterface;
use Diana\Drivers\EventManagerInterface;

class Event implements EventInterface
{
    public function __construct(protected string $class, protected EventManagerInterface $eventManager)
    {
    }

    public function fire(string $action, array $payload = []): void
    {
        $this->eventManager->fire($this, $action, $payload);
    }

    public function getClass(): string
    {
        return $this->class;
    }
}
