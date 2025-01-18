<?php

namespace Diana\Router;

use Diana\Contracts\Cache\Cache;
use Diana\Contracts\Core\Container;
use Diana\Contracts\Event\Dispatcher;
use Diana\Contracts\Router\Route;
use Diana\Events\BootEvent;
use Psr\SimpleCache\InvalidArgumentException;

class FileRouterCached extends FileRouter
{
    protected const string CACHE_KEY = 'router';

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        protected Container $container,
        protected Cache $cache,
        Dispatcher $dispatcher
    ) {
        $cache = $this->cache->get(self::CACHE_KEY);

        if ($cache) {
            $this->routes = $this->unserializeRoutes($cache['routes']);
            $this->commands = $this->unserializeCommands($cache['commands']);
        } else {
            parent::__construct($container, $dispatcher);

            $dispatcher->addSingleEventListener(BootEvent::class, [$this, 'generateCache']);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function generateCache(): void
    {
        $this->cache->set(self::CACHE_KEY, [
            "routes" => $this->serializeRoutes(),
            "commands" => $this->serializeCommands()
        ]);
    }

    public function unserializeRoutes(array $routes): array
    {
        $unserialized = [];
        foreach ($routes as $method => $routesByMethod) {
            foreach ($routesByMethod as $routePath => $serializedRoute) {
                $routeInstance = $this->container->make(Route::class, [
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
            $routeInstance = $this->container->make(Route::class, [
                'controller' => $serializedRoute['controller'],
                'method' => $serializedRoute['method'],
                'middleware' => $serializedRoute['middleware'] ?? [],
                'params' => $serializedRoute['params'] ?? [],
            ]);
            $unserialized[$name] = $routeInstance;
        }
        return $unserialized;
    }

    public function serializeRoutes(): array
    {
        $serialized = [];
        foreach ($this->routes as $method => $routesByMethod) {
            foreach ($routesByMethod as $routePath => $routeInstance) {
                $serialized[$method][$routePath] = $routeInstance->toArray();
            }
        }
        return $serialized;
    }

    public function serializeCommands(): array
    {
        return array_map(fn(Route $command) => $command->toArray(), $this->commands);
    }
}
