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

        $parts = parse_url($url);
        if ($parts === false || (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true))) {
            throw new \InvalidArgumentException('Invalid HTTP URL: ' . $url);
        }

        [$protocol, $host] = self::serverOrigin($_SERVER);
        $this->protocol = strtolower($parts['scheme'] ?? $protocol);
        $this->host = $parts['host'] ?? $host;
        if (isset($parts['port'])) {
            $this->host .= ':' . $parts['port'];
        }
        $this->route = '/' . ltrim($parts['path'] ?? '', '/');
        $this->query = $parts['query'] ?? '';
        parse_str($this->query, $this->queryParameters);

        $this->headers = $headers;

        parent::__construct(
            $this->protocol . "://" . $this->host . $this->route . ($this->query !== '' ? "?" . $this->query : "")
        );
    }

    /**
     * Capture HTTP server data. Trusted proxies are exact REMOTE_ADDR IP addresses.
     * They must overwrite X-Forwarded-Proto and X-Forwarded-Host at the boundary.
     */
    public static function fromServer(array $server, array $trustedProxies = []): self
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = $key;
            } else {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower($name))));
            $headers[$name] = $value;
        }

        [$protocol, $host] = self::serverOrigin($server);
        if (isset($server['REMOTE_ADDR']) && in_array($server['REMOTE_ADDR'], $trustedProxies, true)) {
            $forwardedProtocol = strtolower(trim($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if (in_array($forwardedProtocol, ['http', 'https'], true)) {
                $protocol = $forwardedProtocol;
            }
            $forwardedHost = trim($server['HTTP_X_FORWARDED_HOST'] ?? '');
            if ($forwardedHost !== '' && preg_match('/^[a-z0-9.\[\]:-]+$/iD', $forwardedHost)) {
                $host = $forwardedHost;
            }
        }

        // REQUEST_URI supplies the request target, never the origin.
        $target = parse_url($server['REQUEST_URI'] ?? '/');
        if ($target === false) {
            throw new \InvalidArgumentException('Invalid request URI');
        }
        $path = '/' . ltrim($target['path'] ?? '', '/');
        $query = isset($target['query']) ? '?' . $target['query'] : '';
        return new self($protocol . '://' . $host . $path . $query, $server['REQUEST_METHOD'] ?? 'GET', $headers);
    }

    private static function serverOrigin(array $server): array
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        $protocol = ($https !== '' && $https !== 'off' && $https !== '0')
            || (string) ($server['SERVER_PORT'] ?? '') === '443' ? 'https' : 'http';
        $host = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost';
        return [$protocol, $host !== '' ? $host : 'localhost'];
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
