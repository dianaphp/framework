<?php

namespace Diana\Cache;

use Diana\Support\Exceptions\FileNotFoundException;
use Diana\Support\Helpers\Filesystem;

class PhpFileCache extends FileCache
{
    public function getCacheExtension(): string
    {
        return '.cache.php';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        try {
            return Filesystem::getRequire($this->getCacheFileName($key));
        } catch (FileNotFoundException) {
            return $default;
        }
    }

    protected function encode(mixed $value): string
    {
        return "<?php\nreturn unserialize(" . var_export(serialize($value), true) . ");\n";
    }
}
