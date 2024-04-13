<?php

namespace Diana\IO;

use Closure;
use Diana\IO\Request;
use Diana\IO\Response;
use Diana\IO\Contracts\Kernel as KernelContract;
use Diana\Runtime\Container;
use Diana\Support\Helpers\Filesystem;
use RuntimeException;
use Diana\IO\Pipeline;

use Diana\Contracts\Middleware;

class Kernel implements KernelContract
{
    protected array $middleware = [];

    protected Pipeline $pipeline;

    protected $requestHandler;

    public function registerMiddleware(string|Closure $middleware): void
    {
        if (is_string($middleware) && is_a($middleware, Middleware::class))
            throw new RuntimeException('Attempted to register a middleware [' . $middleware . '] that does not implement Middleware.');

        $this->middleware[] = $middleware;
    }

    protected function setExceptionHandler(): void
    {
        // TODO: clean up
        error_reporting(E_ALL);
        ini_set('display_errors', true ? 'On' : 'Off');
        ini_set('log_errors', 'On');
        ini_set('error_log', Filesystem::absPath('./logs/error.log'));
        ini_set('access_log', Filesystem::absPath('./logs/access.log'));
        ini_set('date.timezone', 'Europe/Berlin');

        ini_set('xdebug.var_display_max_depth', 10);
        ini_set('xdebug.var_display_max_children', 256);
        ini_set('xdebug.var_display_max_data', 1024);
        //ini_set('xdebug.max_nesting_level', 9999);

        $whoops = new \Whoops\Run;
        $whoops->pushHandler(new \Whoops\Handler\PlainTextHandler);
        $whoops->register();

        // set_exception_handler(function ($error) {
        //     return Exception::handleException($error);
        // });

        // register_shutdown_function(function () {
        //     if ($error = error_get_last()) {
        //         ob_end_clean();
        //         FatalCodeException::throw ($error['message'], $error["type"], 0, $error["file"], $error["line"]);
        //     }
        // });

        // set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        //     CodeException::throw ($errstr, $errno, 0, $errfile, $errline);
        // });
    }

    public function __construct(private Container $container)
    {
        $this->setExceptionHandler();
    }

    public function run(Request $request): Response
    {
        $this->container->instance(Request::class, $request);

        return (new Pipeline($this->container))
            ->send($request, new Response())
            ->pipe($this->middleware)
            ->expect(Response::class);
    }

    public function terminate(): void
    {
    }
}