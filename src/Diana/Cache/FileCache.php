<?php

namespace Diana\Cache;

use Diana\Contracts\CacheContract;
use Diana\Contracts\ConfigContract;
use Diana\Runtime\Attributes\Config;
use Diana\Runtime\Framework;

class FileCache implements CacheContract
{
    protected string $extension = '.cache';

    public function __construct(
        #[Config('cfg/framework')] protected ConfigContract $config,
        protected Framework $app
    ) {
    }

    public function setExtension(string $extension): void
    {
        $this->extension = $extension;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    protected function getFileName(string $key): string
    {
        return $this->app->path(join(DIRECTORY_SEPARATOR, [
            $this->config->get('cachePath'),
            $key . $this->getExtension()
        ]));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // todo: implement ttl
        $content = file_get_contents($this->getFileName($key));
        return $content === false ? $default : $content;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        // todo: implement ttl
        return (bool)file_put_contents($this->getFileName($key), $value);
    }

    public function delete(string $key): bool
    {
        return unlink($this->getFileName($key));
    }

    public function clear(): bool
    {
        // todo: clear entire folder
        // $files = glob($this->config->get('cachePath') . '/*');
        return false;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        // TODO: Implement getMultiple() method.
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        // TODO: Implement setMultiple() method.
    }

    public function deleteMultiple(iterable $keys): bool
    {
        // TODO: Implement deleteMultiple() method.
    }

    public function has(string $key): bool
    {
        return file_exists($this->getFileName($key));
    }
}
