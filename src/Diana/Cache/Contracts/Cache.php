<?php

namespace Diana\Cache\Contracts;

interface Cache
{
    public function cache();
    public function flush();

    public function exists(): bool;
}