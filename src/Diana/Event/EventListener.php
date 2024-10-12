<?php

namespace Diana\Event;

use Diana\Drivers\EventListenerInterface;
use Illuminate\Container\Container;

class EventListener implements EventListenerInterface
{
    public function __construct(
        protected string $class,
        protected string $action,
        protected array|string $callable,
        protected array $before = [],
        protected array $after = []
    ) {
    }

    public function getBefore(): array
    {
        return $this->before;
    }

    public function getAfter(): array
    {
        return $this->after;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getCallable(): string|callable
    {
        return $this->callable;
    }
}
