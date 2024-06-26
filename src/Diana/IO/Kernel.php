<?php

namespace Diana\IO;

use Closure;
use Composer\Autoload\ClassLoader;
use Diana\IO\Request;
use Diana\IO\Response;
use Diana\IO\Contracts\Kernel as KernelContract;
use Diana\Routing\Contracts\Router;
use Diana\Controllers\CoreCommandsController;
use Diana\Controllers\StubCommandsController;
use Diana\Routing\Exceptions\CommandNotFoundException;
use Diana\Routing\Exceptions\RouteNotFoundException;
use Diana\Routing\Exceptions\UnsupportedRequestTypeException;
use Diana\Runtime\Application;
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

    protected array $controllers = [
        CoreCommandsController::class,
        StubCommandsController::class
    ];

    protected array $middleware = [];

    protected Pipeline $pipeline;

    public function registerMiddleware(string|Closure $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function __construct(protected Application $app, protected Container $container, protected ClassLoader $classLoader)
    {

    }

    public function runCommand(string $command)
    {
        $args = explode(' ', $command);
        $command = array_shift($args);
        return $this->handle(new ConsoleRequest($command, $args));
    }

    public function boot(Request $request, string $entryPoint, string $routeCachePath)
    {
        $this->container->instance(Request::class, $request);

        $this->registerPackage($entryPoint);

        $router = $this->container->resolve(Router::class);
        $router->load(Filesystem::absPath($routeCachePath), fn() => $this->controllers);

        $this->booted = true;

        foreach ($this->packages as $package)
            $this->container->resolve($package)->performBoot($this->container);
    }

    public function handle(Request $request): Response
    {
        $router = $this->container->resolve(Router::class);

        return (new Pipeline($this->container))
            ->send($request)
            ->pipe($this->middleware)
            ->pipe(function (Request $request) use ($router) {
                try {
                    $resolution = $router->resolve($request);
                } catch (RouteNotFoundException $e) {
                    if (!$resolution = $router->getErrorRouteHandler())
                        return new Response("HTTP Error 404 - This page could not be found.", 404);
                    else
                        $resolution['params']['errorCode'] = 404;
                } catch (CommandNotFoundException $e) {
                    if (!$resolution = $router->getErrorCommandHandler())
                        return new Response("This command could not be found.", 1);
                    else
                        $resolution['params']['errorCode'] = 1;
                }

                return (new Pipeline($this->container))
                    ->send($request, $resolution)
                    ->pipe($resolution['middleware'])
                    ->pipe(function () use ($resolution) {
                        return new Response($this->container->call($resolution['controller'] . '@' . $resolution['method'], $resolution['params']), $resolution['params']['errorCode'] ?? (php_sapi_name() == 'cli' ? 0 : 200));
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

            $this->container->instance($class, $package = $this->container->resolve($class));
            $this->app->getPaths()[$class] = realpath(dirname($this->classLoader->findFile($class), 2));

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

    public function terminate(Request $request, Response $response): void
    {
        foreach ($this->terminatingCallbacks as $terminatingCallback)
            $terminatingCallback();

        exit($response->getErrorCode());
    }

    protected array $terminatingCallbacks = [];

    public function terminating(Closure $callback)
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }
}