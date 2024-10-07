<?php

namespace Diana\Controllers;

use Diana\Router\Attributes\Command;
use Diana\Runtime\Application;

class CoreCommandsController
{
    #[Command("cache-clear")]
    public function cacheClear(Application $app)
    {
        // TODO: Outsource
        $scandel = function (string $dir) use (&$scandel) {
            $dirs = [];

            foreach (glob($dir . '/*') as $file) {
                if (is_dir($file)) {
                    $scandel($file);
                    $dirs[] = $file;
                } else {
                    unlink($file);
                }
            }

            foreach ($dirs as $dir) {
                rmdir($dir);
            }
        };

        $scandel($app->path('tmp'));

        return 'Cache has been cleared.';
    }
}
