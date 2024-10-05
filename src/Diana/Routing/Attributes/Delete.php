<?php

namespace Diana\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Delete extends Route
{
    public const string METHOD = 'DELETE';
}
