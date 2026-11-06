<?php

namespace Diana\Framework\Core;

use Closure;
use Composer\Autoload\ClassLoader;
use Diana\Cache\JsonFileCache;
use Diana\Config\Config;
use Diana\Config\FileConfig;
use Diana\Contracts\Cache\Cache;
use Diana\Contracts\Config\Config as ConfigContract;
use Diana\Contracts\Core\Container;
use Diana\Contracts\Core\Pipeline as PipelineContract;
use Diana\Contracts\Event\Dispatcher;
use Diana\Contracts\RequestContract;
use Diana\Events\BootEvent;
use Diana\Events\RegisterPackageEvent;
use Diana\Events\ShutdownEvent;
use Diana\IO\ConsoleRequest;
use Diana\IO\Event\Dispatcher as DispatcherDriver;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\IO\Pipeline;
use Diana\IO\Response;
use Diana\Runtime\IlluminateContainer;
use Diana\Runtime\KernelModules\ExceptionHandler;
use Diana\Runtime\KernelModules\ProvideAliases;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Application
{
    protected Environment $environment;
    protected Config $config;
    protected Container $container;
    protected Dispatcher $dispatcher;
    protected object $entrypoint;

    protected array $registeredPackages = [];

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
        Config|Closure $config
    ) {
        $this->environment = new Environment();
        $this->config = $config instanceof Closure ? $config() : $config;
        $this->config->addDefault($this->defaultConfig());
        $this->environment->configure($this->config->all()['env'] ?? Environment::PROD);

        $this->setupContainer();
        $this->runModules();
        $this->setupDrivers();

        foreach ($this->config->get('packages') as $package) {
            $this->registerPackage($package);
        }

        $this->entrypoint = $this->registerPackage($this->config->get('entrypoint'));
        if (!is_callable($this->entrypoint)) {
            throw new InvalidArgumentException('The configured entrypoint must provide a public __invoke() method.');
        }

        $this->dispatcher->dispatch(new BootEvent());
    }

    protected function setupContainer(): void
    {
        $this->container = new ($this->config->get('singleton')[Container::class])();

        foreach ($this->config->get('bindings') as $abstract => $concrete) {
            $this->container->bind($abstract, $concrete);
        }

        foreach ($this->config->get('contextualBindings') as $class => $bindings) {
            foreach ($bindings as $abstract => $concrete) {
                $this->container->addContextualBinding($class, $abstract, $concrete);
            }
        }

        // it is crucial to register the user defined bindings first, as they might override core bindings
        foreach ($this->config->get('singleton') as $abstract => $concrete) {
            $this->container->singleton($abstract, $concrete);
        }

        foreach ($this->config->get('containerAliases') as $abstract => $alias) {
            $this->container->alias($abstract, $alias);
        }

        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Application::class, $this);
        $this->container->instance(Environment::class, $this->environment);
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
    }

    public function registerPackage(string $package, bool $force = false): object
    {
        if (!$force && isset($this->registeredPackages[$package])) {
            return $this->registeredPackages[$package];
        }

        $instance = $this->container->make($package);
        $this->container->instance($package, $instance);
        $this->registeredPackages[$package] = $instance;

        $this->dispatcher->dispatch(new RegisterPackageEvent($instance, $force));

        return $instance;
    }

    /**
     * @throws UnexpectedOutputTypeException
     */
    public function generateResponse(RequestContract $request): Response
    {
        $this->container->instance(RequestContract::class, $request);

        return $this->container->make(PipelineContract::class)
            ->send($request)
            ->pipe([
                ...$this->config->get('middleware'),
                function (RequestContract $request): Response {
                    $this->container->instance(RequestContract::class, $request);
                    $response = $this->container->call([$this->entrypoint, '__invoke'], ['request' => $request]);
                    return $response instanceof Response
                        ? $response
                        : new Response($response, $request->getDefaultStatusCode());
                },
            ])
            ->expect(Response::class);
    }

    /**
     * @throws UnexpectedOutputTypeException
     */
    public function handleRequest(RequestContract $request): void
    {
        $response = $this->generateResponse($request);
        $status = $response->getStatusCode();

        if (PHP_SAPI !== 'cli') {
            foreach ($response->getHeader() as $name => $values) {
                $replace = true;
                foreach ((array) $values as $value) {
                    header($name . ': ' . $value, $replace);
                    $replace = false;
                }
            }
        }
        // Set the status last: PHP may otherwise turn a Location header into a 302.
        http_response_code($status);

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
            'middleware' => [],
            'packages' => [],
            'bindings' => [
                PipelineContract::class => Pipeline::class,
            ],
            'singleton' => [
                Container::class => IlluminateContainer::class,
                Config::class => FileConfig::class,
                Cache::class => JsonFileCache::class,
                Dispatcher::class => DispatcherDriver::class
            ],
            'contextualBindings' => [],
            'containerAliases' => [
                Config::class => ConfigContract::class
            ],
            'entrypoint' => 'Diana\Router\Framework\RouterModule',
            'env' => Environment::PROD,
            'logs' => [
                'error' => 'logs/error.log',
                'access' => 'logs/access.log'
            ],
            'timezone' => 'Europe/Berlin'
        ];
    }
}
