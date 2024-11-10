<?php

namespace Diana\Cache;

use Diana\Contracts\CacheContract;

class JsonFileCache extends FileCache implements CacheContract
{
    public function getCacheExtension(): string
    {
        return '.cache.json';
    }
}
