<?php

namespace Diana\Config;

interface ConfigInterface
{
    public function get(?string $key = null): mixed;

    public function setDefault(array $default): self;
}
