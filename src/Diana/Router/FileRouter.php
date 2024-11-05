<?php

namespace Diana\Router;

use Diana\Contracts\ContainerContract;
use Diana\Contracts\EventManagerContract;
use Diana\Contracts\RequestContract;
use Diana\Events\BootEvent;
use Diana\IO\ConsoleRequest;
use Diana\IO\HttpRequest;
use Diana\Router\Attributes\Command;
use Diana\Router\Attributes\CommandErrorHandler;
use Diana\Router\Attributes\HttpErrorHandler;
use Diana\Router\Attributes\Middleware;
use Diana\Router\Attributes\Route;
use Diana\Router\Exceptions\CommandNotFoundException;
use Diana\Router\Exceptions\DuplicateRouteException;
use Diana\Router\Exceptions\MissingArgumentsException;
use Diana\Router\Exceptions\RouteNotFoundException;
use Diana\Router\Exceptions\UnsupportedRequestTypeException;
use Diana\Runtime\Framework;
use Diana\Support\Exceptions\FileNotFoundException;
use Diana\Support\Helpers\Filesystem;
use Diana\Support\Helpers\Str;
use Diana\Runtime\Attributes\Config;
use Diana\Contracts\ConfigContract;
use Diana\Contracts\RouteContract;
use Diana\Contracts\RouterContract;
use Diana\Support\Serializer\ArraySerializer;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

// TODO: Rework this piece of junk
class FileRouter implements RouterContract
{
    /**
     * @var RouteContract[][] The routes
     */
    protected array $routes = [];

    /**
     * @var RouteContract[] The commands
     */
    protected array $commands = [];

    /**
     * @throws FileNotFoundException
     */
    public function __construct(
        #[Config('cfg/framework')] protected ConfigContract $config,
        protected ContainerContract $container,
        protected Framework $app,
        protected EventManagerContract $eventManager
    ) {
        $config->addDefault(['routeCachePath' => 'tmp/routes.php']);

        // ensureCache
        $cachePath = $this->app->path($this->config->get('routeCachePath'));
        if (file_exists($cachePath)) {
            $routes = Filesystem::getRequire($cachePath);
            $this->routes = $this->unserializeRoutes($routes['routes']);
            $this->commands = $this->unserializeCommands($routes['commands']);
        } else {
            // todo: the router shouldnt depend on the event manager
            $this->eventManager->addNewEventListener(
                class: Framework::class,
                action: 'registerPackage',
                callable: [$this, 'loadRoutes'],
                extra: [
                    'before' => ['*']
                ]
            );

            $this->eventManager->addNewEventListener(
                event: BootEvent::class,
                callable: [$this, 'cacheRoutes']
            );
        }
    }

    public function cacheRoutes(): void
    {
        $cachePath = $this->app->path($this->config->get('routeCachePath'));
        file_put_contents(
            $cachePath,
            ArraySerializer::serialize([
                "routes" => $this->serializeRoutes($this->routes),
                "commands" => $this->serializeCommands($this->commands)
            ])
        );
    }

    /**
     * @throws ReflectionException
     * @throws DuplicateRouteException|MissingArgumentsException
     */
    public function loadRoutes(string $package): void
    {
        $reflectionClass = new ReflectionClass($package);
        foreach ($reflectionClass->getMethods() as $classMethod) {
            $reflectionMethod = new ReflectionMethod($package, $classMethod->name);
            $attributes = $reflectionMethod->getAttributes();

            $middleware = $this->extractMiddlewareFromAttributes($attributes);

            foreach ($attributes as $attribute) {
                $httpMethodAttribute = $attribute->getName();
                $attributeArgs = $attribute->getArguments();

                $route = $this->container->make(RouteContract::class, [
                    'controller' => $package,
                    'method' => $classMethod->name,
                    'middleware' => $middleware,
                ]);

                $this->addRoute($httpMethodAttribute, $attributeArgs, $route);
            }
        }
    }

    /**
     * @param ReflectionAttribute[] $attributes
     * @return array
     */
    protected function extractMiddlewareFromAttributes(array $attributes): array
    {
        $middleware = [];
        foreach ($attributes as $attribute) {
            if ($attribute->getName() == Middleware::class) {
                foreach ($attribute->getArguments() as $argument) {
                    $middleware[] = $argument;
                }
            }
        }
        return $middleware;
    }

    /**
     * @throws DuplicateRouteException
     * @throws MissingArgumentsException
     */
    protected function addRoute(string $httpMethodAttribute, array $args, RouteContract $route): void
    {
        if ($httpMethodAttribute == HttpErrorHandler::class) {
            $this->routes['*'][HttpErrorHandler::class] = $route;
            return;
        }

        if ($httpMethodAttribute == CommandErrorHandler::class) {
            $this->commands[CommandErrorHandler::class] = $route;
            return;
        }

        if (
            !is_a($httpMethodAttribute, Route::class, true) &&
            !is_a($httpMethodAttribute, Command::class, true)
        ) {
            return;
        }

        if (empty($args)) {
            throw new MissingArgumentsException(
                'Route [' . $route['controller'] . '@' . $route['method'] . '] does not provide a path.'
            );
        }

        if ($httpMethodAttribute == Command::class) {
            $command = array_shift($args);
            $route->setParameters($args);
            $this->commands[$command] = $route;
            return;
        }

        $attributeMethod = $httpMethodAttribute::METHOD;
        if (!isset($this->routes[$attributeMethod])) {
            $this->routes[$attributeMethod] = [];
        }

        $path = '/';
        if (isset($route->getController()::$route)) {
            $path .= trim($route['controller']::$route, '/') . '/';
        }

        $path .= trim($args[0], '/');

        if (array_key_exists($path, $this->routes[$attributeMethod])) {
            throw new DuplicateRouteException(
                'Route [' . $route->getController() . '@' . $route->getMethod() . '] tried to assign the path [' .
                $path . '] that has already been assigned to [' .
                $this->routes[$attributeMethod][$path]->getController() .
                '@' . $this->routes[$attributeMethod][$path]->getMethod() . ']'
            );
        }

        $route->setSegments(explode('/', trim($path, '/')));
        $this->routes[$attributeMethod][$path] = $route;
    }

    /**
     * @throws RouteNotFoundException
     * @throws CommandNotFoundException
     * @throws UnsupportedRequestTypeException
     */
    public function resolve(RequestContract $request): RouteContract
    {
        if ($request instanceof HttpRequest) {
            return $this->findRoute($request->getRoute(), $request->getMethod());
        } elseif ($request instanceof ConsoleRequest) {
            return $this->findCommand($request->getCommand(), $request->args->getAll());
        } else {
            throw new UnsupportedRequestTypeException(
                "The provided request type is not being supported by the router."
            );
        }
    }

    /**
     * @throws RouteNotFoundException
     */
    protected function findRoute(string $path, string $method): RouteContract
    {
        $trim = trim($path, '/');
        $segments = explode('/', $trim);
        $segmentCount = count($segments);

        $params = [];

        foreach ($this->routes[$method] ?? [] as $route) {
            $routeSegments = $route->getSegments();
            if ($segmentCount != count($routeSegments)) {
                continue;
            }

            for ($i = 0; $i < $segmentCount; $i++) {
                if (isset($routeSegments[$i][0]) && $routeSegments[$i][0] == ':') {
                    $params[substr($routeSegments[$i], 1)] = $segments[$i];
                } elseif ($routeSegments[$i] != $segments[$i]) {
                    continue 2;
                }
            }

            $route->setParameters($params);
            return $route;
        }

        throw new RouteNotFoundException("The route [{$path}] using the method [{$method}] could not have been found.");
    }

    public function getErrorRoute(): ?RouteContract
    {
        return $this->routes['*'][HttpErrorHandler::class] ?? null;
    }

    public function getErrorCommandRoute(): ?RouteContract
    {
        return $this->commands[CommandErrorHandler::class] ?? null;
    }

    /**
     * @param RouteContract[][] $routes
     */
    public function serializeRoutes(array $routes): array
    {
        $serialized = [];
        foreach ($routes as $method => $routesByMethod) {
            foreach ($routesByMethod as $routePath => $routeInstance) {
                $serialized[$method][$routePath] = $routeInstance->toArray();
            }
        }
        return $serialized;
    }

    public function unserializeRoutes(array $routes): array
    {
        $unserialized = [];
        foreach ($routes as $method => $routesByMethod) {
            foreach ($routesByMethod as $routePath => $serializedRoute) {
                $routeInstance = $this->container->make(RouteContract::class, [
                    'controller' => $serializedRoute['controller'],
                    'method' => $serializedRoute['method'],
                    'middleware' => $serializedRoute['middleware'] ?? [],
                    'segments' => $serializedRoute['segments'] ?? [],
                    'params' => $serializedRoute['params'] ?? [],
                ]);
                $unserialized[$method][$routePath] = $routeInstance;
            }
        }
        return $unserialized;
    }

    /**
     * @param RouteContract[] $commands
     */
    public function serializeCommands(array $commands): array
    {
        $serialized = [];
        foreach ($commands as $name => $command) {
            $serialized[$name] = $command->toArray();
        }
        return $serialized;
    }

    public function unserializeCommands(array $commands): array
    {
        $unserialized = [];
        foreach ($commands as $name => $serializedRoute) {
            $routeInstance = $this->container->make(RouteContract::class, [
                'controller' => $serializedRoute['controller'],
                'method' => $serializedRoute['method'],
                'middleware' => $serializedRoute['middleware'] ?? [],
                'params' => $serializedRoute['params'] ?? [],
            ]);
            $unserialized[$name] = $routeInstance;
        }
        return $unserialized;
    }

    /**
     * @throws CommandNotFoundException
     */
    protected function findCommand(string $commandName, array $args = []): RouteContract
    {
        if (isset($this->commands[$commandName])) {
            $command = $this->commands[$commandName];

            $params = [];

            $size = sizeof($args);
            for ($i = 0; $i < $size; $i++) {
                if (Str::startsWith($args[$i], '--')) {
                    if (isset($args[$i + 1]) && !Str::startsWith($args[$i + 1], '--')) {
                        $params[substr($args[$i], 2)] = $args[$i + 1];
                        unset($args[$i]);
                        unset($args[$i + 1]);
                        $i++;
                    } else {
                        $params[substr($args[$i], 2)] = true;
                        unset($args[$i]);
                    }
                }
            }

            $args = array_values($args);

            $size = sizeof($args);
            for ($i = 0; $i < $size; $i++) {
                $params = $command->getParameters();
                if (!isset($params[$i])) {
                    continue;
                }

                if ($params[$i][0] === '?') {
                    $params[$i] = substr($params[$i], 1);
                }

                $params[$params[$i]] = $args[$i];
            }

            $command->setParameters($params);

            return $command;
        }

        throw new CommandNotFoundException("The command [{$commandName}] could not have been found.");
    }

    public function getCommands(): array
    {
        return $this->commands;
    }
}
