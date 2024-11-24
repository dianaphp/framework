<?php

namespace Diana\Runtime;

use Closure;
use Composer\Autoload\ClassLoader;
use Diana\Contracts\ConfigContract;
use Diana\Contracts\ContainerContract;
use Diana\Contracts\EventManagerContract;
use Diana\Contracts\RequestContract;
use Diana\Contracts\RouteContract;
use Diana\Contracts\RouterContract;
use Diana\Events\BootEvent;
use Diana\Events\RegisterPackageEvent;
use Diana\Events\ShutdownEvent;
use Diana\IO\ConsoleRequest;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\IO\Pipeline;
use Diana\IO\Response;
use Diana\Router\Exceptions\CommandNotFoundException;
use Diana\Router\Exceptions\RouteNotFoundException;
use Diana\Runtime\KernelModules\ConfigurePhp;
use Diana\Runtime\KernelModules\ExceptionHandler;
use Diana\Runtime\KernelModules\ProvideAliases;
use Diana\Support\Helpers\Data;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Framework
{
    protected string $frameworkPath;

    protected ContainerContract $container;
    protected ConfigContract $config;
    protected EventManagerContract $eventManager;
    protected RouterContract $router;

    protected array $middleware = [];

    protected array $modules = [
        ExceptionHandler::class,
        ConfigurePhp::class,
        ProvideAliases::class
    ];

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(
        protected string $appPath,
        protected string $configFolder,
        protected ClassLoader $loader,
        Closure|ConfigContract $config
    ) {
        $this->frameworkPath = dirname(__DIR__, 3);

        $this->setupConfig($config);
        $this->instantiateContainer();
        $this->registerBindings();
        $this->runModules();
        $this->setupDrivers();

        $this->eventManager->dispatch(new BootEvent());

        $this->registerPackage($this->config->get('entryPoint'));
    }

    protected function setupConfig(Closure|ConfigContract $config): void
    {
        $this->config = Data::valueOf($config, $this);
        $this->config->addDefault($this->getDefaultConfig());
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function setupDrivers(): void
    {
        // todo: $this->logger = $this->container->get(LoggerContract::class);
        $this->eventManager = $this->container->get(EventManagerContract::class);
        $this->router = $this->container->get(RouterContract::class);
    }

    protected function instantiateContainer(): void
    {
        $singleton = $this->config->get('singleton');
        $containerClass = $singleton[ContainerContract::class];
        $this->container = new $containerClass();
    }

    protected function registerBindings(): void
    {
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

        $this->container->instance(ContainerContract::class, $this->container);
        $this->container->instance(Framework::class, $this);
        $this->container->instance(ClassLoader::class, $this->loader);
    }

    public function runModules(): void
    {
        foreach ($this->modules as $module) {
            $this->container->singleton($module);
            $this->container->make($module)();
        }
    }

    public function registerPackage(string $package, bool $force = false): void
    {
        if (!$force && $this->container->has($package)) {
            return;
        }

        $instance = $this->container->make($package);
        $this->container->instance($package, $instance);

        $this->eventManager->dispatch(new RegisterPackageEvent($instance, $force));
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
        $this->eventManager->dispatch(new ShutdownEvent($status));

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

    protected function getDefaultConfig(): array
    {
        return [
            'output' => 'php://output',
            'cachePath' => 'tmp',
            'aliases' => [],
            'singleton' => [
                \Diana\Contracts\ContainerContract::class => \Diana\Runtime\IlluminateContainer::class,
                \Diana\Contracts\ConfigContract::class => \Diana\Config\FileConfig::class,
                \Diana\Contracts\CacheContract::class => \Diana\Cache\JsonFileCache::class,
                \Diana\Contracts\RouterContract::class => \Diana\Router\FileRouterCached::class,
                \Diana\Contracts\RouteContract::class => \Diana\Router\Route::class,
                \Diana\Contracts\RendererContract::class => \Diana\Rendering\Drivers\BladeRenderer::class,
                \Diana\Contracts\EventManagerContract::class => \Diana\IO\Event\EventManager::class
            ],
            'contextualBindings' => [
                \Diana\Router\FileRouterCached::class => [
                    \Diana\Contracts\CacheContract::class => \Diana\Cache\PhpFileCache::class
                ]
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
