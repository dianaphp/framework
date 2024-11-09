<?php

namespace Diana\Router;

use Diana\Contracts\RequestContract;
use Diana\Contracts\RouteContract;
use Diana\Contracts\RouterContract;
use Diana\IO\ConsoleRequest;
use Diana\IO\HttpRequest;
use Diana\Router\Attributes\CommandErrorHandler;
use Diana\Router\Attributes\HttpErrorHandler;
use Diana\Router\Exceptions\CommandNotFoundException;
use Diana\Router\Exceptions\RouteNotFoundException;
use Diana\Router\Exceptions\UnsupportedRequestTypeException;
use Diana\Support\Helpers\Str;

abstract class Router implements RouterContract
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
     * @throws UnsupportedRequestTypeException
     * @throws RouteNotFoundException
     * @throws CommandNotFoundException
     */
    public function resolve(RequestContract $request): RouteContract
    {
        if ($request instanceof HttpRequest) {
            return $this->resolveRoute($request->getRoute(), $request->getMethod());
        } elseif ($request instanceof ConsoleRequest) {
            return $this->resolveCommand($request->getCommand(), $request->args->getAll());
        } else {
            throw new UnsupportedRequestTypeException(
                "The provided request type is not being supported by the router."
            );
        }
    }

    /**
     * @throws RouteNotFoundException
     */
    protected function resolveRoute(string $path, string $method): RouteContract
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

    /**
     * @throws CommandNotFoundException
     */
    protected function resolveCommand(string $command, array $args = []): RouteContract
    {
        if (isset($this->commands[$command])) {
            $route = $this->commands[$command];

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
                $params = $route->getParameters();
                if (!isset($params[$i])) {
                    continue;
                }

                if ($params[$i][0] === '?') {
                    $params[$i] = substr($params[$i], 1);
                }

                $params[$params[$i]] = $args[$i];
            }

            $route->setParameters($params);

            return $route;
        }

        throw new CommandNotFoundException("The command [{$command}] could not have been found.");
    }

    public function getErrorRoute(): ?RouteContract
    {
        return $this->routes['*'][HttpErrorHandler::class] ?? null;
    }

    public function getErrorCommandRoute(): ?RouteContract
    {
        return $this->commands[CommandErrorHandler::class] ?? null;
    }
}
