<?php

namespace Diana\Runtime;

use Closure;
use Composer\Autoload\ClassLoader;
use Diana\Config\FileConfig;
use Diana\Drivers\ContainerInterface;
use Diana\Drivers\Routing\RequestInterface;
use Diana\IO\ConsoleRequest;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\IO\Request;
use Diana\IO\Response;
use Diana\Drivers\ConfigInterface;
use Illuminate\Contracts\Container\BindingResolutionException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Application extends Package
{
    /**
     * @var array The currently loaded package classes
     */
    protected array $packages = [];

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(
        protected string $path,
        protected string $output,
        protected string $sapi,
        protected ClassLoader $loader,
        protected ContainerInterface $container = new ContainerProxy()
    ) {
        $this->registerBindings();
        $this->registerPackage(Kernel::class);
    }

    public function boot(?ContainerInterface $container = null): void
    {
        parent::boot($container ?? $this->container);
    }

    protected function registerBindings(): void
    {
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Application::class, $this);
        $this->container->instance(ClassLoader::class, $this->loader);

        // register a default driver for the configuration, this is important because the kernel needs configuration
        $this->container->singleton(ConfigInterface::class, FileConfig::class);
//        $this->container->singleton(ConfigInterface::class, NullProxy::class);

        // TODO: contextual binding based on $sapi, check capture method
        $this->container->instance(RequestInterface::class, Request::capture());
    }

    /**
     * Initiates the application lifecycle
     * @throws NotFoundExceptionInterface
     * @throws UnexpectedOutputTypeException
     * @throws ContainerExceptionInterface
     * @throws BindingResolutionException
     */
    public function init(): void
    {
        $status = null;
        $buffer = fopen($this->output, 'a');

        $this->booted = true;

        foreach ($this->packages as $package) {
            $this->container->get($package)->boot($this->container);
        }

        try {
            $response = $this->container->get(Kernel::class)
                ->handleRequest($this->container->get(RequestInterface::class));

            fwrite($buffer, $response);

            $status = $response->getErrorCode();
        } finally {
            fclose($buffer);

            $this->terminate($status);
        }
    }

    public function getSapi(): string
    {
        return $this->sapi;
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws UnexpectedOutputTypeException
     * @throws BindingResolutionException
     */
    public function runCommand(string $command): Response
    {
        $args = explode(' ', $command);
        $command = array_shift($args);
        return $this->container->get(Kernel::class)->handle(new ConsoleRequest($command, $args));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function registerPackage(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (in_array($class, $this->packages)) {
                continue;
            }

            $this->packages[] = $class;

            $this->container->singleton($class);
            $package = $this->container->get($class);

            if ($this->hasBooted()) {
                $package->boot($this->container);
            }
        }
    }

    protected array $terminatingCallbacks = [];
    public function terminating(Closure $callback): Application
    {
        $this->terminatingCallbacks[] = $callback;
        return $this;
    }

    public function terminate(?int $status = null): void
    {
        foreach ($this->terminatingCallbacks as $terminatingCallback) {
            $terminatingCallback();
        }

        if ($status !== null) {
            exit($status);
        }
    }
}
