<?php

namespace Diana\Routing\Attributes;

abstract class Route
{
    public function __construct(protected string $path)
    {

    }
}