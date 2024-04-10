<?php

namespace Diana\Runtime\Contracts;

interface Bootable
{
    public function boot(): void;
    public function hasBooted(): bool;
}