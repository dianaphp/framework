<?php

namespace Diana\IO;

use Closure;
use Composer\Autoload\ClassLoader;
use Diana\IO\Request;
use Diana\IO\Response;
use Diana\IO\Contracts\Kernel as KernelContract;
use Diana\Routing\Contracts\Router;
use Diana\Routing\Exceptions\CommandNotFoundException;
use Diana\Routing\Exceptions\RouteNotFoundException;
use Diana\Routing\Exceptions\UnsupportedRequestTypeException;
use Diana\Runtime\Container;
use Diana\Runtime\Contracts\Bootable;
use Diana\Runtime\Implementations\Boot;
use Diana\Support\Collection\Collection;
use Diana\Support\Helpers\Filesystem;
use Diana\IO\Pipeline;

class Kernel implements KernelContract, Bootable
{
    use Boot;

    protected array $packages = [];

    protected array $controllers = [];

    protected array $middleware = [];

    protected Pipeline $pipeline;

    public function registerMiddleware(string|Closure $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function __construct(protected Container $container, protected ClassLoader $classLoader)
    {
    }

    public function handle(Request $request): Response
    {
        $this->boot();

        $router = $this->container->resolve(Router::class);
        $router->load(Filesystem::absPath("./cache/routes.php"), fn() => $app->getControllers());

        $this->container->instance(Request::class, $request);

        return (new Pipeline($this->container))
            ->send($request)
            ->pipe($this->middleware)
            ->pipe(function (Request $request) use ($router) {
                try {
                    if ($request instanceof HttpRequest)
                        $resolution = $router->findRoute($request->getRoute(), $request->getMethod());
                    elseif ($request instanceof ConsoleRequest) {
                        $resolution = $router->findCommand($request->getCommand(), $request->args->getAll());
                    } else
                        throw new UnsupportedRequestTypeException("The provided request type is not being supported by the router.");
                } catch (RouteNotFoundException | CommandNotFoundException $e) {
                    return new Response("404");
                }

                return (new Pipeline($this->container))
                    ->send($request, $resolution)
                    ->pipe($resolution['middleware'])
                    ->pipe(function () use ($resolution) {
                        return new Response($this->container->call($resolution['controller'] . '@' . $resolution['method'], $resolution['params']));
                    })
                    ->expect(Response::class);
            })
            ->expect(Response::class);
    }

    public function registerPackage(...$classes): void
    {
        $classes = (new Collection($classes))->flat();
        foreach ($classes as $class) {
            if (in_array($class, $this->packages))
                continue;

            $this->packages[] = $class;

            $this->container->instance($class, $package = $this->container->resolve($class)->withPath($this->classLoader));

            if ($this->hasBooted())
                $package->performBoot($this->container);
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
        $this->registerPackage(\App\AppPackage::class);

        $this->booted = true;

        foreach ($this->packages as $package)
            $this->container->resolve($package)->performBoot($this->container);
    }

    public function terminate(Request $request, Response $response): void
    {
        foreach ($this->terminatingCallbacks as $terminatingCallback) {
            $terminatingCallback();
        }
    }

    protected array $terminatingCallbacks = [];

    public function terminating(Closure $callback)
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }
}