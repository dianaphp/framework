<?php

namespace Diana\Cache\Exceptions;

use Exception;
use Throwable;

class CacheException extends Exception implements Throwable
{
    public function __construct(protected string $key, protected object $source)
    {
        parent::__construct("Cache key [$key] not found");
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getSource(): object
    {
        return $this->source;
    }
}
