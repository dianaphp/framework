<?php

namespace Diana\Runtime\Implementations;

use Diana\Support\Collection\ImmutableCollection;
use Diana\Support\Helpers\Arr;
use Diana\Support\Helpers\Filesystem;
use Diana\Support\Serializer\ArraySerializer;

trait Config
{
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
        return false;
    }

    public function getConfigAppend(): bool
    {
        return false;
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
            $configFile = Filesystem::absPath('./config/' . $configFile . '.php');

            if ($exists = file_exists($configFile))
                $config = require $configFile;
        }

        $this->config = new ImmutableCollection(array_merge($default, $config), visible: $this->getConfigVisible(), hidden: $this->getConfigHidden());

        if ($configFile && !empty($this->config) && (!$exists && $this->getConfigCreate() || $this->getConfigAppend() && !empty(Arr::except($default, array_keys($config)))))
            file_put_contents($configFile, ArraySerializer::serialize($this->config));
    }
}