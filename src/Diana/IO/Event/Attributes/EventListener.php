<?php

namespace Diana\IO\Event\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class EventListener
{
    public function __construct(protected string $event)
    {
    }
}
