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
    }

    public function __invoke(): void
    {
        $this->createRun()->register();
    }

    protected function createRun(): Run
    {
        $run = new Run();
        if (($this->config->all()['env'] ?? null) === 'dev') {
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
