<?php

namespace Diana\Runtime\KernelModules;

use Diana\Cache\FileCache;
use Diana\Contracts\CacheContract;
use Diana\Contracts\ConfigContract;
use Diana\Contracts\ContainerContract;
use Diana\Runtime\Attributes\Config;
use Diana\Runtime\Framework;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;

class ProvideAliases implements KernelModule
{
    protected const string CACHE_KEY = 'aliases';

    protected CacheContract $cache;

    public function __construct(
        protected Framework $app,
        ContainerContract $container,
        #[Config('cfg/framework')] protected ConfigContract $config
    ) {
        // this is hard-coded on purpose, ide cache can only be a file
        $this->cache = $container->make(FileCache::class);
        $this->cache->setCacheExtension('.cache.php');
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function __invoke(): void
    {
        $cache = $this->cache->has(self::CACHE_KEY);

        // ide helpers
        // TODO: make this a command
        if (!$cache) {
            $script = "<?php";

            foreach ($this->config->get('aliases') as $class) {
                $script .= PHP_EOL . "class " . substr($class, strrpos($class, '\\') + 1) . " extends $class {}";
            }

            $script .= PHP_EOL;

            $this->cache->set(self::CACHE_KEY, $script);
        }

        // provide aliases
        $this->provideAliases();
    }

    /**
     * @throws ReflectionException
     */
    public function provideAliases(): void
    {
        foreach ($this->config->get('aliases') as $class) {
            $reflection = new ReflectionClass($class);
            class_alias($class, $reflection->getShortName());
        }
    }
}
