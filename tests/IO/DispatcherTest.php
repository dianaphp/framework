<?php

namespace Diana\Tests\IO;

use Diana\Framework\Core\Application;
use Diana\IO\Event\Dispatcher;
use Diana\Runtime\IlluminateContainer;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

class DispatcherTest extends TestCase
{
    private Dispatcher $dispatcher;
    private array $calls = [];

    protected function setUp(): void
    {
        $this->dispatcher = new Dispatcher(
            $this->createStub(Application::class),
            new IlluminateContainer()
        );
    }

    private function listener(string $name): callable
    {
        return function () use ($name): void {
            $this->calls[] = $name;
        };
    }

    public static function insertionRules(): iterable
    {
        yield 'append by default' => [[], [], ['A', 'B', 'C', 'new']];
        yield 'before first' => [['A'], [], ['new', 'A', 'B', 'C']];
        yield 'before last' => [['C'], [], ['A', 'B', 'new', 'C']];
        yield 'before all' => [['*'], [], ['new', 'A', 'B', 'C']];
        yield 'multiple before rules' => [['C', 'A'], [], ['new', 'A', 'B', 'C']];
        yield 'reversed before rules' => [['A', 'C'], [], ['new', 'A', 'B', 'C']];
        yield 'after first' => [[], ['A'], ['A', 'B', 'C', 'new']];
        yield 'after last' => [[], ['C'], ['A', 'B', 'C', 'new']];
        yield 'multiple after rules' => [['C'], ['B', 'A'], ['A', 'B', 'new', 'C']];
        yield 'reversed after rules' => [['C'], ['A', 'B'], ['A', 'B', 'new', 'C']];
        yield 'unknown before' => [['missing'], [], ['A', 'B', 'C', 'new']];
        yield 'unknown after' => [[], ['missing'], ['A', 'B', 'C', 'new']];
        yield 'unknown then known before' => [['missing', 'B'], [], ['A', 'new', 'B', 'C']];
        yield 'known then unknown before' => [['B', 'missing'], [], ['A', 'new', 'B', 'C']];
        yield 'unknown with valid bounds' => [['missing', 'C'], ['missing', 'B'], ['A', 'B', 'new', 'C']];
        yield 'wildcard with unknown' => [['missing', '*'], ['missing'], ['new', 'A', 'B', 'C']];
    }

    #[DataProvider('insertionRules')]
    public function testDispatchRespectsInsertionRules(array $before, array $after, array $expected): void
    {
        $listeners = ['missing' => $this->listener('missing')];
        foreach (['A', 'B', 'C'] as $name) {
            $listeners[$name] = $this->dispatcher->addEventListener(stdClass::class, $this->listener($name));
        }
        $listener = $this->listener('new');
        self::assertSame($listener, $this->dispatcher->addEventListener(
            stdClass::class,
            $listener,
            array_map(static fn ($name) => $listeners[$name] ?? $name, $before),
            array_map(static fn ($name) => $listeners[$name], $after)
        ));

        $this->dispatcher->dispatch(new stdClass());
        self::assertSame($expected, $this->calls);
    }

    public static function conflictingRules(): iterable
    {
        yield 'same reference' => [['A'], ['A']];
        yield 'inverted bounds' => [['A'], ['B']];
        yield 'wildcard conflicts with after' => [['*'], ['A']];
        yield 'multiple rules conflict' => [['C', 'A'], ['B']];
    }

    #[DataProvider('conflictingRules')]
    public function testConflictingRulesLeaveListenersUnchanged(array $before, array $after): void
    {
        $listeners = [];
        foreach (['A', 'B', 'C'] as $name) {
            $listeners[$name] = $this->dispatcher->addEventListener(stdClass::class, $this->listener($name));
        }

        try {
            $this->dispatcher->addEventListener(
                stdClass::class,
                $this->listener('new'),
                array_map(static fn ($name) => $listeners[$name] ?? $name, $before),
                array_map(static fn ($name) => $listeners[$name], $after)
            );
            self::fail('Conflicting listener rules must be rejected.');
        } catch (Exception $exception) {
            self::assertSame('Invalid event listener ruleset', $exception->getMessage());
        }

        $this->dispatcher->dispatch(new stdClass());
        self::assertSame(['A', 'B', 'C'], $this->calls);
    }

    public function testInsertionAfterRemovalUsesCurrentPositions(): void
    {
        $a = $this->dispatcher->addEventListener(stdClass::class, $this->listener('A'));
        $b = $this->dispatcher->addEventListener(stdClass::class, $this->listener('B'));
        $c = $this->dispatcher->addEventListener(stdClass::class, $this->listener('C'));
        self::assertTrue($this->dispatcher->removeEventListener(stdClass::class, $a));
        $this->dispatcher->addEventListener(stdClass::class, $this->listener('new'), [$c], [$b]);

        $this->dispatcher->dispatch(new stdClass());
        self::assertSame(['B', 'new', 'C'], $this->calls);
    }

    public function testSingleListenerKeepsOrderAndRunsOnlyOnce(): void
    {
        $a = $this->dispatcher->addEventListener(stdClass::class, $this->listener('A'));
        $this->dispatcher->addEventListener(stdClass::class, $this->listener('B'));
        $this->dispatcher->addSingleEventListener(stdClass::class, $this->listener('once'), [$a]);

        $this->dispatcher->dispatch(new stdClass());
        $this->dispatcher->dispatch(new stdClass());
        self::assertSame(['once', 'A', 'B', 'A', 'B'], $this->calls);
    }

    public function testFirstListenerIgnoresUnknownReferences(): void
    {
        $unknown = $this->listener('missing');
        $this->dispatcher->addEventListener(stdClass::class, $this->listener('first'), [$unknown], [$unknown]);
        $this->dispatcher->dispatch(new stdClass());
        self::assertSame(['first'], $this->calls);
    }
}
