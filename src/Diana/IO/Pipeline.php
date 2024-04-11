<?php

namespace Diana\IO;

use Closure;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\Runtime\Container;
use RuntimeException;

class Pipeline
{
    protected mixed $input = null;
    protected mixed $output = null;

    protected mixed $data = null;

    public function __construct(protected Container $container)
    {
    }

    public function expect(string $type): mixed
    {
        if (class_exists($type)) {
            if (!is_a($this->output, $type))
                throw new UnexpectedOutputTypeException("Unexpected pipeline output type. Expected [{$type}], got [" . $this->getType($this->output) . "]");
        } elseif (gettype($this->output) != $type)
            throw new UnexpectedOutputTypeException("Unexpected pipeline output type. Expected [{$type}], got [" . $this->getType($this->output) . "]");

        return $this->finalize();
    }

    public function finalize(): mixed
    {
        return $this->output;
    }

    public function send(mixed $input, mixed $output = null, mixed $data = null): static
    {
        $this->input = $input;
        $this->output = $output;
        $this->data = $data;

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
            return $current($this->input, $next, $this->output, $this->data);
        else if (is_string($current)) {
            $instance = $this->container->resolve($current);
            return $instance->run($this->input, $next, $this->output, $this->data);
        }
    }

    public function pipe(Closure|array|string $pipes = [])
    {
        if (is_array($pipes)) {
            $next = function () use (&$next, &$pipes) {
                $current = array_shift($pipes);

                if ($current)
                    $this->output = $this->run($current, $next);
            };

            $next();
        } else
            $this->output = $this->run($pipes, function () {});

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