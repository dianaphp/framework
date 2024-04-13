<?php

namespace Diana\IO;

abstract class Request
{
    public function __construct(protected string $resource)
    {
    }

    public static function capture(): Request
    {
        if (php_sapi_name() === 'cli') {
            $argv = $_SERVER['argv'];
            array_shift($argv);
            $command = array_shift($argv);
            return new ConsoleRequest($command, $argv);
        } else {
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (substr($key, 0, 5) <> 'HTTP_')
                    continue;

                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            }

            $protocol = strtolower(strtok($_SERVER['SERVER_PROTOCOL'], '/'));

            return new HttpRequest($protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $headers);
        }
    }

    public function getResource()
    {
        return $this->resource;
    }
}