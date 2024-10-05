<?php

namespace Diana\Routing\Drivers;

use Closure;
use Diana\IO\ConsoleRequest;
use Diana\IO\HttpRequest;
use Diana\IO\Request;
use Diana\Routing\Attributes\Command;
use Diana\Routing\Attributes\CommandErrorHandler;
use Diana\Routing\Attributes\Delete;
use Diana\Routing\Attributes\Get;
use Diana\Routing\Attributes\HttpErrorHandler;
use Diana\Routing\Attributes\Middleware;
use Diana\Routing\Attributes\Patch;
use Diana\Routing\Attributes\Post;
use Diana\Routing\Attributes\Put;
use Diana\Routing\Exceptions\CommandNotFoundException;
use Diana\Routing\Exceptions\RouteNotFoundException;
use Diana\Routing\Exceptions\UnsupportedRequestTypeException;
use Diana\Routing\Router;
use Diana\Support\Exceptions\FileNotFoundException;
use Diana\Support\Helpers\Data;
use Diana\Support\Helpers\Filesystem;
use Diana\Support\Helpers\Str;
use Diana\Support\Serializer\ArraySerializer;
use Exception;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class FileRouter implements Router
{
    protected static array $routeMap = [
        Get::class, Post::class, Put::class, Patch::class, Delete::class
    ];

    protected array $routes = [];

    protected array $commands = [];

    /**
     * @throws ReflectionException
     * @throws FileNotFoundException
     */
    public function load(string $cacheFile, array|Closure $controllers): void
    {
        if (!file_exists($cacheFile)) {
            $this->loadControllers(Data::valueOf($controllers));
            file_put_contents(
                $cacheFile,
                ArraySerializer::serialize(["routes" => $this->routes, "commands" => $this->commands])
            );
        } else {
            ["routes" => $this->routes, "commands" => $this->commands] = Filesystem::getRequire($cacheFile);
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
     * @throws Exception
     */
    protected function processAttribute(string $attributeName, array $args, array $route): void
    {
        if ($attributeName == HttpErrorHandler::class) {
            $this->routes['__error'] = $route;
            return;
        }

        if ($attributeName == CommandErrorHandler::class) {
            $this->commands['__error'] = $route;
            return;
        }

        if (!array_key_exists($attributeName, [...self::$routeMap, Command::class])) {
            return;
        }

        if (empty($args)) {
            throw new Exception(
                'Route [' . $route['controller'] . '@' . $route['method'] . '] does not provide a path.'
            );
        }

        if ($attributeName == Command::class) {
            $command = array_shift($args);
            $this->commands[$command] = [...$route, 'args' => $args];
            return;
        }

        $attributeMethod = $attributeName::METHOD;

        $path = '/';
        if (isset($route['controller']::$route)) {
            $path .= trim($route['controller']::$route, '/') . '/';
        }

        $path .= trim($args[0], '/');

        if (array_key_exists($path, $this->routes[$attributeMethod])) {
            throw new Exception(
                'Route [' . $route['controller'] . '@' . $route['method'] . '] tried to assign the path [' .
                $path . '] that has already been assigned to [' .
                $this->routes[$attributeMethod][$path]['controller'] .
                '@' . $this->routes[$attributeMethod][$path]['method'] . ']'
            );
        }

        $this->routes[$attributeMethod][$path] = [
            ...$route,
            'segments' => explode('/', trim($path, '/'))
        ];
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function loadControllers(array $controllers = []): void
    {
        foreach (self::$routeMap as $method) {
            $this->routes[$method::METHOD] = [];
        }

        foreach ($controllers as $controller) {
            foreach ((new ReflectionClass($controller))->getMethods() as $method) {
                $reflection = new ReflectionMethod($controller, $method->name);
                $attributes = $reflection->getAttributes();

                $middleware = $this->extractMiddlewareFromAttributes($attributes);

                foreach ($attributes as $attribute) {
                    $attributeName = $attribute->getName();
                    $args = $attribute->getArguments();

                    $route = [
                        'controller' => $controller,
                        'method' => $method->name,
                        'middleware' => $middleware,
                    ];

                    $this->processAttribute($attributeName, $args, $route);
                }
            }
        }
    }

    /**
     * @throws RouteNotFoundException
     * @throws CommandNotFoundException
     * @throws UnsupportedRequestTypeException
     */
    public function resolve(Request $request): array
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
    protected function findRoute(string $path, string $method): array
    {
        $trim = trim($path, '/');
        $segments = explode('/', $trim);
        $segmentCount = count($segments);

        $params = [];

        foreach ($this->routes[$method] as $route) {
            if ($segmentCount != count($route['segments'])) {
                continue;
            }

            for ($i = 0; $i < $segmentCount; $i++) {
                if (isset($route['segments'][$i][0]) && $route['segments'][$i][0] == ':') {
                    $params[substr($route['segments'][$i], 1)] = $segments[$i];
                } elseif ($route['segments'][$i] != $segments[$i]) {
                    continue 2;
                }
            }

            $route['params'] = $params;
            return $route;
        }

        throw new RouteNotFoundException("The route [{$path}] using the method [{$method}] could not have been found.");
    }

    public function getErrorRouteHandler()
    {
        if (isset($this->routes['__error'])) {
            $route = $this->routes['__error'];
            $route['params'] = [];
            return $route;
        }
    }

    public function getErrorCommandHandler()
    {
        if (isset($this->commands['__error'])) {
            $route = $this->commands['__error'];
            $route['params'] = [];
            return $route;
        }
    }

    /**
     * @throws CommandNotFoundException
     */
    protected function findCommand(string $commandName, array $args = []): array
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
                if (!isset($command['args'][$i])) {
                    continue;
                }

                if ($command['args'][$i][0] === '?') {
                    $command['args'][$i] = substr($command['args'][$i], 1);
                }

                $params[$command['args'][$i]] = $args[$i];
            }

            $command['params'] = $params;

            return $command;
        }

        throw new CommandNotFoundException("The command [{$commandName}] could not have been found.");
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function getCommands(): array
    {
        return $this->commands;
    }
}