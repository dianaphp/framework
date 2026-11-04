<?php

namespace Diana\Tests\Core;

use Diana\Config\Config;
use Diana\Runtime\KernelModules\ExceptionHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Whoops\Handler\PlainTextHandler;
use Whoops\Run;

class ExceptionHandlerTest extends TestCase
{
    private function runner(?string $environment): Run
    {
        $config = new Config('framework');
        if ($environment !== null) {
            $config->set('env', $environment);
        }
        return (new class($config) extends ExceptionHandler {
            public function runner(): Run { return $this->createRun(); }
        })->runner();
    }

    public function testProductionAndMissingEnvironmentHideDetailsAndLogExceptions(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'diana-errors-');
        $previous = ini_set('error_log', $log);
        try {
            foreach (['production', null] as $environment) {
                $run = $this->runner($environment);
                $run->allowQuit(false);
                $run->writeToOutput(false);
                $output = $run->handleException(new RuntimeException('secret database password'));
                self::assertSame("Internal Server Error\n", $output);
            }
            self::assertStringContainsString('secret database password', file_get_contents($log));
        } finally {
            ini_set('error_log', $previous);
            unlink($log);
        }
    }

    public function testDevelopmentRetainsDetailedConsoleHandler(): void
    {
        self::assertInstanceOf(PlainTextHandler::class, $this->runner('dev')->getHandlers()[0]);
    }
}
