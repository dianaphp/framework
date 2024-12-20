<?php

namespace Diana\Config;

use Diana\Contracts\ConfigContract;
use Diana\Runtime\Framework;
use Diana\Support\Exceptions\FileNotFoundException;
use Diana\Support\Helpers\Filesystem;
use Diana\Support\Serializer\ArraySerializer;
use Illuminate\Contracts\Container\ContextualAttribute;

class FileConfig implements ConfigContract, ContextualAttribute
{
    protected string $path;

    protected ?array $config = null;
    protected array $default = [];

    public function __construct(protected Framework $app, protected string $name = 'cfg/app')
    {
        $this->path = $this->app->path($name . '.conf.php');
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

    protected function enforceIntegrity(): void
    {
        if (!isset($this->config)) {
            try {
                $this->config = Filesystem::getRequire($this->path);
            } catch (FileNotFoundException) {
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
