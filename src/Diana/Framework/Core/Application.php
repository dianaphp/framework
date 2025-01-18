<?php

namespace Diana\Framework\Core;

use Composer\Autoload\ClassLoader;
use Diana\Cache\JsonFileCache;
use Diana\Cache\PhpFileCache;
use Diana\Config\Config;
use Diana\Config\FileConfig;
use Diana\Contracts\Cache\Cache;
use Diana\Contracts\Config\Config as ConfigContract;
use Diana\Contracts\Core\Container;
use Diana\Contracts\Event\Dispatcher;
use Diana\Contracts\RendererContract;
use Diana\Contracts\RequestContract;
use Diana\Contracts\Router\Route as RouteContract;
use Diana\Contracts\Router\Router;
use Diana\Events\BootEvent;
use Diana\Events\RegisterPackageEvent;
use Diana\Events\ShutdownEvent;
use Diana\IO\ConsoleRequest;
use Diana\IO\Event\Dispatcher as DispatcherDriver;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\IO\Pipeline;
use Diana\IO\Response;
use Diana\Rendering\Drivers\BladeRenderer;
use Diana\Router\Exceptions\CommandNotFoundException;
use Diana\Router\Exceptions\RouteNotFoundException;
use Diana\Router\FileRouterCached;
use Diana\Router\Route;
use Diana\Runtime\IlluminateContainer;
use Diana\Runtime\KernelModules\ExceptionHandler;
use Diana\Runtime\KernelModules\ProvideAliases;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Application
{
    protected Container $container;
    protected Dispatcher $dispatcher;
    protected Router $router;

    protected array $middleware = [];

    protected array $modules = [
        ExceptionHandler::class,
        ProvideAliases::class
    ];

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(
        protected string $appPath,
        protected ClassLoader $loader,
        protected Config $config
    ) {
        $this->config->addDefault($this->defaultConfig());

        $this->setupContainer();
        // ab hier kernel
        $this->runModules();
        $this->setupDrivers();

        $this->registerPackage($this->config->get('entryPoint'));

        $this->dispatcher->dispatch(new BootEvent());
    }

    protected function setupContainer(): void
    {
        $this->container = new ($this->config->get('singleton')[Container::class])();

        foreach ($this->config->get('contextualBindings') as $class => $bindings) {
            foreach ($bindings as $abstract => $concrete) {
                $this->container->addContextualBinding($class, $abstract, $concrete);
            }
        }

        // it is crucial to register the user defined bindings first, as they might override core bindings
        foreach ($this->config->get('singleton') as $abstract => $concrete) {
            // TODO: binding resolving as event? or pipeline?
            // TODO: outsource to another class?
//            if (is_a($concrete, ProxyInterface::class, true)) {
//                $reflectConcrete = new ReflectionClass($concrete);
//                $className = $reflectConcrete->getShortName() . 'Proxy';
//                if (!class_exists($className . 'Proxy')) {
//                    if (class_exists($abstract)) {
//                        $reflectAbstract = new ReflectionClass($abstract);
//                        $implementation = $reflectAbstract->isInterface() ? 'implements' : 'extends';
//                        eval('class ' . $className . ' ' . $implementation . ' ' . $abstract . ' {}');
//                    }
//                }
//            }
            $this->container->singleton($abstract, $concrete);
        }

        foreach ($this->config->get('containerAliases') as $abstract => $alias) {
            $this->container->alias($abstract, $alias);
        }

        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Application::class, $this);
        $this->container->instance(ClassLoader::class, $this->loader);
    }

    public function runModules(): void
    {
        foreach ($this->modules as $module) {
            $this->container->singleton($module);
            $this->container->make($module)();
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function setupDrivers(): void
    {
        // todo: $this->logger = $this->container->get(LoggerContract::class);
        $this->dispatcher = $this->container->get(Dispatcher::class);
        $this->router = $this->container->get(Router::class);
    }

    public function registerPackage(string $package, bool $force = false): void
    {
        if (!$force && $this->container->has($package)) {
            return;
        }

        $instance = $this->container->make($package);
        $this->container->instance($package, $instance);

        $this->dispatcher->dispatch(new RegisterPackageEvent($instance, $force));
    }

    /**
     * @throws UnexpectedOutputTypeException
     */
    public function generateResponse(RequestContract $request): Response
    {
        try {
            $route = $this->router->resolve($request);
            $statusCode = $request->getDefaultStatusCode();
        } catch (RouteNotFoundException) {
            $statusCode = 404;
            $route = $this->router->getErrorRoute();
            if (!$route) {
                // TODO: use the default renderer to render an http error
                // RendererInterface::class => BlankRenderer::class
                return new Response("HTTP Error 404 - This page could not be found.", $statusCode);
            }
        } catch (CommandNotFoundException) {
            $statusCode = 1;
            $route = $this->router->getErrorCommandRoute();
            if (!$route) {
                return new Response("This command could not be found.", $statusCode);
            }
        }

        $this->container->instance(RouteContract::class, $route);

        return $this->container->make(Pipeline::class)
            ->pipe($this->middleware) // global middleware
            ->pipe($route->getMiddleware()) // route middleware
            ->pipe(function () use ($route, $statusCode) {
                return new Response(
                    $this->container->call(
                        $route->getController() . '@' . $route->getMethod(),
                        [
                            'statusCode' => $statusCode,
                            ...$route->getParameters()
                        ]
                    ),
                    $statusCode
                );
            })
            ->expect(Response::class);
    }

    /**
     * @throws UnexpectedOutputTypeException
     */
    public function handleRequest(RequestContract $request): void
    {
        $this->container->instance(RequestContract::class, $request);

        $response = $this->generateResponse($request);
//        $this->container->instance(ResponseContract::class, $response);
        $status = $response->getStatusCode();

//        try {
        http_response_code($status);
//        } catch (ErrorException $e) {
        // todo: enable for debugging in case we want to dump something before the response code is set
        // use logger here
//        }

        $buffer = fopen($this->config->get('output'), 'a');
        fwrite($buffer, $response);
        fclose($buffer);

        $this->shutdown($status);
    }

    /**
     * @throws UnexpectedOutputTypeException
     */
    public function runCommand(string $command): Response
    {
        $args = explode(' ', $command);
        $command = array_shift($args);
        return $this->generateResponse(new ConsoleRequest($command, $args));
    }

    public function shutdown(int $status = 0): void
    {
        $this->dispatcher->dispatch(new ShutdownEvent($status));

        exit($status);
    }

    public function path(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $path = trim($path, DIRECTORY_SEPARATOR);
        $slugs = explode(DIRECTORY_SEPARATOR, $path);
        array_splice($slugs, 0, 0, $this->appPath);
        return join(DIRECTORY_SEPARATOR, $slugs);
    }

    protected function defaultConfig(): array
    {
        return [
            'output' => 'php://output',
            'cachePath' => 'tmp',
            'aliases' => [],
            'singleton' => [
                Container::class => IlluminateContainer::class,
                Config::class => FileConfig::class,
                Cache::class => JsonFileCache::class,
                Router::class => FileRouterCached::class,
                RouteContract::class => Route::class,
                RendererContract::class => BladeRenderer::class,
                Dispatcher::class => DispatcherDriver::class
            ],
            'contextualBindings' => [
                FileRouterCached::class => [
                    Cache::class => PhpFileCache::class
                ]
            ],
            'containerAliases' => [
                Config::class => ConfigContract::class
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
