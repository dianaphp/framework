<?php

namespace Diana\Runtime\KernelModules;

use Diana\Drivers\ConfigInterface;
use Diana\Runtime\Application;
use Illuminate\Container\Container;

class RegisterBindings implements KernelModule
{
    public function __construct(
        protected Application $app,
        protected Container $container,
        protected ConfigInterface $config
    ) {
    }

    public function init(): void
    {
        foreach ($this->config->get('bindings') as $abstract => $concrete) {
            $this->container->singleton($abstract, $concrete);
        }
    }
}