<?php

namespace Diana\Event;

use Diana\Contracts\ContainerContract;
use Diana\Contracts\EventManagerContract;
use Diana\Contracts\EventListenerContract;
use Diana\Event\Attributes\EventListener as EventListenerAttribute;
use Diana\Event\Exceptions\EventListenerNotRegistered;
use Diana\Events\RegisterPackageEvent;
use Diana\Runtime\Framework;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class EventManager implements EventManagerContract
{
    /**
     * @var EventListenerContract[][] $eventListeners
     */
    protected array $eventListeners = [];

    public function __construct(
        protected Framework $app,
        protected ContainerContract $container
    ) {
        $this->addNewEventListener(RegisterPackageEvent::class, [$this, 'loadEventListeners'], ['*']);
    }

    /**
     * @throws ReflectionException
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
                $callable = [$package, $classMethod->name];

                $this->addEventListener($this->container->make(
                    EventListenerContract::class,
                    compact('event', 'callable', 'before', 'after')
                ));
            }
        }
    }

    public function createEventListener(
        string $event,
        array|string $callable,
        array $before = [],
        array $after = []
    ): EventListenerContract {
        return $this->container->make(
            EventListenerContract::class,
            compact('event', 'callable', 'before', 'after')
        );
    }

    public function addNewEventListener(
        string $event,
        array|string $callable,
        array $before = [],
        array $after = []
    ): EventListenerContract {
        $eventListener = $this->createEventListener($event, $callable, $before, $after);
        $this->addEventListener($eventListener);
        return $eventListener;
    }

    public function addNewSingleEventListener(
        string $event,
        array|string $callable,
        array $before = [],
        array $after = []
    ): EventListenerContract {
        $eventListener = $this->createEventListener($event, $callable, $before, $after);
        $this->addSingleEventListener($eventListener);
        return $eventListener;
    }

    public function addEventListener(EventListenerContract $eventListener): void
    {
        $this->eventListeners[$eventListener->getEvent()][] = $eventListener;
    }

    public function addSingleEventListener(EventListenerContract $eventListener): void
    {
        $oldCallable = $eventListener->getCallable();
        $eventListener->setCallable(function (EventListenerContract $eventListener) use ($oldCallable) {
            $this->removeEventListener($eventListener);
            $oldCallable(...func_get_args());
        });
        $this->addEventListener($eventListener);
    }

    /**
     * @throws EventListenerNotRegistered
     */
    public function removeEventListener(EventListenerContract $eventListener): void
    {
        $event = $eventListener->getEvent();

        foreach ($this->eventListeners[$event] ?? [] as $i => $listener) {
            if ($listener == $eventListener) {
                unset($this->eventListeners[$event][$i]);
                return;
            }
        }

        throw new EventListenerNotRegistered($eventListener);
    }

    public function fire(EventInterface $event): void
    {
        $class = get_class($event);

        // sort
        $this->sortListeners($class);

        foreach ($this->eventListeners[$class] ?? [] as $listener) {
            $this->container->call($listener->getCallable(), [
                $class => $event,
                EventInterface::class => $event,
                EventListenerContract::class => $listener,
            ]);
        }
    }

    protected function sortListeners(string $class): void
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
