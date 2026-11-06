<?php

namespace Diana\Framework\Core;

/** Application-local environment, available before configuration and container setup. */
final class Environment
{
    public const string PROD = 'prod';
    public const string DEV = 'dev';

    private string $name;
    private readonly bool $fromProcess;

    public function __construct()
    {
        $value = getenv('APP_ENV');
        $this->fromProcess = $value !== false;
        $this->name = self::normalize($value);
    }

    /** Complete boot configuration without overriding the captured process value. */
    public function configure(mixed $value): void
    {
        if (!$this->fromProcess) {
            $this->name = self::normalize($value);
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function is(string ...$names): bool
    {
        return in_array($this->name, $names, true);
    }

    private static function normalize(mixed $value): string
    {
        return !is_string($value) || $value === '' ? self::PROD : $value;
    }
}
