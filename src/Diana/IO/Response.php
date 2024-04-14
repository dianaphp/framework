<?php

namespace Diana\IO;

use Diana\IO\Traits\Headers;
use JsonSerializable;

class Response
{
    use Headers;

    public function __construct(protected mixed $response = "", protected int $errorCode = 200, array $headers = [])
    {
        $this->headers = $headers;
        http_response_code($this->errorCode);
    }

    public function setErrorCode(int $errorCode): void
    {
        http_response_code($this->errorCode = $errorCode);
    }

    public function emit(): void
    {
        echo (string) $this;
    }

    public function __toString(): string
    {
        return match (true) {
            $this->response instanceof JsonSerializable || is_iterable($this->response) => json_encode($this->response),
            $this->response == null => '',
            default => $this->response
        };
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }
}