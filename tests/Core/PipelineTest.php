<?php

namespace Diana\Tests\Core;

use Diana\Contracts\Core\Container;
use Diana\Contracts\Core\Pipeline as PipelineContract;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\IO\Pipeline;
use Diana\Runtime\IlluminateContainer;
use PHPUnit\Framework\TestCase;

class PipelineTest extends TestCase
{
    private function container(): IlluminateContainer
    {
        $container = new IlluminateContainer();
        $container->instance(Container::class, $container);
        $container->bind(PipelineContract::class, Pipeline::class);
        return $container;
    }

    public function testImplementationSatisfiesSdkContract(): void
    {
        $pipeline = $this->container()->make(PipelineContract::class);
        self::assertInstanceOf(Pipeline::class, $pipeline);
    }

    public function testBindingCreatesFreshPipelines(): void
    {
        $container = $this->container();
        $first = $container->make(PipelineContract::class)->send('first', 'result', ['context']);
        $second = $container->make(PipelineContract::class);

        self::assertNotSame($first, $second);
        self::assertSame('result', $first->finalize());
        self::assertNull($second->finalize());
    }

    public function testGenericInputOutputAndDataWithoutHttp(): void
    {
        $result = $this->container()->make(PipelineContract::class)
            ->send(5, 7, ['multiplier' => 3])
            ->pipe(fn ($input, $next, $output, $data) => ($input + $output) * $data['multiplier'])
            ->expect('integer');

        self::assertSame(36, $result);
    }

    public function testMiddlewareCanShortCircuitWithinOneStage(): void
    {
        $called = false;
        $result = $this->container()->make(PipelineContract::class)
            ->send('input')
            ->pipe([
                fn () => 'blocked',
                function () use (&$called) { $called = true; return 'unexpected'; },
            ])
            ->expect('string');

        self::assertSame('blocked', $result);
        self::assertFalse($called);
    }

    public function testNestedMiddlewareOrder(): void
    {
        $calls = [];
        $middleware = function ($input, $next) use (&$calls) {
            $calls[] = 'before';
            $result = $next();
            $calls[] = 'after';
            return $result;
        };

        $result = $this->container()->make(PipelineContract::class)->pipe([
            $middleware,
            function () use (&$calls) { $calls[] = 'terminal'; return 42; },
        ])->finalize();

        self::assertSame(42, $result);
        self::assertSame(['before', 'terminal', 'after'], $calls);
    }

    public function testRegisteredMiddlewareClassIsResolvedThroughContainer(): void
    {
        self::assertSame('value:processed', $this->container()->make(PipelineContract::class)
            ->send('value')->pipe(GenericTestMiddleware::class)->finalize());
    }

    public function testExistingEagerStageSemanticsRemainUnchanged(): void
    {
        $pipeline = $this->container()->make(PipelineContract::class);
        $pipeline->pipe(fn () => 2);
        self::assertSame(2, $pipeline->finalize());

        $pipeline->pipe(fn ($input, $next, $output) => $output + 3);
        self::assertSame(5, $pipeline->finalize());
    }

    public function testUnexpectedOutputTypeThrows(): void
    {
        $this->expectException(UnexpectedOutputTypeException::class);
        $this->container()->make(PipelineContract::class)->pipe(fn () => 'string')->expect('integer');
    }

    public function testNextForwardsInputAndKeepsItForFollowingMiddleware(): void
    {
        $result = $this->container()->make(PipelineContract::class)->send('original')->pipe([
            fn ($input, $next) => $next('replaced'),
            fn ($input, $next) => $next(),
            fn ($input) => $input,
        ])->finalize();

        self::assertSame('replaced', $result);
    }

    public function testNextCanExplicitlyForwardNull(): void
    {
        $result = $this->container()->make(PipelineContract::class)->send('original')->pipe([
            fn ($input, $next) => $next(null),
            fn ($input) => $input,
        ])->finalize();

        self::assertNull($result);
    }

    public function testMiddlewareCanWrapTheInitialOutputWithoutATerminalPipe(): void
    {
        $pipeline = $this->container()->make(PipelineContract::class)->send('input', 'response');
        self::assertSame('response', $pipeline->pipe([])->finalize());
        self::assertSame('[response]', $pipeline->pipe(fn ($input, $next) => '[' . $next() . ']')->finalize());
    }
}

class GenericTestMiddleware
{
    public function run($input, $next, $output, $data): string
    {
        return $input . ':processed';
    }
}
