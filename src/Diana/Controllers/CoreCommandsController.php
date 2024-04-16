<?php

namespace Diana\Controllers;

use Diana\Routing\Attributes\Command;
use Diana\Support\Helpers\Filesystem;

class CoreCommandsController
{
    #[Command("cache-clear")]
    public function cacheClear()
    {
        $scandel = function (string $dir) use (&$scandel) {
            $dirs = [];

            foreach (glob($dir . '/*') as $file) {
                if (is_dir($file)) {
                    $scandel($file);
                    $dirs[] = $file;
                } else
                    unlink($file);
            }

            foreach ($dirs as $dir) {
                rmdir($dir);
            }
        };

        $scandel(Filesystem::absPath('./tmp'));

        return 'Cache has been cleared.';
    }
}