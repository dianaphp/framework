<?php

namespace Diana\Runtime\KernelModules;

use Diana\Framework\Core\Environment;
use Diana\Interfaces\ExceptionHandlerInterface;
use ErrorException;
use Throwable;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

class ExceptionHandler implements KernelModule
{
    private array $handlers = [];
    private ?Run $run = null;

    public function __construct(protected Environment $environment)
    {
    }

    public function __invoke(): void
    {
        // Whoops owns development output; native PHP output must never leak details.
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        $this->run?->unregister();
        $this->run = $this->createRun();
        $this->run->register();
    }

    protected function createRun(): Run
    {
        $run = new Run();
        if ($this->environment->is(Environment::DEV)) {
            return $run->pushHandler(PHP_SAPI === 'cli' ? new PlainTextHandler() : new PrettyPageHandler());
        }

        // Detailed exceptions go to the server log, never into the response.
        return $run->pushHandler(static function (Throwable $exception): int {
            error_log((string) $exception);
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo "Internal Server Error\n";
            return \Whoops\Handler\Handler::QUIT;
        });
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
            if ($handler->supports($exception)) {
                $success = $handler->handle($exception);
                if ($success) {
                    return;
                }
            }
        }
    }

    /**
     * @throws ErrorException
     */
    public function handleError($severity, $message, $filename, $lineno): void
    {
        throw new ErrorException($message, 0, $severity, $filename, $lineno);
    }
}
