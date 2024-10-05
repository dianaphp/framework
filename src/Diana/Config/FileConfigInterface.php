<?php

namespace Diana\Config;

use Diana\Runtime\Application;
use Diana\Support\Exceptions\FileNotFoundException;
use Diana\Support\Helpers\Filesystem;
use Diana\Support\Serializer\ArraySerializer;
use Illuminate\Contracts\Container\ContextualAttribute;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class FileConfigInterface implements ConfigInterface, ContextualAttribute
{
    protected const string FOLDER = 'cfg';

    protected array $config;

    protected array $default = [];

    public function __construct(protected Application $app, protected string $name = 'app')
    {
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws FileNotFoundException
     * @throws NotFoundExceptionInterface
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

            $this->config = [...$this->default, ...$local];

            if ($this->config != $local) {
                file_put_contents($absPath, ArraySerializer::serialize($this->config));
            }
        }

        return $key ? $this->config[$key] : $this->config;
    }

    public function setDefault(array $default): self
    {
        $this->default = $default;
        return $this;
    }
}