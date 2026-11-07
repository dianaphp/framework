<?php

namespace Diana\IO;

use Diana\Contracts\RequestContract;

abstract class Request implements RequestContract
{
    public function __construct(protected string $resource)
    {
    }

    abstract public function getDefaultStatusCode(): int;

    public static function capture(array $trustedProxies = []): Request
    {
        if (php_sapi_name() === 'cli') {
            $argv = $_SERVER['argv'];
            array_shift($argv);
            $command = array_shift($argv);
            return new ConsoleRequest($command ?? 'version', $argv);
        }

        return HttpRequest::fromServer($_SERVER, $trustedProxies);
    }
}
