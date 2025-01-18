<?php

namespace Diana\IO\Event;

use Diana\Contracts\Core\Container;
use Diana\Contracts\Event\Dispatcher as DispatcherContract;
use Diana\Events\RegisterPackageEvent;
use Diana\Framework\Core\Application;
use Diana\IO\Event\Attributes\EventListener as EventListenerAttribute;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class Dispatcher implements DispatcherContract
{
    /**
     * @var callable[][] $eventListeners
     */
    protected array $eventListeners = [];

    /**
     * @throws Exception
     */
    public function __construct(
        protected Application $app,
        protected Container $container
    ) {
        $this->addEventListener(RegisterPackageEvent::class, [$this, 'loadEventListeners'], ['*']);
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function loadEventListeners(RegisterPackageEvent $event): void
    {
        $package = $event->getPackage();
        $reflectionClass = new ReflectionClass($package);
        foreach ($reflectionClass->getMethods() as $classMethod) {
            $reflectionMethod = new ReflectionMethod($package, $classMethod->name);
            $attributes = $reflectionMethod->getAttributes();

            foreach ($attributes as $attribute) {
                $attributeName = $attribute->getName();

                if ($attributeName != EventListenerAttribute::class) {
                    continue;
                }

                $attributeArgs = $attribute->getArguments();

                $event = $attributeArgs['event'] ?? array_shift($attributeArgs);
                $before = $attributeArgs['before'] ?? array_shift($attributeArgs) ?: [];
                $after = $attributeArgs['after'] ?? array_shift($attributeArgs) ?: [];
                $eventListener = [$package, $classMethod->name];

                $this->addEventListener($event, $eventListener, $before, $after);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function addEventListener(
        string $event,
        callable $eventListener,
        array $before = [],
        array $after = []
    ): callable {
        if (!isset($this->eventListeners[$event])) {
            $this->eventListeners[$event] = [$eventListener];
        } else {
            $position = count($this->eventListeners[$event]);
            $min = INF;

            foreach ($before as $beforeEventListener) {
                if ($beforeEventListener == '*') {
                    $min = 0;
                } else {
                    $index = array_search($beforeEventListener, $this->eventListeners[$event]);
                    if ($index !== false) {
                        $min = min($position, $index - 1);
                    }
                }
                $position = $min;
            }

            foreach ($after as $afterEventListener) {
                $index = array_search($afterEventListener, $this->eventListeners[$event]);
                if ($index !== false) {
                    $position = max($position, $index + 1);
                }
            }

            if ($position > $min) {
                throw new Exception('Invalid event listener ruleset');
            }

            array_splice($this->eventListeners[$event], $position, 0, [$eventListener]);
        }
        return $eventListener;
    }

    public function addSingleEventListener(
        string $event,
        callable $eventListener,
        array $before = [],
        array $after = []
    ): callable {
        $wrapper = function (...$args) use ($event, $eventListener, &$wrapper) {
            $success = $this->removeEventListener($event, $wrapper);
            if (!$success) {
                throw new Exception('Unable to remove event listener');
            }
            return $eventListener(...$args);
        };
        return $this->addEventListener($event, $wrapper, $before, $after);
    }

    public function removeEventListener(string $event, callable $eventListener): bool
    {
        $key = array_search($eventListener, $this->eventListeners[$event]);
        $success = $key !== false;
        if ($success) {
            unset($this->eventListeners[$event][$key]);
        }
        return $success;
    }

    // todo: replace object with BaseEventMessage class?
    public function dispatch(object $event): void
    {
        $class = get_class($event);

        foreach ($this->eventListeners[$class] ?? [] as $eventListener) {
            $this->container->call($eventListener, [$class => $event]);
        }
    }
}
