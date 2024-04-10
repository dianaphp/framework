<?php

namespace Diana\IO;

use Closure;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\Runtime\Container;
use RuntimeException;

class Pipeline
{
    protected mixed $initial = null;
    protected mixed $mutable = null;

    public function __construct(protected Container $container)
    {
    }

    public function expect(string $type): mixed
    {
        if (class_exists($type)) {
            if (!is_a($this->mutable, $type))
                throw new UnexpectedOutputTypeException("Unexpected pipeline output type. Expected [{$type}], got [" . $this->getType($this->mutable) . "]");
        } elseif (gettype($this->mutable) != $type)
            throw new UnexpectedOutputTypeException("Unexpected pipeline output type. Expected [{$type}], got [" . $this->getType($this->mutable) . "]");

        return $this->finalize();
    }

    public function finalize(): mixed
    {
        return $this->mutable;
    }

    public function send(mixed $initial): static
    {
        $this->initial = $initial;
        $this->mutable = $initial;

        return $this;
    }

    public function then(Closure $closure): static
    {
        $this->postProcessor = $closure;

        return $this;
    }

    protected function run(Closure|string $current, Closure $next)
    {
        if ($current instanceof Closure)
            return $current($this->initial, $next, $this->mutable);
        else if (is_string($current)) {
            $instance = $this->container->resolve($current);
            return $instance->run($this->initial, $next, $this->mutable);
        }
    }

    public function pipe(Closure|array $pipes = [])
    {
        if (is_array($pipes)) {
            $next = function () use (&$next, &$pipes) {
                $current = array_shift($pipes);

                if ($current)
                    $this->mutable = $this->run($current, $next);
            };

            $next();
        } else
            $this->mutable = $this->run($pipes, function () {});

        return $this;
    }

    protected function getType(mixed $subject): string
    {
        $type = gettype($subject);

        if ($type == 'object')
            $type = get_class($subject);

        return $type;
    }
}