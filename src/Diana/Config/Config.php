<?php

namespace Diana\Config;

use Attribute;
use Diana\Contracts\Config\Config as ConfigContract;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\ContextualAttribute;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Config implements ConfigContract, ContextualAttribute
{
    protected static array $configs = [];
    protected array $config = [];
    protected array $default = [];

    public function __construct(protected string $name = 'app')
    {
    }

    public function get(string $key): mixed
    {
        return $this->config[$key];
    }

    public function set(string $key, mixed $value): void
    {
        $this->config[$key] = $value;
    }

    public function all(): array
    {
        return $this->config;
    }

    public function addDefault(array $default): self
    {
        $this->default = $this->arrayMergeRecursiveDistinct($this->default, $default);
        return $this;
    }

    public function getDefault(string $key): mixed
    {
        return $this->default[$key];
    }

    public function getDefaults(): array
    {
        return $this->default;
    }

    // TODO: Outsource
    protected function arrayMergeRecursiveDistinct(array $array1, array $array2): array
    {
        $merged = $array1;

        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->arrayMergeRecursiveDistinct($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Resolve the configuration value.
     *
     * @param self $attribute
     * @param Container $container
     * @return mixed
     * @throws BindingResolutionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function resolve(self $attribute, Container $container): mixed
    {
        if (!isset(self::$configs[$attribute->name])) {
            self::$configs[$attribute->name] = $container->make(self::class, [
                'name' => $attribute->name
            ]);
        }

        return self::$configs[$attribute->name];
    }
}
