<?php

namespace Diana\Runtime\KernelModules;

use Diana\Drivers\ConfigInterface;
use Diana\Drivers\ContainerInterface;
use Diana\Runtime\Application;

class RegisterBindings implements KernelModule
{
    public function __construct(
        protected Application $app,
        protected ContainerInterface $container,
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
