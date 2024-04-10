<?php

namespace Diana\IO\Traits;

trait Headers
{
    protected array $headers;

    public function setHeader(string|array $header, mixed $value = null): void
    {
        if (is_array($header))
            $this->headers = [$header];
        else
            $this->headers[$header] = $value;
    }

    public function getHeader(string $header = null): mixed
    {
        return $header ? $this->header[$header] : $this->header;
    }
}