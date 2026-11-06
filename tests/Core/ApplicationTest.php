<?php

namespace Diana\Tests\Core;

use Composer\Autoload\ClassLoader;
use Diana\Config\Config;
use Diana\Contracts\Core\Container;
use Diana\Contracts\Core\Pipeline as PipelineContract;
use Diana\Contracts\Event\Dispatcher;
use Diana\Contracts\RequestContract;
use Diana\Events\BootEvent;
use Diana\Events\RegisterPackageEvent;
use Diana\Events\ShutdownEvent;
use Diana\Framework\Core\Application;
use Diana\Framework\Core\Environment;
use Diana\IO\Event\NullDispatcher;
use Diana\IO\HttpRequest;
use Diana\IO\Pipeline;
use Diana\IO\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        LifecycleTrace::$events = [];
        TestPipeline::$stages = 0;
        TestEntryPoint::$calls = 0;
    }

    private function app(array $values = []): TestApplication
    {
        return new TestApplication('/example/app', new ClassLoader(), new TestConfig([
            'entrypoint' => TestEntryPoint::class,
            'singleton' => [Dispatcher::class => RecordingDispatcher::class],
            ...$values,
        ]));
    }

    public function testMissingEnvironmentDefaultsToProduction(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV');
        try {
            $app = $this->app();
            self::assertSame(Environment::PROD, $app->container()->get(Environment::class)->name());
            self::assertSame($app->container()->get(Environment::class), $app->container()->make(Environment::class));
        } finally {
            putenv($previous === false ? 'APP_ENV' : 'APP_ENV=' . $previous);
        }
    }

    public function testEnvironmentIsCapturedBeforeConfigFactoryAndInjectedAsTheSameInstance(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=' . Environment::DEV);
        $config = new TestConfig(['env' => Environment::PROD, 'entrypoint' => EnvironmentEntryPoint::class]);
        try {
            $app = new TestApplication('/example', new ClassLoader(), static function () use ($config): Config {
                putenv('APP_ENV=' . Environment::PROD);
                return $config;
            });
            $environment = $app->container()->get(Environment::class);
            self::assertSame($environment, $app->container()->make(EnvironmentEntryPoint::class)->environment);
            self::assertSame(Environment::DEV, $environment->name());
            self::assertSame(Environment::DEV, (string) $app->generateResponse(new HttpRequest('/')));
            self::assertSame(Environment::PROD, $config->get('env'));
            $other = $this->app()->container()->get(Environment::class);
            self::assertNotSame($environment, $other);
            self::assertTrue($other->is(Environment::PROD));
        } finally {
            putenv($previous === false ? 'APP_ENV' : 'APP_ENV=' . $previous);
        }
    }

    public function testBootOrderIncludesNestedPackageRegistration(): void
    {
        $this->app();

        self::assertSame([
            'module',
            'package:' . ChildEntryPoint::class,
            'package:' . TestEntryPoint::class,
            'boot',
        ], LifecycleTrace::$events);
    }

    public function testDefaultPipelineBindingIsTransient(): void
    {
        $app = $this->app();
        $container = $app->container();
        self::assertInstanceOf(Pipeline::class, $container->make(PipelineContract::class));
        self::assertNotSame($container->make(PipelineContract::class), $container->make(PipelineContract::class));
        self::assertSame($app, $container->get(Application::class));
        self::assertSame($container, $container->get(Container::class));
    }

    public function testRequestProcessingUsesConfiguredPipelineImplementation(): void
    {
        $app = $this->app(['bindings' => [PipelineContract::class => TestPipeline::class]]);
        $response = $app->generateResponse(new HttpRequest('/example'));

        self::assertSame('response', (string) $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertGreaterThan(0, TestPipeline::$stages);
    }

    public function testGenerateResponseDoesNotEmitOrShutDown(): void
    {
        $app = $this->app();
        $before = LifecycleTrace::$events;
        self::assertSame('response', (string) $app->generateResponse(new HttpRequest('/example')));
        self::assertSame($before, LifecycleTrace::$events);
    }

    public function testHandleRequestEmitsAndDispatchesShutdown(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'diana-lifecycle-');
        try {
            $app = $this->app(['output' => $output]);
            $request = new HttpRequest('/example');
            $app->handleRequest($request);

            self::assertSame('response', file_get_contents($output));
            self::assertSame($request, $app->container()->get(RequestContract::class));
            self::assertSame('shutdown:200', LifecycleTrace::$events[array_key_last(LifecycleTrace::$events)]);
        } finally {
            unlink($output);
        }
    }

    public function testCommandAndPathFacadeRemainAvailable(): void
    {
        $app = $this->app();
        self::assertSame('response', (string) $app->runCommand('example'));
        self::assertSame(0, $app->runCommand('example')->getStatusCode());
        self::assertSame('/example/app/res/view.php', $app->path('res/view.php'));
    }

    public function testCommandArgumentListPreservesValuesVerbatim(): void
    {
        $app = $this->app();
        $arguments = ['two words', '', '0', 'a"b', 'C:\\tmp', '$HOME'];
        $app->runCommand('example', $arguments);
        $request = $app->container()->get(RequestContract::class);
        self::assertSame('example', $request->getCommand());
        self::assertSame($arguments, $request->getArguments());
    }

    public function testCommandStringSupportsWhitespaceQuotesEscapesAndEmptyValues(): void
    {
        $app = $this->app();
        $app->runCommand(<<<'COMMAND'
          example  "two words" '' pre"fix" 'literal\path' escaped\ space "$HOME"
COMMAND);
        $request = $app->container()->get(RequestContract::class);
        self::assertSame('example', $request->getCommand());
        self::assertSame(['two words', '', 'prefix', 'literal\\path', 'escaped space', '$HOME'], $request->getArguments());
    }

    public function testMalformedCommandStringsAreRejectedBeforeDispatch(): void
    {
        $app = $this->app();
        foreach (['', " \t", 'example "unclosed', "example trailing\\"] as $command) {
            try {
                $app->runCommand($command);
                self::fail('Malformed command accepted: ' . $command);
            } catch (InvalidArgumentException) {
                self::assertSame(0, TestEntryPoint::$calls);
            }
        }
    }

    public function testHeadSuppressesTheBodyAfterGlobalMiddlewareIncludingErrors(): void
    {
        $app = $this->app(['middleware' => [static fn () => new Response('error', 405, ['Allow' => 'GET, HEAD'])]]);
        $response = $app->generateResponse(new HttpRequest('/example', 'HEAD'));
        self::assertSame('', (string) $response);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeader('Allow'));
    }

    public function testPackageRegistrationStillSkipsExistingInstancesUnlessForced(): void
    {
        $app = $this->app();
        $before = count(LifecycleTrace::$events);
        $app->registerPackage(ChildEntryPoint::class);
        self::assertCount($before, LifecycleTrace::$events);
        $app->registerPackage(ChildEntryPoint::class, true);
        self::assertSame('package:' . ChildEntryPoint::class, LifecycleTrace::$events[array_key_last(LifecycleTrace::$events)]);
    }

    public function testRegistrationReturnsTheSameObjectForAnAlreadyRegisteredModule(): void
    {
        $app = $this->app();
        $first = $app->registerPackage(ChildEntryPoint::class);
        $second = $app->registerPackage(ChildEntryPoint::class);

        self::assertSame($first, $second);
        self::assertSame($first, $app->container()->get(ChildEntryPoint::class));
    }

    public function testPreboundPackagesStillDispatchTheirRegistrationEventExactlyOnce(): void
    {
        $app = $this->app();
        $package = new PreboundPackage();
        $app->container()->instance(PreboundPackage::class, $package);
        $before = count(LifecycleTrace::$events);

        self::assertSame($package, $app->registerPackage(PreboundPackage::class));
        self::assertSame($package, $app->registerPackage(PreboundPackage::class));
        self::assertSame(['package:' . PreboundPackage::class], array_slice(LifecycleTrace::$events, $before));
    }

    public function testGlobalMiddlewareCanReturnWithoutCallingTheEntryPoint(): void
    {
        $blocked = new Response('blocked', 403);
        $app = $this->app(['middleware' => [static fn () => $blocked]]);

        self::assertSame($blocked, $app->generateResponse(new HttpRequest('/example')));
        self::assertSame(0, TestEntryPoint::$calls);
    }

    public function testGlobalMiddlewareReceivesTheCurrentRequestAndWrapsTheEntryPoint(): void
    {
        $request = new HttpRequest('/example');
        $calls = [];
        $app = $this->app(['middleware' => [function ($input, $next) use ($request, &$calls): Response {
            self::assertSame($request, $input);
            self::assertSame(0, TestEntryPoint::$calls);
            $calls[] = 'before';
            $response = $next();
            self::assertSame(1, TestEntryPoint::$calls);
            $calls[] = 'after';
            return $response;
        }]]);

        self::assertSame('response', (string) $app->generateResponse($request));
        self::assertSame(['before', 'after'], $calls);
        self::assertSame($request, $app->container()->get(RequestContract::class));
    }

    public function testEntryPointResponseAndStatusCodeArePreserved(): void
    {
        $app = $this->app(['entrypoint' => ResponseEntryPoint::class]);
        $response = $app->generateResponse(new HttpRequest('/example'));
        self::assertSame(ResponseEntryPoint::$response, $response);
        self::assertSame(202, $response->getStatusCode());
    }

    public function testEntryPointMayReturnAValueAndReceiveInjectedServices(): void
    {
        $app = $this->app(['entrypoint' => ValueEntryPoint::class]);
        self::assertSame('injected', (string) $app->generateResponse(new HttpRequest('/example')));
        self::assertSame(0, $app->runCommand('example')->getStatusCode());
    }

    public function testEntryPointMustBeInvokable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('__invoke');
        $this->app(['entrypoint' => ChildEntryPoint::class]);
    }

    public function testAnyRequestContractCanBeProcessedWithoutHttpOrConsole(): void
    {
        $request = $this->createStub(RequestContract::class);
        $request->method('getDefaultStatusCode')->willReturn(7);
        $response = $this->app()->generateResponse($request);

        self::assertSame('response', (string) $response);
        self::assertSame(7, $response->getStatusCode());
    }
}

class TestApplication extends Application
{
    protected array $modules = [TestModule::class];

    public function container(): Container { return $this->container; }
    public function shutdown(int $status = 0): void { $this->dispatcher->dispatch(new ShutdownEvent($status)); }
}

class TestConfig extends Config
{
    public function __construct(array $values)
    {
        parent::__construct('framework');
        $this->config = $values;
    }

    public function addDefault(array $default): self
    {
        parent::addDefault($default);
        $this->config = $this->arrayMergeRecursiveDistinct($default, $this->config);
        return $this;
    }
}

class LifecycleTrace
{
    public static array $events = [];
}

class TestModule
{
    public function __invoke(): void { LifecycleTrace::$events[] = 'module'; }
}

class TestEntryPoint
{
    public static int $calls = 0;

    public function __construct(Application $app) { $app->registerPackage(ChildEntryPoint::class); }

    public function __invoke(RequestContract $request): Response
    {
        self::$calls++;
        return new Response('response', $request->getDefaultStatusCode());
    }
}

class ChildEntryPoint {}

class PreboundPackage {}

class ResponseEntryPoint
{
    public static Response $response;

    public function __invoke(): Response
    {
        return self::$response = new Response('accepted', 202);
    }
}

class ValueEntryPoint
{
    public function __invoke(EntryPointService $service, RequestContract $request): string
    {
        return $service->value();
    }
}

class EntryPointService
{
    public function value(): string { return 'injected'; }
}

class RecordingDispatcher extends NullDispatcher
{
    public function dispatch(object $event): void
    {
        if ($event instanceof RegisterPackageEvent) {
            LifecycleTrace::$events[] = 'package:' . $event->getPackage()::class;
        } elseif ($event instanceof BootEvent) {
            LifecycleTrace::$events[] = 'boot';
        } elseif ($event instanceof ShutdownEvent) {
            LifecycleTrace::$events[] = 'shutdown:' . $event->getStatusCode();
        }
    }
}

class TestPipeline extends Pipeline
{
    public static int $stages = 0;

    public function pipe(\Closure|array|string $pipes = []): static
    {
        self::$stages++;
        return parent::pipe($pipes);
    }
}

class EnvironmentEntryPoint
{
    public function __construct(public Environment $environment) {}

    public function __invoke(): string { return $this->environment->name(); }
}
