<?php

namespace Diana\Runtime\Contracts;

interface Configurable
{
    public function getConfigFile(): ?string;

    public function getConfigCreate(): bool;

    public function getConfigAppend(): bool;

    public function getConfigDefault(): array;

    public function loadConfig(): void;
}