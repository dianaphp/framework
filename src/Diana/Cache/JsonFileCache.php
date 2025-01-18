<?php

namespace Diana\Cache;

use Diana\Contracts\Cache\Cache;

class JsonFileCache extends FileCache implements Cache
{
    public function getCacheExtension(): string
    {
        return '.cache.json';
    }
}
