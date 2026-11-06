<?php

namespace Diana\Tests\Core;

use Diana\Framework\Core\Environment;
use Diana\Runtime\KernelModules\ExceptionHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Whoops\Handler\PlainTextHandler;
use Whoops\Run;

class ExceptionHandlerTest extends TestCase
{
    private string|false $previousEnvironment;

    protected function setUp(): void
    {
        $this->previousEnvironment = getenv('APP_ENV');
        putenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        putenv($this->previousEnvironment === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousEnvironment);
    }

    private function runner(?string $environment): Run
    {
        $env = new Environment();
        $env->configure($environment);
        return (new class($env) extends ExceptionHandler {
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

    public function testDeploymentEnvironmentOverridesConfiguredDevelopment(): void
    {
        foreach (['production', '', 'DEV', 'testing'] as $environment) {
            putenv('APP_ENV=' . $environment);
            self::assertNotInstanceOf(PlainTextHandler::class, $this->runner('dev')->getHandlers()[0]);
        }
        putenv('APP_ENV=dev');
        self::assertInstanceOf(PlainTextHandler::class, $this->runner('production')->getHandlers()[0]);
    }

    public function testDevelopmentRetainsDetailedConsoleHandler(): void
    {
        self::assertInstanceOf(PlainTextHandler::class, $this->runner('dev')->getHandlers()[0]);
    }
}
