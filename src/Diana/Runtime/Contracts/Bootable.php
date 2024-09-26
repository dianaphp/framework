<?php

namespace Diana\Runtime\Contracts;

interface Bootable
{
    public function hasBooted(): bool;
}