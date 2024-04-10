<?php

namespace Diana\IO;

use Diana\IO\Traits\Headers;
use JsonSerializable;

class Response
{
    use Headers;

    public function __construct(protected $response = "", array $headers = [])
    {
        $this->headers = $headers;
    }

    public function emit(): void
    {
        echo (string) $this;
    }

    public function __toString(): string
    {
        $response = match (true) {
            $this->response instanceof JsonSerializable || is_iterable($this->response) => json_encode($this->response),
            default => $this->response
        };

        return (string) $response;
    }

    public function set($response): void
    {
        $this->response = $response;
    }

}