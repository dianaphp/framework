<?php

namespace Diana\Config;

use Diana\Contracts\Config\Config as ConfigContract;
use Diana\Support\Exceptions\FileNotFoundException;
use Diana\Support\Helpers\Filesystem;
use Diana\Support\Serializer\ArraySerializer;
use Exception;
use Illuminate\Contracts\Container\ContextualAttribute;
use RuntimeException;

class FileConfig extends Config implements ConfigContract, ContextualAttribute
{
    protected static string $directory;
    protected string $path;
    private bool $loaded = false;

    public function __construct(protected string $name)
    {
        if (!isset(self::$directory)) {
            throw new RuntimeException('Config directory not set');
        }

        parent::__construct($name);
        $this->path = self::$directory . DIRECTORY_SEPARATOR . $name . '.conf.php';
        if (!is_file($this->path)) {
            throw new Exception("Config file not found: {$this->path}");
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->enforceIntegrity();
        return parent::get($key, $default);
    }

    public function all(): array
    {
        $this->enforceIntegrity();
        return parent::all();
    }

    public function addDefault(array $default): self
    {
        parent::addDefault($default);
        $this->enforceIntegrity();
        return $this;
    }

    protected function enforceIntegrity(): void
    {
        if (!$this->loaded) {
            $this->loaded = true;
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

    public static function setDirectory(string $directory): void
    {
        self::$directory = $directory;
    }
}
