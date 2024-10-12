<?php

namespace Diana\Runtime\KernelModules;

use Diana\Drivers\ConfigInterface;
use Diana\Runtime\Framework;

class ConfigurePhp implements KernelModule
{
    public function __construct(protected Framework $app, protected ConfigInterface $config)
    {
    }

    public function __invoke(): void
    {
        error_reporting(E_ALL);

        $env = $this->config->get('env');
        // TODO: hardcoding is bad, find another solution, maybe constants?
        ini_set('display_errors', $env == 'dev' ? 'On' : 'Off');

        $logs = $this->config->get('logs');
        ini_set('log_errors', 'On');

        if (isset($logs['error'])) {
            ini_set('error_log', $this->app->path($logs['error']));
        }

        if (isset($logs['access'])) {
            ini_set('access_log', $this->app->path($logs['access']));
        }

        if ($timezone = $this->config->get('timezone')) {
            ini_set('date.timezone', $timezone);
        }

        ini_set('xdebug.var_display_max_depth', 10);
        ini_set('xdebug.var_display_max_children', 256);
        ini_set('xdebug.var_display_max_data', 1024);
    }
}
