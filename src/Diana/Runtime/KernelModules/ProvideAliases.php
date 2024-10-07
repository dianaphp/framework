<?php

namespace Diana\Runtime\KernelModules;

use Diana\Drivers\ConfigInterface;
use Diana\Runtime\Application;

class ProvideAliases implements KernelModule
{
    public function __construct(protected Application $app, protected ConfigInterface $config)
    {
    }

    public function init(): void
    {
        // cache ide helpers
        // TODO: make this a command
        // TODO: outsource to cache class
        if (!file_exists($cachePath = $this->app->path($this->config->get('aliasCachePath')))) {
            $cache = "<?php" . str_repeat(PHP_EOL, 2);

            foreach ($this->config->get('aliases') as $class) {
                $cache .= "class " . substr($class, strrpos($class, '\\') + 1) . " extends $class {}" . PHP_EOL;
            }

            file_put_contents($cachePath, $cache);
        }

        // provide aliases
        foreach ($this->config->get('aliases') as $class) {
            class_alias($class, substr($class, strrpos($class, '\\') + 1));
        }
    }
}