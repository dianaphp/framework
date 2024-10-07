<?php

namespace Diana\Tests;

use Diana\IO\ConsoleRequest;
use Diana\IO\HttpRequest;
use Diana\Router\Exceptions\CommandNotFoundException;
use Diana\Router\Exceptions\RouteNotFoundException;
use Diana\Router\FileRouter;
use Diana\Routing\Router;
use Diana\Tests\Controllers\AllMethodsController;
use Diana\Tests\Controllers\CommandController;
use Diana\Tests\Controllers\GetMethodController;
use Diana\Tests\Controllers\ParamController;
use Diana\Tests\Middleware\MockMiddleware;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public Router $router;

    public function setUp(): void
    {
        $this->router = new FileRouter();
    }

    public function tearDown(): void
    {
        unset($this->router);
    }

    public function testEmptyRouteLoadingAndMethodIntegrity()
    {
        $this->router->loadControllers([]);
        $routes = $this->router->getRoutes();

        foreach (['GET', 'POST', 'DELETE', 'PATCH', 'PUT'] as $method) {
            $this->assertArrayHasKey($method, $routes);
            $this->assertEmpty($routes[$method]);
        }

        $this->assertEmpty($this->router->getCommands());
    }

    public function testGetMethodRouteLoading()
    {
        $this->router->loadControllers([GetMethodController::class]);
        $routes = $this->router->getRoutes();

        foreach (['POST', 'DELETE', 'PATCH', 'PUT'] as $method)
            $this->assertEmpty($routes[$method]);

        $this->assertCount(1, $routes['GET']);
        $this->assertArrayHasKey('/GET', $routes['GET']);

        $route = $routes['GET']['/GET'];

        $this->assertEquals(GetMethodController::class, $route['controller']);
        $this->assertEquals('GET', $route['method']);

        $this->assertCount(1, $route['middleware']);
        $this->assertContains(MockMiddleware::class, $route['middleware']);
    }

    public function testAllMethodsRouteLoading()
    {
        $this->router->loadControllers([AllMethodsController::class]);
        $routes = $this->router->getRoutes();

        $methods = ['GET', 'POST', 'DELETE', 'PATCH', 'PUT'];
        foreach ($methods as $method) {
            $this->assertCount(1, $routes[$method]);

            $path = AllMethodsController::$route . '/' . $method;

            $this->assertArrayHasKey($path, $routes[$method]);

            $route = $routes[$method][$path];

            $this->assertEquals(AllMethodsController::class, $route['controller']);
            $this->assertEquals($method, $route['method']);

            $this->assertEmpty($route['middleware']);
        }
    }

    public function testAllMethodsRouteResolving()
    {
        $this->router->loadControllers([AllMethodsController::class]);
        $routes = $this->router->getRoutes();

        $methods = ['GET', 'POST', 'DELETE', 'PATCH', 'PUT'];
        foreach ($methods as $method) {
            $path = AllMethodsController::$route . '/' . $method;

            $route = $routes[$method][$path];

            $this->assertNotEquals($route, $this->router->resolve(new HttpRequest($path, $method)));

            $route['params'] = [];

            $this->assertEquals($route, $this->router->resolve(new HttpRequest($path, $method)));
        }
    }

    public function testInvalidRouteResolving()
    {
        $this->router->loadControllers([AllMethodsController::class]);

        $this->expectException(RouteNotFoundException::class);
        $this->router->resolve(new HttpRequest('/GET', 'GET'));
    }

    public function testParamRouteResolving()
    {
        $this->router->loadControllers([ParamController::class]);
        $routes = $this->router->getRoutes();

        $route = $routes['GET']['/noparam'];
        $route['params'] = [];
        $this->assertEquals($route, $this->router->resolve(new HttpRequest('/noparam', 'GET')));

        $route = $routes['GET']['/param/:param'];
        $route['params'] = ['param' => '123'];
        $this->assertNotEquals($route, $this->router->resolve(new HttpRequest('/param/test', 'GET')));

        $route['params'] = ['param' => 'test'];
        $this->assertEquals($route, $this->router->resolve(new HttpRequest('/param/test', 'GET')));
    }

    public function testInvalidParamRouteResolving()
    {
        $this->router->loadControllers([ParamController::class]);

        $this->expectException(RouteNotFoundException::class);
        $this->router->resolve(new HttpRequest('/param/', 'GET'));
    }

    public function testCommandResolving()
    {
        $this->router->loadControllers([CommandController::class]);

        $command = $this->router->resolve(new ConsoleRequest('test', []));
        $expectedCommand = $this->router->getCommands()['test'];
        $expectedCommand['params'] = [];
        $this->assertEquals($expectedCommand, $command);

        $this->assertEquals(CommandController::class, $command['controller']);
        $this->assertEquals('test', $command['method']);

        $this->assertCount(1, $command['middleware']);
        $this->assertContains(MockMiddleware::class, $command['middleware']);
    }

    public function testInvalidCommandResolving()
    {
        $this->router->loadControllers([CommandController::class]);

        $this->expectException(CommandNotFoundException::class);
        $this->router->resolve(new ConsoleRequest('invalid', []));
    }

    public function testArgCommandResolving()
    {
        $this->router->loadControllers([CommandController::class]);

        $command = $this->router->resolve(new ConsoleRequest('testarg', [1]));
        $expectedCommand = $this->router->getCommands()['testarg'];
        $expectedCommand['params'] = ['opt' => 1];
        $this->assertEquals($expectedCommand, $command);

        $this->assertEquals(CommandController::class, $command['controller']);
        $this->assertEquals('testArg', $command['method']);

        $this->assertEmpty($command['middleware']);
    }

    public function testArgCommandResolvingWithArgNotProvided()
    {
        $this->router->loadControllers([CommandController::class]);

        $command = $this->router->resolve(new ConsoleRequest('testarg', []));

        $expectedCommand = $this->router->getCommands()['testarg'];
        $expectedCommand['params'] = [];
        $this->assertEquals($expectedCommand, $command);

        $this->assertEquals(CommandController::class, $command['controller']);
        $this->assertEquals('testArg', $command['method']);

        $this->assertEmpty($command['middleware']);
    }
}