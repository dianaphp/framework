<?php

namespace Diana\Cache;

use DateInterval;
use DateTime;
use Diana\Cache\Exceptions\InvalidCacheKeyException;
use Diana\Config\Config;
use Diana\Contracts\Cache\Cache;
use Diana\Framework\Core\Application;
use InvalidArgumentException;
use RuntimeException;

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
        $this->validateExtension($extension, $this->getMetaExtension());
        $this->cacheExtension = $extension;
    }

    public function setMetaExtension(string $extension): void
    {
        $this->validateExtension($extension, $this->getCacheExtension());
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
        return $this->entryPath($this->getCacheDir(), $key, $this->getCacheExtension());
    }

    protected function getMetaFileName(string $key): string
    {
        return $this->entryPath($this->getMetaDir(), $key, $this->getMetaExtension());
    }

    protected function validateKey(mixed $key): void
    {
        if (!is_string($key) || $key === '' || preg_match('~[{}()/\\\\@:[:cntrl:]]~', $key)) {
            throw new InvalidCacheKeyException('Cache keys must be non-empty strings without reserved or control characters.');
        }
    }

    private function validateExtension(string $extension, string $other): void
    {
        if (!preg_match('/\A(?:\.[a-zA-Z0-9]+)+\z/', $extension) || $extension === $other) {
            throw new InvalidArgumentException('Cache extensions must be distinct dot-separated alphanumeric suffixes.');
        }
    }

    private function filePrefix(): string
    {
        return 'diana-' . hash('sha256', static::class . "\0" . $this->getCacheExtension() . "\0" . $this->getMetaExtension()) . '-';
    }

    private function entryPath(string $directory, string $key, string $extension): string
    {
        $this->validateKey($key);
        $path = $directory . DIRECTORY_SEPARATOR . $this->filePrefix() . hash('sha256', $key) . $extension;
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Cache entry must be a regular file, not a link or directory.');
        }
        return $path;
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
        // Check both paths before writing either file.
        $file = $this->getCacheFileName($key);
        $meta = $this->getMetaFileName($key);
        if ($expiration !== 0 && $expiration <= time()) {
            return $this->delete($key);
        }

        return file_put_contents($file, $this->encode($value)) !== false
            && file_put_contents($meta, (string) $expiration) !== false;
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
        $meta = $this->getMetaFileName($key);
        $file = $this->getCacheFileName($key);
        $success = true;
        if (file_exists($meta)) {
            $success = unlink($meta);
        }

        if (file_exists($file)) {
            $success = unlink($file) && $success;
        }
        return $success;
    }

    public function clear(): bool
    {
        $success = true;
        $directories = [];
        $directories[$this->getCacheDir()][] = $this->getCacheExtension();
        $directories[$this->getMetaDir()][] = $this->getMetaExtension();
        foreach ($directories as $directory => $extensions) {
            $files = scandir($directory);
            if ($files === false) {
                $success = false;
                continue;
            }
            $pattern = '/\A' . preg_quote($this->filePrefix(), '/') . '[a-f0-9]{64}(?:'
                . implode('|', array_map(fn ($suffix) => preg_quote($suffix, '/'), $extensions)) . ')\z/';
            foreach ($files as $file) {
                $path = $directory . DIRECTORY_SEPARATOR . $file;
                if (preg_match($pattern, $file) && !is_link($path) && is_file($path)) {
                    $success = unlink($path) && $success;
                }
            }
        }

        return $success;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = $this->validatedKeys($keys);
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }
        return $values;
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $entries = [];
        foreach ($values as $key => $value) {
            // PHP converts numeric string array keys to integers.
            $key = is_int($key) ? (string) $key : $key;
            $this->validateKey($key);
            $entries[] = [$key, $value];
        }
        $expiration = $this->calculateExpiration(new DateTime(), $ttl);
        $success = true;
        foreach ($entries as [$key, $value]) {
            $success = $this->setByTimestamp($key, $value, $expiration) && $success;
        }
        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keys = $this->validatedKeys($keys);
        $success = true;
        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }
        return $success;
    }

    private function validatedKeys(iterable $keys): array
    {
        $validated = [];
        foreach ($keys as $key) {
            $this->validateKey($key);
            $validated[] = $key;
        }
        return $validated;
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
