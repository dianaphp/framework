<?php

namespace Diana\Runtime\KernelModules;

use Diana\Config\Config;
use Diana\Interfaces\ExceptionHandlerInterface;
use ErrorException;
use Throwable;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

class ExceptionHandler implements KernelModule
{
    private array $handlers = [];

    public function __construct(#[Config('framework')] protected Config $config)
    {
//        $this->config->addDefault(['exceptionHandlers' => []]);
    }

    public function __invoke(): void
    {
        (new Run())
            ->pushHandler(php_sapi_name() == 'cli' ? new PlainTextHandler() : new PrettyPageHandler())
            ->register();

//        $previous = set_error_handler([$this, 'handleError']);
//        if ($previous) {
//            $this->addHandler(new class ($previous) implements ExceptionHandlerInterface {
//                public function __construct(protected Closure|array|string $previous)
//                {
//                }
//
//                public function supports(Throwable $exception): bool
//                {
//                    return $exception instanceof ErrorException;
//                }
//
//                public function handle(Throwable $exception): bool
//                {
//                    ($this->previous)($exception);
//                    return true;
//                }
//            });
//        }

//        $previous = set_exception_handler([$this, 'handle']);
//        if ($previous) {
//            $this->addHandler(new class ($previous) implements ExceptionHandlerInterface {
//                public function __construct(protected Closure|array|string $previous)
//                {
//                }
//
//                public function supports(Throwable $exception): bool
//                {
//                    return true;
//                }
//
//                public function handle(Throwable $exception): bool
//                {
//                    ($this->previous)($exception);
//                    return true;
//                }
//            });
//        }
    }

    public function addHandler(ExceptionHandlerInterface $handler): void
    {
        array_unshift($this->handlers, $handler);
    }

    /**
     * @throws Throwable
     */
    public function handle(Throwable $exception): void
    {
        foreach ($this->handlers as $handler) {
            var_dump($handler);
            if ($handler->supports($exception)) {
                $success = $handler->handle($exception);
                if ($success) {
                    var_dump($handler);
                    return;
                }
            }
        }

//        throw $exception;
    }

    /**
     * @throws ErrorException
     */
    public function handleError($severity, $message, $filename, $lineno): void
    {
        throw new ErrorException($message, 0, $severity, $filename, $lineno);
    }
}
