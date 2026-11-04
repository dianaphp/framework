<?php

namespace Diana\Cache;

use DateInterval;
use DateTime;
use Diana\Config\Config;
use Diana\Contracts\Cache\Cache;
use Diana\Framework\Core\Application;

class FileCache implements Cache
{
    private const SERIALIZED_PREFIX = "DIANA-CACHE-SERIALIZED\n";

    protected string $cacheExtension = '.cache.txt';
    protected string $metaExtension = '.meta.txt';

    public function __construct(
        #[Config('framework')] protected Config $config,
        protected Application $app
    ) {
    }

    public function setCacheExtension(string $extension): void
    {
        $this->cacheExtension = $extension;
    }

    public function setMetaExtension(string $extension): void
    {
        $this->metaExtension = $extension;
    }

    public function getCacheExtension(): string
    {
        return $this->cacheExtension;
    }

    public function getMetaExtension(): string
    {
        return $this->metaExtension;
    }

    public function getCacheDir(): string
    {
        return $this->app->path($this->config->get('cachePath'));
    }

    public function getMetaDir(): string
    {
        return $this->getCacheDir();
    }

    protected function getCacheFileName(string $key): string
    {
        return $this->getCacheDir() . DIRECTORY_SEPARATOR . $key . $this->getCacheExtension();
    }

    protected function getMetaFileName(string $key): string
    {
        return $this->getMetaDir() . DIRECTORY_SEPARATOR . $key . $this->getMetaExtension();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        $content = file_get_contents($this->getCacheFileName($key));
        return $content !== false ? $this->decode($content) : $default;
    }

    protected function encode(mixed $value): string
    {
        // Keep ordinary strings verbatim: IDE alias files use this driver too.
        if (is_string($value) && !str_starts_with($value, self::SERIALIZED_PREFIX)) {
            return $value;
        }

        return self::SERIALIZED_PREFIX . serialize($value);
    }

    protected function decode(string $content): mixed
    {
        return str_starts_with($content, self::SERIALIZED_PREFIX)
            ? unserialize(substr($content, strlen(self::SERIALIZED_PREFIX)))
            : $content;
    }

    public function setByTimestamp(string $key, mixed $value, int $expiration): bool
    {
        if ($expiration !== 0 && $expiration <= time()) {
            return $this->delete($key);
        }

        return file_put_contents($this->getCacheFileName($key), $this->encode($value)) !== false
            && file_put_contents($this->getMetaFileName($key), (string) $expiration) !== false;
    }

    public function calculateExpiration(DateTime $dateTime, int|DateInterval|null $ttl): int
    {
        if ($ttl === null) {
            return 0;
        }

        return $ttl instanceof DateInterval
            ? (clone $dateTime)->add($ttl)->getTimestamp()
            : $dateTime->getTimestamp() + $ttl;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $expiration = $this->calculateExpiration(new DateTime(), $ttl);
        return $this->setByTimestamp($key, $value, $expiration);
    }

    public function delete(string $key): bool
    {
        $success = true;
        if (file_exists($this->getMetaFileName($key))) {
            $success = unlink($this->getMetaFileName($key));
        }

        if (file_exists($this->getCacheFileName($key))) {
            $success = unlink($this->getCacheFileName($key)) && $success;
        }
        return $success;
    }

    public function clear(): bool
    {
        $success = true;
        arr(scandir($this->getCacheDir()))
            ->diff(['.', '..'])
            ->filter(fn($file) => is_file($this->getCacheDir() . DIRECTORY_SEPARATOR . $file))
            ->each(function ($file) use (&$success) {
                $success = unlink($this->getCacheDir() . DIRECTORY_SEPARATOR . $file) && $success;
            });

        return $success;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }
        return $values;
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $expiration = $this->calculateExpiration(new DateTime(), $ttl);
        $success = true;
        foreach ($values as $key => $value) {
            $success = $this->setByTimestamp($key, $value, $expiration) && $success;
        }
        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }
        return $success;
    }

    public function has(string $key): bool
    {
        if (!file_exists($this->getMetaFileName($key))) {
            return false;
        }

        $meta = file_get_contents($this->getMetaFileName($key));
        if ($meta === false) {
            return false;
        }

        if (!is_numeric($meta) || (int)$meta != 0 && time() >= (int)$meta) {
            $this->delete($key);
            return false;
        }

        return file_exists($this->getCacheFileName($key));
    }
}
