<?php

namespace Diana\Event\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class EventListener
{
    public function __construct(protected string $class, protected string $action)
    {
    }
}
