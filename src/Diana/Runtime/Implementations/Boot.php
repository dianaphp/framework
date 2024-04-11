<?php

namespace Diana\Runtime\Implementations;

use Diana\Runtime\Application;
use RuntimeException;

trait Boot
{
    public bool $booted = false;

    public function performBoot(Application $app)
    {
        if ($this->hasBooted())
            throw new RuntimeException('The runtime [' . get_class($this) . '] has already been booted.');

        $app->call([$this, 'boot']);
        $this->booted = true;
    }

    public function hasBooted(): bool
    {
        return $this->booted;
    }
}