<?php

namespace Diana\Runtime\KernelModules;

use Diana\Drivers\ConfigInterface;
use Diana\Runtime\Application;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

class RegisterExceptionHandler implements KernelModule
{
    public function __construct(protected Application $app, protected ConfigInterface $config)
    {
    }

    public function init(): void
    {
        (new Run())
            ->pushHandler($this->app->getSapi() == 'cli' ? new PlainTextHandler() : new PrettyPageHandler())
            ->register();
    }
}
