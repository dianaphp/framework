<?php

namespace Diana\IO;

use Diana\IO\Traits\Headers;
use JsonSerializable;

class Response
{
    use Headers;

    public function __construct(protected mixed $response = "", protected int $statusCode = 200, array $headers = [])
    {
        $this->headers = $headers;
    }

    public function setStatusCode(int $statusCode): static
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    public function __toString(): string
    {
        return match (true) {
            $this->response instanceof JsonSerializable || is_iterable($this->response) => json_encode($this->response),
            $this->response == null => '',
            default => $this->response
        };
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
