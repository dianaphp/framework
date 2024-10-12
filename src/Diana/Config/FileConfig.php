<?php

namespace Diana\Config;

use Diana\Runtime\Framework;
use Diana\Support\Exceptions\FileNotFoundException;
use Diana\Support\Helpers\Filesystem;
use Diana\Support\Serializer\ArraySerializer;
use Diana\Drivers\ConfigInterface;
use Illuminate\Contracts\Container\ContextualAttribute;

class FileConfig implements ConfigInterface, ContextualAttribute
{
    protected string $path;

    protected ?array $config = null;
    protected array $default = [];

    public function __construct(protected Framework $app, protected string $name = 'cfg/app')
    {
        $this->path = $this->app->path($name . '.php');
    }

    // TODO: Outsource
    protected function arrayMergeRecursiveDistinct(array $array1, array $array2): array
    {
        $merged = $array1;

        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->arrayMergeRecursiveDistinct($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @throws FileNotFoundException
     */
    public function get(?string $key = null): mixed
    {
        $this->enforceIntegrity();

        return $key ? $this->config[$key] : $this->config;
    }

    /**
     * @throws FileNotFoundException
     */
    public function addDefault(array $default): self
    {
        $this->default = $this->arrayMergeRecursiveDistinct($this->default, $default);
        $this->enforceIntegrity();
        return $this;
    }

    public function getDefault(?string $key = null): array
    {
        return $key ? $this->default[$key] : $this->default;
    }

    /**
     * @throws FileNotFoundException
     */
    protected function enforceIntegrity(): void
    {
        if (!isset($this->config)) {
            if (file_exists($this->path)) {
                $this->config = Filesystem::getRequire($this->path);
            } else {
                $this->config = [];
            }
        }

        $config = $this->arrayMergeRecursiveDistinct($this->default, $this->config);

        if ($this->config != $config) {
            file_put_contents($this->path, ArraySerializer::serialize($config));
        }

        $this->config = $config;
    }
}
