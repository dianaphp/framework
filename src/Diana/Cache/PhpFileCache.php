<?php

namespace Diana\Cache;

use Diana\Contracts\CacheContract;
use Diana\Support\Exceptions\FileNotFoundException;
use Diana\Support\Helpers\Filesystem;
use Diana\Support\Serializer\ArraySerializer;

class PhpFileCache extends FileCache implements CacheContract
{
    public function getCacheExtension(): string
    {
        return '.cache.php';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            return Filesystem::getRequire($this->getCacheFileName($key));
        } catch (FileNotFoundException) {
            return $default;
        }
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $value = ArraySerializer::serialize($value);
        return parent::set($key, $value, $ttl);
    }
}