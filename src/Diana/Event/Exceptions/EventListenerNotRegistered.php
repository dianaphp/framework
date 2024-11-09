<?php

namespace Diana\Event\Exceptions;

use Diana\Contracts\EventListenerContract;
use Exception;

class EventListenerNotRegistered extends Exception
{
    public function __construct(protected EventListenerContract $eventListener)
    {
        parent::__construct(
            sprintf(
                'Attempted to remove an event listener [%s] for the event [%s] that is not registered.',
                get_class($eventListener),
                $eventListener->getEvent()
            )
        );
    }
}
