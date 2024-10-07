<?php

namespace Diana\Event;

use Diana\Drivers\EventInterface;

class Event implements EventInterface
{
    protected array $listeners = [];

    public function listen(string $action, callable $callback): void
    {
        $this->listeners[$action][] = $callback;
    }

    public function fire(string $action, mixed $payload = null): void
    {
        if (isset($this->listeners[$action])) {
            foreach ($this->listeners[$action] as $listener) {
                $listener($payload);
            }
        }
    }
}
