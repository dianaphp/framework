<?php

namespace Diana\Runtime\Attributes;

use Attribute;
use Diana\Drivers\EventInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Event implements ContextualAttribute
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(protected string $name)
    {
    }

    /**
     * Resolve the configuration value.
     *
     * @param self $attribute
     * @param Container $container
     * @return mixed
     * @throws BindingResolutionException
     */
    public static function resolve(self $attribute, Container $container): mixed
    {
        if (!isset(self::$events[$attribute->name])) {
            self::$events[$attribute->name] = $container->make(EventInterface::class, ['name' => $attribute->name]);
        }

        return self::$events[$attribute->name];
    }
}
