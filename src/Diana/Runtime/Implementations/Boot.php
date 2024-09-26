<?php

namespace Diana\Runtime\Implementations;

use Diana\Runtime\Container;
use RuntimeException;

trait Boot
{
    private bool $booted = false;

    public function performBoot(Container $container)
    {
        if ($this->hasBooted())
            throw new RuntimeException('The runtime [' . get_class($this) . '] has already been booted.');

        if(method_exists($this, 'boot'))
            $container->call([$this, 'boot']);

        $this->booted = true;
    }

    public function hasBooted(): bool
    {
        return $this->booted;
    }
}