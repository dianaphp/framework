<?php

namespace Diana\IO;

use Diana\IO\Traits\Headers;
use JsonSerializable;

class Response
{
    use Headers;

    public function __construct(protected string $response = "", array $headers = [])
    {
        $this->headers = $headers;
    }

    public function emit(): void
    {
        echo (string) $this;
    }

    public function __toString(): string
    {
        return $this->response;
    }

    public function set($response): void
    {
        $this->response = $this->stringify($response);
    }

    public function append($input): void
    {
        $this->response .= $this->stringify($input);
    }

    private function stringify(mixed $input)
    {
        return match (true) {
            $input instanceof JsonSerializable || is_iterable($input) => json_encode($input),
            default => $input
        };
    }

}