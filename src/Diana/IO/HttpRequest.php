<?php

namespace Diana\IO;

use Diana\Contracts\IO\HttpRequest as HttpRequestContract;
use Diana\IO\Traits\Headers;

class HttpRequest extends Request implements HttpRequestContract
{
    use Headers;

    protected ?string $route = null;
    protected ?string $host = null;
    protected ?string $query = null;
    protected array $queryParameters = [];
    protected ?string $protocol = null;

    public function __construct(
        string $url = '',
        protected string $method = 'GET',
        array $headers = []
    ) {
        $url = $url !== '' ? $url : ($_SERVER['REQUEST_URI'] ?? '/');

        if (($pos = strpos($url, "://")) !== false) {
            $this->protocol = substr($url, 0, $pos);
            $url = substr($url, $pos + 3);

            if (($pos = strpos($url, "/")) !== false) {
                $this->host = substr($url, 0, $pos);
                $url = substr($url, $pos);
            }
        } else {
            if (isset($_SERVER["SERVER_PROTOCOL"])) {
                $this->protocol = strtolower(strtok($_SERVER["SERVER_PROTOCOL"], "/"));
            }
        }

        if (!$this->host) {
            if (isset($_SERVER["HTTP_HOST"])) {
                $this->host = $_SERVER["HTTP_HOST"];
            }
        }

        if (($pos = strpos($url, "?")) !== false) {
            $this->query = substr($url, $pos + 1);
            parse_str($this->query, $this->queryParameters);

            $url = substr($url, 0, $pos);
        } else {
            $this->query = '';
        }

        $this->route = $url ?: '/';

        $this->headers = $headers;

        parent::__construct(
            $this->protocol . "://" . $this->host . $this->route . ($this->query !== '' ? "?" . $this->query : "")
        );
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getQueryParameters(): array
    {
        return $this->queryParameters;
    }

    public function getRoute(): string
    {
        return $this->route;
    }

    public function getDefaultStatusCode(): int
    {
        return 200;
    }
}
