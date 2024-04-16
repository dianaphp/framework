<?php

namespace Diana\Routing\Drivers;

use ReflectionClass, ReflectionMethod;

use Diana\Routing\Attributes\Delete;
use Diana\Routing\Attributes\Get;
use Diana\Routing\Attributes\Patch;
use Diana\Routing\Attributes\Post;
use Diana\Routing\Attributes\Put;

use Diana\Routing\Contracts\Router as RouterContract;
use Diana\Routing\Attributes\Middleware;
use Exception;
use Diana\Routing\Exceptions\RouteNotFoundException;
use Diana\Routing\Attributes\Command;
use Diana\Routing\Exceptions\CommandNotFoundException;
use Diana\Support\Helpers\Str;
use Closure;
use Diana\Support\Helpers\Filesystem;
use Diana\Support\Helpers\Data;
use Diana\Support\Serializer\ArraySerializer;
use Diana\Routing\Attributes\HttpErrorHandler;
use Diana\Routing\Attributes\CommandErrorHandler;
use Diana\IO\Request;
use Diana\Routing\Exceptions\UnsupportedRequestTypeException;
use Diana\Support\Debug;
use Diana\IO\HttpRequest;
use Diana\IO\ConsoleRequest;

class FileRouter implements RouterContract
{
    private static $methodMap = [
        Delete::class => "DELETE",
        Get::class => "GET",
        Patch::class => "PATCH",
        Post::class => "POST",
        Put::class => "PUT"
    ];

    protected array $routes = [];

    protected array $commands = [];

    public function load(string $cacheFile, array|Closure $controllers): void
    {
        if (!file_exists($cacheFile)) {
            $this->loadControllers(Data::valueOf($controllers));
            file_put_contents($cacheFile, ArraySerializer::serialize(["routes" => $this->routes, "commands" => $this->commands]));
        } else
            ["routes" => $this->routes, "commands" => $this->commands] = Filesystem::getRequire($cacheFile);
    }

    public function loadControllers(array $controllers = []): void
    {
        foreach (self::$methodMap as $class => $method)
            $this->routes[$method] = [];

        foreach ($controllers as $controller) {
            foreach ((new ReflectionClass($controller))->getMethods() as $method) {
                $reflection = new ReflectionMethod($controller, $method->name);
                $attributes = $reflection->getAttributes();

                $middleware = [];
                foreach ($attributes as $attribute) {
                    if ($attribute->getName() == Middleware::class)
                        foreach ($attribute->getArguments() as $argument)
                            $middleware[] = $argument;
                }

                foreach ($attributes as $attribute) {
                    $attributeName = $attribute->getName();

                    if ($attributeName == HttpErrorHandler::class) {
                        $this->routes['__error'] = [
                            'controller' => $controller,
                            'method' => $method->name,
                            'middleware' => $middleware,
                        ];
                        continue;
                    } elseif ($attributeName == CommandErrorHandler::class) {
                        $this->commands['__error'] = [
                            'controller' => $controller,
                            'method' => $method->name,
                            'middleware' => $middleware,
                        ];
                        continue;
                    } elseif ($attributeName == Command::class) {
                        $arguments = $attribute->getArguments();

                        if (empty($arguments))
                            throw new Exception('Command [' . $controller . '@' . $method->name . '] does not provide a command name.');

                        $command = array_shift($arguments);
                        $this->commands[$command] = [
                            'controller' => $controller,
                            'method' => $method->name,
                            'middleware' => $middleware,
                            'args' => $arguments
                        ];

                        continue;
                    }

                    if (!array_key_exists($attributeName, self::$methodMap))
                        continue;

                    $arguments = $attribute->getArguments();

                    if (empty($arguments))
                        throw new Exception('Route [' . $controller . '@' . $method->name . '] does not provide a path.');

                    $path = '/';
                    if (isset($controller::$route))
                        $path .= trim($controller::$route, '/') . '/';
                    $path .= trim($arguments[0], '/');

                    if (array_key_exists($path, $this->routes[self::$methodMap[$attribute->getName()]]))
                        throw new Exception('Route [' . $controller . '@' . $method->name . '] tried to assign the path [' . $path . '] that has already been assigned to [' . $this->routes[self::$methodMap[$attribute->getName()]][$path]['controller'] . '@' . $this->routes[self::$methodMap[$attribute->getName()]][$path]['method'] . ']');

                    $this->routes[self::$methodMap[$attribute->getName()]][$path] = [
                        'controller' => $controller,
                        'method' => $method->name,
                        'middleware' => $middleware,
                        'segments' => explode('/', trim($path, '/'))
                    ];
                }
            }
        }
    }

    public function resolve(Request $request): array
    {
        if ($request instanceof HttpRequest)
            return $this->findRoute($request->getRoute(), $request->getMethod());
        elseif ($request instanceof ConsoleRequest) {
            return $this->findCommand($request->getCommand(), $request->args->getAll());
        } else
            throw new UnsupportedRequestTypeException("The provided request type is not being supported by the router.");
    }

    protected function findRoute(string $path, string $method): array
    {
        $trim = trim($path, '/');
        $segments = explode('/', $trim);
        $segmentCount = count($segments);

        $params = [];

        foreach ($this->routes[$method] as $route) {
            if ($segmentCount != count($route['segments']))
                continue;

            for ($i = 0; $i < $segmentCount; $i++) {
                if (isset($route['segments'][$i][0]) && $route['segments'][$i][0] == ':') {
                    $params[substr($route['segments'][$i], 1)] = $segments[$i];
                } elseif ($route['segments'][$i] != $segments[$i])
                    continue 2;
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
                if (!isset($command['args'][$i]))
                    continue;

                if ($command['args'][$i][0] === '?')
                    $command['args'][$i] = substr($command['args'][$i], 1);

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