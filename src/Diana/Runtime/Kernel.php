<?php

namespace Diana\Runtime;

use Closure;
use Diana\Controllers\CoreCommandsController;
use Diana\Controllers\StubCommandsController;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\IO\Pipeline;
use Diana\IO\Request;
use Diana\IO\Response;
use Diana\Router\Exceptions\CommandNotFoundException;
use Diana\Router\Exceptions\RouteNotFoundException;
use Diana\Runtime\KernelModules\ConfigurePhp;
use Diana\Runtime\KernelModules\ProvideAliases;
use Diana\Runtime\KernelModules\RegisterBindings;
use Diana\Runtime\KernelModules\RegisterExceptionHandler;
use Diana\Support\Exceptions\FileNotFoundException;
use Diana\Runtime\Attributes\Config;
use Diana\Drivers\ConfigInterface;
use Diana\Drivers\Routing\RouteInterface;
use Diana\Drivers\Routing\RouterInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Kernel extends Package
{
    /**
     * @var array The currently loaded controllers
     */
    protected array $controllers = [
        CoreCommandsController::class,
        StubCommandsController::class
    ];

    /**
     * @var array The kernel modules to be loaded
     */
    protected array $kernelModules = [
        ConfigurePhp::class,
        RegisterExceptionHandler::class,
        ProvideAliases::class,
        RegisterBindings::class
    ];

    /*
     * @var array The global middleware stack
     */
    protected array $middleware = [];

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws FileNotFoundException
     */
    public function __construct(
        protected Container $container,
        protected Application $app,
        #[Config('framework')] protected ConfigInterface $config
    ) {
        $this->path = dirname(__DIR__, 3);
        $this->config->setDefault($this->getDefaultConfig());

        foreach ($this->kernelModules as $kernelModule) {
            $this->container->make($kernelModule)->init();
        }

        $this->app->registerPackage($this->config->get('entryPoint'));
    }

    protected function getDefaultConfig(): array
    {
        return [
            'aliasCachePath' => 'tmp/aliases.php',
            'aliases' => [],
            'bindings' => [
                \Diana\Drivers\ConfigInterface::class => \Diana\Config\FileConfig::class,
                \Diana\Drivers\EventInterface::class => \Diana\Proxies\NullProxy::class,
                \Diana\Drivers\Routing\RouterInterface::class => \Diana\Router\FileRouter::class,
                \Diana\Drivers\Routing\RouteInterface::class => \Diana\Router\Route::class,
                \Diana\Drivers\RendererInterface::class => \Diana\Rendering\Drivers\BladeRenderer::class
            ],
            'entryPoint' => '\App\AppPackage',
            'env' => 'dev',
            'logs' => [
                'error' => 'logs/error.log',
                'access' => 'logs/access.log'
            ],
            'timezone' => 'Europe/Berlin'
        ];
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws UnexpectedOutputTypeException
     * @throws ContainerExceptionInterface
     * @throws BindingResolutionException
     */
    public function handleRequest(Request $request): Response
    {
        /** @var RouterInterface $router */
        $router = $this->container->get(RouterInterface::class);

        try {
            $route = $router->resolve($request);
            $statusCode = $this->app->getSapi() == 'cli' ? 0 : 200;
        } catch (RouteNotFoundException) {
            $statusCode = 404;
            $route = $router->getErrorRoute();
            if (!$route) {
                // TODO: use the default renderer to render an http error
                // RendererInterface::class => BlankRenderer::class
                return new Response("HTTP Error 404 - This page could not be found.", $statusCode);
            }
        } catch (CommandNotFoundException) {
            $statusCode = 1;
            $route = $router->getErrorCommandRoute();
            if (!$route) {
                return new Response("This command could not be found.", $statusCode);
            }
        }

        $this->container->instance(RouteInterface::class, $route);

        return $this->container->make(Pipeline::class)
            ->pipe($this->middleware) // global middleware
            ->pipe($route->getMiddleware()) // route middleware
            ->pipe(function () use ($route, $statusCode) {
                return new Response(
                    $this->container->call(
                        $route->getController() . '@' . $route->getMethod(),
                        $route->getParameters()
                    ),
                    $statusCode
                );
            })
            ->expect(Response::class);
    }

    public function registerMiddleware(string|Closure $middleware): void
    {
        $this->middleware[] = $middleware;
    }
}
