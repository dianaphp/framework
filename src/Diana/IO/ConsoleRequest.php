<?php

namespace Diana\IO;

use Diana\Contracts\IO\ConsoleRequest as ConsoleRequestContract;
use Diana\Support\Collection\ImmutableCollection;

class ConsoleRequest extends Request implements ConsoleRequestContract
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

    public function getArguments(): array
    {
        return $this->args->getAll();
    }

    public function getDefaultStatusCode(): int
    {
        return 0;
    }
}
