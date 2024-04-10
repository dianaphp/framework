<?php

namespace Diana\Runtime\Implementations;

use RuntimeException;

trait Boot
{
    public bool $booted = false;

    public function performBoot()
    {
        if ($this->hasBooted())
            throw new RuntimeException('The runtime [' . get_class($this) . '] has already been booted.');

        $this->boot();
        $this->booted = true;
    }

    abstract function boot(): void;

    public function hasBooted(): bool
    {
        return $this->booted;
    }
}