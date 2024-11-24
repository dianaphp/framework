<?php

namespace Diana\Events;

class ShutdownEvent
{
    public function __construct(protected int $statusCode)
    {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
