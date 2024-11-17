<?php

namespace Diana\Router;

use Diana\Contracts\ContainerContract;
use Diana\Contracts\EventManagerContract;
use Diana\Contracts\RouteContract;
use Diana\Contracts\RouterContract;
use Diana\Events\RegisterPackageEvent;
use Diana\Router\Attributes\Command;
use Diana\Router\Attributes\CommandErrorHandler;
use Diana\Router\Attributes\HttpErrorHandler;
use Diana\Router\Attributes\Middleware;
use Diana\Router\Attributes\Route;
use Diana\Router\Exceptions\DuplicateRouteException;
use Diana\Router\Exceptions\MissingArgumentsException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class FileRouter extends Router implements RouterContract
{
    public function __construct(
        protected ContainerContract $container,
        EventManagerContract $eventManager
    ) {
        $eventManager->addNewEventListener(RegisterPackageEvent::class, [$this, 'handlePackage'], ['*']);
    }

    /**
     * @throws ReflectionException
     * @throws MissingArgumentsException
     * @throws DuplicateRouteException
     */
    public function handlePackage(RegisterPackageEvent $event): void
    {
        $this->generateRoutesFromPackage($event->getPackage());
    }

    /**
     * @throws ReflectionException
     * @throws DuplicateRouteException|MissingArgumentsException
     */
    public function generateRoutesFromPackage(object $package): void
    {
        $reflectionClass = new ReflectionClass($package);
        foreach ($reflectionClass->getMethods() as $classMethod) {
            $reflectionMethod = new ReflectionMethod($package, $classMethod->name);
            $attributes = $reflectionMethod->getAttributes();

            $middleware = $this->extractMiddlewareFromAttributes($attributes);

            foreach ($attributes as $attribute) {
                $attributeName = $attribute->getName();
                $attributeArgs = $attribute->getArguments();

                if (!$this->isValidAttribute($attributeName)) {
                    continue;
                }

                $route = $this->container->make(RouteContract::class, [
                    'controller' => $reflectionClass->getName(),
                    'method' => $classMethod->name,
                    'middleware' => $middleware,
                ]);

                $this->registerRoute($attributeName, $attributeArgs, $route);
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

    protected function isValidAttribute(string $attributeName): bool
    {
        $handledRoutes = [
            Route::class,
            Command::class,
            HttpErrorHandler::class,
            CommandErrorHandler::class
        ];

        foreach ($handledRoutes as $attributeClass) {
            if (is_a($attributeName, $attributeClass, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws DuplicateRouteException
     * @throws MissingArgumentsException
     */
    protected function registerRoute(string $attributeName, array $args, RouteContract $route): void
    {
        if (is_a($attributeName, HttpErrorHandler::class, true)) {
            $this->routes['*'][HttpErrorHandler::class] = $route;
            return;
        }
        if (is_a($attributeName, CommandErrorHandler::class, true)) {
            $this->commands[CommandErrorHandler::class] = $route;
            return;
        }

        if (empty($args)) {
            throw new MissingArgumentsException(
                'Route [' . $route['controller'] . '@' . $route['method'] . '] does not provide a path.'
            );
        }

        if (is_a($attributeName, Command::class, true)) {
            $command = array_shift($args);
            $route->setParameters($args);
            $this->commands[$command] = $route;
            return;
        }

        $attributeMethod = $attributeName::METHOD;
        if (!isset($this->routes[$attributeMethod])) {
            $this->routes[$attributeMethod] = [];
        }

        $path = '/';
        $controller = $route->getController();
        if (isset($controller::$route)) {
            $path .= trim($controller::$route, '/') . '/';
        }

        $path .= trim($args[0], '/');

        if (array_key_exists($path, $this->routes[$attributeMethod])) {
            throw new DuplicateRouteException(
                'Route [' . $controller . '@' . $route->getMethod() . '] tried to assign the path [' .
                $path . '] that has already been assigned to [' .
                $this->routes[$attributeMethod][$path]->getController() .
                '@' . $this->routes[$attributeMethod][$path]->getMethod() . ']'
            );
        }

        $route->setSegments(explode('/', trim($path, '/')));
        $this->routes[$attributeMethod][$path] = $route;
    }
}
