<?php

namespace Diana\Config;

use Diana\Runtime\Application;
use Diana\Support\Exceptions\FileNotFoundException;
use Diana\Support\Helpers\Filesystem;
use Diana\Support\Serializer\ArraySerializer;
use Diana\Drivers\ConfigInterface;
use Illuminate\Contracts\Container\ContextualAttribute;

class FileConfig implements ConfigInterface, ContextualAttribute
{
    protected const string FOLDER = 'cfg';

    protected ?array $config;

    protected array $default = [];

    public function __construct(protected Application $app, protected string $name = 'app')
    {
    }

    // TODO: Outsource
    public function arrayMergeRecursiveDistinct(array $array1, array $array2): array
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
        if (!isset($this->config)) {
            $absPath = $this->app->path(self::FOLDER . DIRECTORY_SEPARATOR . $this->name . '.php');

            if (file_exists($absPath)) {
                $local = Filesystem::getRequire($absPath);
            } else {
                $local = [];
            }

            $this->config = $this->arrayMergeRecursiveDistinct($this->default, $local);

            if ($this->config != $local) {
                file_put_contents($absPath, ArraySerializer::serialize($this->config));
            }
        }

        return $key ? $this->config[$key] : $this->config;
    }

    public function setDefault(array $default): self
    {
        $this->default = $default;
        $this->config = null;
        return $this;
    }
}
