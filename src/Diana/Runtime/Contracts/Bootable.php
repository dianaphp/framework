<?php

namespace Diana\Runtime\Contracts;

interface Bootable
{
    // Dependency injection
    // public function boot(): void;

    public function hasBooted(): bool;
}