<?php

namespace Diana\Runtime\Implementations;

use Diana\Support\Collection\ImmutableCollection;
use Diana\Support\Helpers\Arr;
use Diana\Support\Helpers\Filesystem;
use Diana\Support\Serializer\ArraySerializer;

trait Config
{
    const cfgFolder = './cfg';

    public ImmutableCollection $config;

    public function getConfigDefault(): array
    {
        return [];
    }

    public function getConfigFile(): ?string
    {
        return null;
    }

    public function getConfigCreate(): bool
    {
        return true;
    }

    public function getConfigAppend(): bool
    {
        return true;
    }

    public function getConfigVisible(): array
    {
        return [];
    }

    public function getConfigHidden(): array
    {
        return [];
    }

    public function loadConfig(): void
    {
        $default = $this->getConfigDefault();
        $config = [];

        if ($configFile = $this->getConfigFile()) {
            $configFile = Filesystem::absPath(self::cfgFolder . '/' . $configFile . '.php');

            if ($exists = file_exists($configFile))
                $config = require $configFile;
        }

        $this->config = new ImmutableCollection(array_merge($default, $config), visible: $this->getConfigVisible(), hidden: $this->getConfigHidden());

        if ($configFile && !empty($this->config) && (!$exists && $this->getConfigCreate() || $this->getConfigAppend() && !empty(Arr::except($default, array_keys($config)))))
            file_put_contents($configFile, ArraySerializer::serialize($this->config));
    }
}