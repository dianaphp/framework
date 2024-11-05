<?php

namespace Diana\Events;

use Diana\Event\EventInterface;

class RegisterPackageEvent implements EventInterface
{
    public function __construct(protected object $package, protected bool $force)
    {
    }

    public function getPackage(): object
    {
        return $this->package;
    }
}
