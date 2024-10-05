<?php

namespace Diana\Runtime;

use Closure;
use Composer\Autoload\ClassLoader;
use Diana\IO\ConsoleRequest;
use Diana\IO\Exceptions\PipelineException;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\IO\Request;
use Diana\IO\Response;
use Illuminate\Container\Container;
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
        protected Container $container,
    ) {
        $this->registerBindings();

        $this->registerPackage(Kernel::class);
    }

    public function registerBindings(): void
    {
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Application::class, $this);
        $this->container->instance(ClassLoader::class, $this->loader);

        // TODO: do we need this?
        // TODO: contextual binding based on $sapi, check capture method
        $this->container->instance(Request::class, Request::capture());
    }

    /**
     * Initiates the application lifecycle
     * @throws NotFoundExceptionInterface
     * @throws PipelineException
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
                ->handleRequest($this->container->get(Request::class));

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
     * @throws PipelineException
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
