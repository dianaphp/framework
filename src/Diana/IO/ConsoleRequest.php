<?php

namespace Diana\IO;

use Diana\Support\Collection\ImmutableCollection;

class ConsoleRequest extends Request
{
    public ImmutableCollection $args;

    public function __construct(protected string $command, array $args = [])
    {
        $this->args = new ImmutableCollection($args);

        parent::__construct("");
    }

    public function getCommand(): string
    {
        return $this->command;
    }
}
