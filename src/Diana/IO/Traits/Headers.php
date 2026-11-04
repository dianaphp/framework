<?php

namespace Diana\IO\Traits;

trait Headers
{
    protected array $headers = [];

    public function setHeader(string|array $header, mixed $value = null): void
    {
        if (is_array($header)) {
            $this->headers = [];
            foreach ($header as $name => $value) {
                $this->setHeader($name, $value);
            }
            return;
        }

        foreach ($this->headers as $name => $_) {
            if (strcasecmp($name, $header) === 0) {
                $this->headers[$name] = $value;
                return;
            }
        }
        $this->headers[$header] = $value;
    }

    public function getHeader(?string $header = null): mixed
    {
        if ($header === null) {
            return $this->headers;
        }
        foreach ($this->headers as $name => $value) {
            if (strcasecmp($name, $header) === 0) {
                return $value;
            }
        }
        return null;
    }
}
