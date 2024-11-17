<?php

namespace Diana\Events;

class RegisterPackageEvent
{
    public function __construct(protected object $package, protected bool $force)
    {
    }

    public function getPackage(): object
    {
        return $this->package;
    }
}
