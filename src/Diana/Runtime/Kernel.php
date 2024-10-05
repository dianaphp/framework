<?php

namespace Diana\Runtime;

use Closure;
use Diana\Config\Attributes\Config;
use Diana\Config\ConfigInterface;
use Diana\Controllers\CoreCommandsController;
use Diana\Controllers\StubCommandsController;
use Diana\IO\Exceptions\PipelineException;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\IO\Pipeline;
use Diana\IO\Request;
use Diana\IO\Response;
use Diana\Routing\Exceptions\CommandNotFoundException;
use Diana\Routing\Exceptions\RouteNotFoundException;
use Diana\Routing\Router;
use Diana\Support\Exceptions\FileNotFoundException;
use Diana\Support\Helpers\Filesystem;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

class Kernel extends Package
{
    /**
     * @var array The currently loaded controllers
     */
    protected array $controllers = [
        CoreCommandsController::class,
        StubCommandsController::class
    ];

    /*
     * @var array The global middleware stack
     */
    protected array $middleware = [];

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws FileNotFoundException
     */
    public function __construct(
        protected Container $container,
        protected Application $app,
        #[Config('framework')] protected ConfigInterface $config
    ) {
        $this->path = dirname(__DIR__, 3);
        $this->config->setDefault(Filesystem::getRequire($this->path('configs/framework.default.php')));

        $this->registerPhpIni();
        $this->registerExceptionHandler();
        $this->provideAliases();

        $this->app->registerPackage($this->config->get('entryPoint'));
        foreach ($this->config->get('bindings') as $abstract => $concrete) {
            $this->container->singleton($abstract, $concrete);
        }
    }

    protected function registerPhpIni(): void
    {
        error_reporting(E_ALL);

        $env = $this->config->get('env');
        ini_set('display_errors', $env == 'dev' ? 'On' : 'Off');

        $logs = $this->config->get('logs');
        ini_set('log_errors', 'On');

        ini_set('error_log', $this->app->path($logs['error']));
        ini_set('access_log', $this->app->path($logs['access']));

        ini_set('date.timezone', $this->config->get('timezone'));

        ini_set('xdebug.var_display_max_depth', 10);
        ini_set('xdebug.var_display_max_children', 256);
        ini_set('xdebug.var_display_max_data', 1024);
        //ini_set('xdebug.max_nesting_level', 9999);
    }

    protected function registerExceptionHandler(): void
    {
        (new Run())
            ->pushHandler($this->app->getSapi() == 'cli' ? new PlainTextHandler() : new PrettyPageHandler())
            ->register();
    }

    protected function provideAliases(): void
    {
        // cache ide helpers
        // TODO: make this a command
        // TODO: outsource to cache class
        if (!file_exists($cachePath = $this->app->path($this->config->get('aliasCachePath')))) {
            $cache = "<?php" . str_repeat(PHP_EOL, 2);

            foreach ($this->config->get('aliases') as $class) {
                $cache .= "class " . substr($class, strrpos($class, '\\') + 1) . " extends $class {}" . PHP_EOL;
            }

            file_put_contents($cachePath, $cache);
        }

        // provide aliases
        foreach ($this->config->get('aliases') as $class) {
            class_alias($class, substr($class, strrpos($class, '\\') + 1));
        }
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws PipelineException
     * @throws UnexpectedOutputTypeException
     * @throws ContainerExceptionInterface
     * @throws BindingResolutionException
     */
    public function handleRequest(Request $request): Response
    {
        $router = $this->container->get(Router::class);
        $router->load($this->app->path($this->config->get('routeCachePath')), fn() => $this->controllers);

        try {
            $resolution = $router->resolve($request);
        } catch (RouteNotFoundException) {
            if (!$resolution = $router->getErrorRouteHandler()) {
                return new Response("HTTP Error 404 - This page could not be found.", 404);
            } else {
                $resolution['params']['errorCode'] = 404;
            }
        } catch (CommandNotFoundException) {
            if (!$resolution = $router->getErrorCommandHandler()) {
                return new Response("This command could not be found.", 1);
            } else {
                $resolution['params']['errorCode'] = 1;
            }
        }

        return (new Pipeline($this->container))
            ->pipe($this->middleware)
            ->pipe($resolution['middleware'])
            ->pipe(function () use ($resolution) {
                return new Response(
                    $this->container->call(
                        $resolution['controller'] . '@' . $resolution['method'],
                        $resolution['params']
                    ),
                    $resolution['params']['errorCode'] ?? ($this->app->getSapi() == 'cli' ? 0 : 200)
                );
            })
            ->expect(Response::class);
    }

    public function registerMiddleware(string|Closure $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function registerController(string ...$controllers): static
    {
        foreach ($controllers as $controller) {
            if (!in_array($controller, $this->controllers)) {
                $this->controllers[] = $controller;
            }
        }

        return $this;
    }
}
