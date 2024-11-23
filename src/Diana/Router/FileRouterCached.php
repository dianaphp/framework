<?php

namespace Diana\Router;

use Diana\Contracts\CacheContract;
use Diana\Contracts\ContainerContract;
use Diana\Contracts\EventManagerContract;
use Diana\Contracts\RouteContract;
use Diana\Events\BootEvent;
use Psr\SimpleCache\InvalidArgumentException;

class FileRouterCached extends FileRouter
{
    protected const string CACHE_KEY = 'router';

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        protected ContainerContract $container,
        protected CacheContract $cache,
        EventManagerContract $eventManager
    ) {
        $cache = $this->cache->get(self::CACHE_KEY);

        if ($cache) {
            $this->routes = $this->unserializeRoutes($cache['routes']);
            $this->commands = $this->unserializeCommands($cache['commands']);
        } else {
            parent::__construct($container, $eventManager);

            $eventManager->addSingleEventListener(BootEvent::class, [$this, 'generateCache']);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function generateCache(): void
    {
        $this->cache->set(self::CACHE_KEY, [
            "routes" => $this->serializeRoutes($this->routes),
            "commands" => $this->serializeCommands($this->commands)
        ]);
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
}
