<?php

namespace Diana\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class HttpErrorHandler
{
    public function __construct()
    {

    }
}