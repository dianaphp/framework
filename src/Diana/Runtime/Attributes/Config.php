<?php

namespace Diana\Runtime\Attributes;

use Attribute;
use Diana\Contracts\ConfigContract;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Config implements ContextualAttribute
{
    protected static array $configs = [];

    /**
     * Create a new attribute instance.
     */
    public function __construct(protected string $name = 'cfg/app')
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
        if (!isset(self::$configs[$attribute->name])) {
            self::$configs[$attribute->name] = $container->make(ConfigContract::class, ['name' => $attribute->name]);
        }

        return self::$configs[$attribute->name];
    }
}
