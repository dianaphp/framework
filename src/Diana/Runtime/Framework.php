<?php

namespace Diana\Runtime;

use Closure;
use Composer\Autoload\ClassLoader;
use Diana\Controllers\CoreCommandsController;
use Diana\Controllers\StubCommandsController;
use Diana\Drivers\ContainerInterface;
use Diana\Drivers\EventInterface;
use Diana\Drivers\EventManagerInterface;
use Diana\Drivers\RequestInterface;
use Diana\Drivers\RouteInterface;
use Diana\Drivers\RouterInterface;
use Diana\IO\ConsoleRequest;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\IO\Pipeline;
use Diana\IO\Request;
use Diana\IO\Response;
use Diana\Drivers\ConfigInterface;
use Diana\Router\Exceptions\CommandNotFoundException;
use Diana\Router\Exceptions\RouteNotFoundException;
use Diana\Support\Helpers\Data;
use Illuminate\Contracts\Container\BindingResolutionException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Framework
{
    protected string $frameworkPath;

    protected ConfigInterface $config;
    protected EventManagerInterface $eventManager;
    protected EventInterface $event;
    protected ContainerInterface $container;

    protected array $middleware = [];

    protected array $modules = [];

    protected array $packages;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(
        protected string $appPath,
        protected ClassLoader $loader,
        Closure|ConfigInterface $config
    ) {
        $this->frameworkPath = dirname(__DIR__, 3);

        $this->loadConfig($config);
        $this->instantiateContainer();
        $this->registerBindings();
        //$this->setupLogger();
        //$this->setupCache();
        $this->setupEvents();
        $this->runModules();

        $this->registerPackage($this->config->get('entryPoint'));
    }

    protected function loadConfig(Closure|ConfigInterface $config): void
    {
        $this->config = Data::valueOf($config, $this);
        $this->config->setDefault($this->getDefaultConfig());
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function setupEvents(): void
    {
        $this->eventManager = $this->container->get(EventManagerInterface::class);
        $this->event = $this->container->make(EventInterface::class, [
            'class' => static::class
        ]);
    }

    protected function instantiateContainer(): void
    {
        $bindings = $this->config->get('bindings');
        $containerClass = $bindings[ContainerInterface::class];
        $this->container = new $containerClass();
    }

    protected function registerBindings(): void
    {
        // it is crucial to register the user defined bindings first, as they might override core bindings
        foreach ($this->config->get('bindings') as $abstract => $concrete) {
            $this->container->singleton($abstract, $concrete);
        }

        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Framework::class, $this);
        $this->container->instance(ClassLoader::class, $this->loader);

        // TODO: contextual binding based on $sapi, check capture method
        $this->container->instance(RequestInterface::class, Request::capture());
    }

    public function runModules(): void
    {
        foreach ($this->modules as $module) {
            $this->container->make($module)();
        }
    }

    public function registerPackage(string $package, bool $force = false): void
    {
        if (!$force && isset($this->packages[$package])) {
            return;
        }

        $instance = $this->container->make($package);
        $this->container->instance($package, $instance);

        $this->event->fire(
            'registerPackage',
            compact('package', 'instance')
        );
    }

    /**
     * Initiates the application lifecycle
     * @throws NotFoundExceptionInterface
     * @throws UnexpectedOutputTypeException
     * @throws ContainerExceptionInterface
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $status = null;
        $buffer = fopen($this->config->get('output'), 'a');

        try {
            $request = $this->container->get(RequestInterface::class);
            $response = $this->handleRequest($request);

            fwrite($buffer, $response);

            $status = $response->getErrorCode();
        } finally {
            fclose($buffer);

            $this->terminate($status);
        }
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws UnexpectedOutputTypeException
     */
    public function handleRequest(Request $request): Response
    {
        $router = $this->container->get(RouterInterface::class);

        try {
            $route = $router->resolve($request);
            $statusCode = $this->config->get('sapi') == 'cli' ? 0 : 200;
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
        return $this->handleRequest(new ConsoleRequest($command, $args));
    }

    protected array $terminatingCallbacks = [];
    public function terminating(Closure $callback): Framework
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

    public function path(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $path = trim($path, DIRECTORY_SEPARATOR);
        $slugs = explode(DIRECTORY_SEPARATOR, $path);
        array_splice($slugs, 0, 0, $this->appPath);
        return join(DIRECTORY_SEPARATOR, $slugs);
    }

    protected function getDefaultConfig(): array
    {
        return [
            'output' => 'php://stdout',
            'sapi' => PHP_SAPI, // TODO: outsource to env.php
            'aliasCachePath' => 'tmp/aliases.php',
            'packages' => [
                CoreCommandsController::class,
                StubCommandsController::class,
                \App\AppController::class
            ],
            'aliases' => [],
            'bindings' => [
                \Diana\Drivers\ContainerInterface::class => \Diana\Runtime\IlluminateContainer::class,
                \Diana\Drivers\ConfigInterface::class => \Diana\Config\FileConfig::class,
                \Diana\Drivers\EventInterface::class => \Diana\Event\Event::class,
                \Diana\Drivers\RouterInterface::class => \Diana\Router\FileRouter::class,
                \Diana\Drivers\RouteInterface::class => \Diana\Router\Route::class,
                \Diana\Drivers\RendererInterface::class => \Diana\Rendering\Drivers\BladeRenderer::class,
                \Diana\Drivers\EventManagerInterface::class => \Diana\Event\EventManager::class
            ],
            'entryPoint' => '\App\AppModule',
            'env' => 'dev',
            'logs' => [
                'error' => 'logs/error.log',
                'access' => 'logs/access.log'
            ],
            'timezone' => 'Europe/Berlin'
        ];
    }
}
