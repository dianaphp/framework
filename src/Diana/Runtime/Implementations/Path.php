<?php

namespace Diana\Runtime\Implementations;

use Composer\Autoload\ClassLoader;

trait Path
{
    protected string $path;

    public function withPath(ClassLoader $classLoader)
    {
        $this->path = dirname($classLoader->findFile($this::class), 2);
        return $this;
    }

    /**
     * Gets the absolute path to the project.
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }
}