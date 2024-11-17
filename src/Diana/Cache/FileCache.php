<?php

namespace Diana\Cache;

use DateInterval;
use DateMalformedIntervalStringException;
use DateTime;
use Diana\Contracts\CacheContract;
use Diana\Contracts\ConfigContract;
use Diana\Runtime\Attributes\Config;
use Diana\Runtime\Framework;
use Diana\Support\Wrapper\ArrayWrapper;

class FileCache implements CacheContract
{
    protected string $cacheExtension = '.cache.txt';
    protected string $metaExtension = '.meta.txt';

    public function __construct(
        #[Config('cfg/framework')] protected ConfigContract $config,
        protected Framework $app
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
        return $content !== false ? $content : $default;
    }

    public function setByTimestamp(string $key, mixed $value, int $expiration): bool
    {
        return
            file_put_contents($this->getCacheFileName($key), $value) &&
            file_put_contents($this->getMetaFileName($key), $expiration);
    }

    /**
     * @throws DateMalformedIntervalStringException
     */
    public function calculateExpiration(DateTime $dateTime, int|DateInterval|null $ttl): int
    {
        if (!$ttl) {
            return 0;
        }

        if (!$ttl instanceof DateInterval) {
            $ttl = new DateInterval('P' . (int)$ttl . 'S');
        }

        return (clone $dateTime)->add($ttl)->getTimestamp();
    }

    /**
     * @throws DateMalformedIntervalStringException
     */
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
            $success = $success && unlink($this->getCacheFileName($key));
        }
        return $success;
    }

    public function clear(): bool
    {
        $success = true;
        arr(scandir($this->getCacheDir()))
            ->diff(['.', '..'])
            ->filter(fn ($file) => is_file($this->getCacheDir() . DIRECTORY_SEPARATOR . $file))
            ->each(function ($file) use (&$success) {
                $success = $success && unlink($this->getCacheDir() . DIRECTORY_SEPARATOR . $file);
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

    /**
     * @throws DateMalformedIntervalStringException
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $expiration = $this->calculateExpiration(new DateTime(), $ttl);
        $success = true;
        foreach ($values as $key => $value) {
            $success = $success && $this->setByTimestamp($key, $value, $expiration);
        }
        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $success = $success && $this->delete($key);
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

        if (!is_numeric($meta) || (int)$meta != 0 && time() > (int)$meta) {
            $this->delete($key);
            return false;
        }

        return file_exists($this->getCacheFileName($key));
    }
}
