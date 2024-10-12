<?php

namespace Diana\Runtime\KernelModules;

use Diana\Drivers\ConfigInterface;
use Diana\Runtime\Framework;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

class RegisterExceptionHandler implements KernelModule
{
    public function __construct()
    {
    }

    public function __invoke(): void
    {
        (new Run())
            ->pushHandler(php_sapi_name() == 'cli' ? new PlainTextHandler() : new PrettyPageHandler())
            ->register();
    }
}
