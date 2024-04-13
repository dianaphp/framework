<?php

namespace Diana\Runtime;

use Composer\Autoload\ClassLoader;

use Diana\Cache\Aliases;
use Diana\IO\Request;

use Diana\IO\Contracts\Kernel;

use Diana\Runtime\Contracts\Bootable;
use Diana\Runtime\Contracts\Configurable;
use Diana\Runtime\Contracts\HasPath;
use Diana\Runtime\Implementations\Boot;
use Diana\Runtime\Implementations\Config;
use Diana\Runtime\Implementations\Path;
use Diana\Support\Collection\Collection;
use Diana\Support\Helpers\Filesystem;

class Application extends Container implements Bootable, HasPath, Configurable
{
    use Boot, Config, Path;

    protected array $packages = [];

    protected array $controllers = [];

    protected function __construct(protected string $path, protected ClassLoader $classLoader)
    {
        $this->registerBindings();

        $this->provideAliases();

        Filesystem::setBasePath($path);

        $this->loadConfig();
    }

    protected array $caches = [];

    protected function provideAliases()
    {
        $this->caches[Aliases::class] = new Aliases($this);
        $this->caches[Aliases::class]->cache();
        $this->caches[Aliases::class]->provide();
    }

    public static function make(string $path, ClassLoader $classLoader): static
    {
        // initializes the application
        $app = new static($path, $classLoader);

        // initializes all packages
        $app->registerPackage(\AppPackage::class);

        // boots all packages
        $app->performBoot($app);

        return $app;
    }

    protected function registerBindings(): void
    {
        static::setInstance($this);
        $this->instance(Application::class, $this);
        $this->instance(Container::class, $this);
        $this->instance(ClassLoader::class, $this->classLoader);
    }

    public function registerPackage(...$classes): void
    {
        foreach ((new Collection($classes))->flat() as $class) {
            if (in_array($class, $this->packages))
                continue;

            $this->packages[] = $class;

            $this->singleton($class);
            $package = $this->resolve($class)->withPath($this->classLoader);

            if ($this->hasBooted())
                $package->performBoot($this);
        }
    }

    public function registerController(...$controllers): void
    {
        foreach ((new Collection($controllers))->flat() as $controller) {
            if (!in_array($controller, $this->controllers))
                $this->controllers[] = $controller;
        }
    }

    public function boot(): void
    {
        foreach ($this->packages as $package)
            $this->resolve($package)->performBoot($this);
    }

    public function handleRequest(Request $request): void
    {
        $kernel = $this->resolve(Kernel::class);

        $response = $kernel->run($request);
        $response->emit();

        $kernel->terminate($request, $response);
    }

    public function getControllers()
    {
        return $this->controllers;
    }

    public function getPackages()
    {
        return $this->packages;
    }


    // TODO: BLADE
    protected array $terminatingCallbacks = [];

    public function terminating(\Closure $callback)
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }

    public function terminate()
    {
        foreach ($this->terminatingCallbacks as $terminatingCallback) {
            $terminatingCallback();
        }
    }
}