<?php

namespace Diana\Event;

use Diana\Drivers\ContainerInterface;
use Diana\Drivers\EventInterface;
use Diana\Drivers\EventManagerInterface;
use Diana\Drivers\EventListenerInterface;
use Diana\Event\Attributes\EventListener as EventListenerAttribute;
use Diana\Runtime\Framework;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class EventManager implements EventManagerInterface
{
    /**
     * @var EventListenerInterface[][][] $events
     */
    protected array $events = [];

    public function __construct(
        protected Framework $app,
        protected ContainerInterface $container
    ) {
        // if(!cached) {
        $this->addEventListener(new EventListener(
            class: Framework::class,
            action: 'registerPackage',
            callable: [$this, 'loadEventListeners'],
            before: ['*']
        ));
        // $this->cacheEventListeners();
        // }
    }

    /**
     * @throws ReflectionException
     */
    public function loadEventListeners(string $package, object $instance): void
    {
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

                $this->addEventListener(new EventListener(
                    ...$attributeArgs,
                    callable: [$instance, $classMethod->name]
                ));
            }
        }
    }

    public function addEventListener(EventListenerInterface $eventListener): void
    {
        $this->events[$eventListener->getClass()][$eventListener->getAction()][] = $eventListener;
    }

    public function removeEventListener(EventListenerInterface $eventListener): void
    {
        $class = $eventListener->getClass();
        $action = $eventListener->getAction();

        if (!isset($this->events[$class])) {
            return;
        }

        if (!isset($this->events[$class][$action])) {
            return;
        }

        foreach ($this->events[$class][$action] as $key => $listener) {
            if ($listener->getCallable() == $eventListener->getCallable()) {
                unset($this->events[$class][$action][$key]);
            }
        }
    }

    public function fire(EventInterface $event, string $action, array $payload = []): void
    {
        $class = $event->getClass();

        if (!isset($this->events[$class])) {
            return;
        }

        if (!isset($this->events[$class][$action])) {
            return;
        }

        // sort
        $this->sortListeners($class, $action);

        foreach ($this->events[$class][$action] as $listener) {
            $this->container->call($listener->getCallable(), [
                ...$payload,

                // TODO: i dont like this:
                'event' => $event,
                'eventListener' => $listener,
            ]);
        }
    }

    protected function sortListeners(string $class, string $action): void
    {
//        $sorted = [];
//        while (true) {
//            foreach($this->events[$class][$action] as $listener) {
//                $afterClasses = $listener->getAfter();
//                foreach ($afterClasses as $afterClass) {
//                    if (!in_array($afterClass, $sorted)) {
//                        continue 2;
//                    }
//                }
//            }
//        }
    }
}
