<?php

namespace Diana\Cache;

use Diana\Cache\Contracts\Cache;
use Diana\Runtime\Application;

class Aliases implements Cache
{
    private $CONFIG_CACHE_FILE;
    private $CONFIG_ALIASES = [];

    public function __construct(private Application $app)
    {
        $this->CONFIG_CACHE_FILE = $this->app->getPath() . '/cache/aliases.php';
    }

    public function provide($cached = true)
    {
        foreach ($this->CONFIG_ALIASES as $class)
            class_alias($class, substr($class, strrpos($class, '\\') + 1));
    }

    public function cache()
    {
        $cache = "<?php" . str_repeat(PHP_EOL, 2);

        foreach ($this->CONFIG_ALIASES as $class)
            $cache .= "class " . substr($class, strrpos($class, '\\') + 1) . " extends $class {}" . PHP_EOL;

        file_put_contents($this->CONFIG_CACHE_FILE, $cache);
    }

    public function flush()
    {
        unlink($this->CONFIG_CACHE_FILE);
    }

    public function exists(): bool
    {
        return file_exists($this->CONFIG_CACHE_FILE);
    }
}