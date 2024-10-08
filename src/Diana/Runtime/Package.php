<?php

namespace Diana\Runtime;

use Diana\Drivers\ContainerInterface;
use RuntimeException;

abstract class Package
{
    protected bool $booted = false;
    protected string $path;

    public function boot(?ContainerInterface $container): void
    {
        if ($this->hasBooted()) {
            throw new RuntimeException('The runtime [' . get_class($this) . '] has already been booted.');
        }

        if (method_exists($this, 'init')) {
            if ($container) {
                $container->call([$this, 'init']);
            } else {
                $this->init();
            }
        }

        $this->booted = true;
    }

    public function path(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $path = trim($path, DIRECTORY_SEPARATOR);
        $slugs = explode(DIRECTORY_SEPARATOR, $path);
        array_splice($slugs, 0, 0, $this->path);
        return join(DIRECTORY_SEPARATOR, $slugs);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function hasBooted(): bool
    {
        return $this->booted;
    }
}
