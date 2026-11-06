<?php

namespace Diana\Tests\Core;

use Diana\Framework\Core\Environment;
use PHPUnit\Framework\TestCase;

class EnvironmentTest extends TestCase
{
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('APP_ENV');
        putenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false ? 'APP_ENV' : 'APP_ENV=' . $this->previous);
    }

    public function testSafeDefaultAndExactComparisonsWithoutAliases(): void
    {
        $environment = new Environment();
        self::assertSame(Environment::PROD, $environment->name());
        self::assertTrue($environment->is(Environment::PROD));
        self::assertFalse($environment->is('production', '', 'PROD'));
        $environment->configure('production');
        self::assertFalse($environment->is(Environment::PROD));
        self::assertFalse($environment->is(Environment::DEV));
        self::assertTrue($environment->is('production'));
        $environment->configure(Environment::DEV);
        self::assertTrue($environment->is(Environment::DEV));
        self::assertFalse($environment->is('DEV', 'development'));
        $environment->configure('staging');
        self::assertTrue($environment->is(Environment::DEV, 'staging'));
        self::assertFalse($environment->is(Environment::DEV));
    }

    public function testProcessValueIsCapturedAndOverridesConfigurationIncludingEmptyValue(): void
    {
        foreach ([Environment::PROD, Environment::DEV, '', 'DEV', 'testing'] as $value) {
            putenv('APP_ENV=' . $value);
            $environment = new Environment();
            putenv('APP_ENV=changed-after-start');
            $environment->configure($value === Environment::DEV ? Environment::PROD : Environment::DEV);
            self::assertSame($value === '' ? Environment::PROD : $value, $environment->name());
            self::assertSame($value === Environment::DEV, $environment->is(Environment::DEV));
        }
    }

    public function testEnvironmentInstancesDoNotShareState(): void
    {
        $first = new Environment();
        $second = new Environment();
        $first->configure(Environment::DEV);
        self::assertTrue($first->is(Environment::DEV));
        self::assertTrue($second->is(Environment::PROD));
    }
}
