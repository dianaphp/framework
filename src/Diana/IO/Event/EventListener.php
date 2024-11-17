<?php

namespace Diana\IO\Event;

use Closure;
use Diana\Contracts\EventListenerContract;

class EventListener implements EventListenerContract
{
    public function __construct(
        protected string $event,
        protected array|string|Closure $callable,
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

    public function getCallable(): string|callable
    {
        return $this->callable;
    }

    public function setCallable(string|callable $callable): void
    {
        $this->callable = $callable;
    }

    public function getEvent(): string
    {
        return $this->event;
    }
}
