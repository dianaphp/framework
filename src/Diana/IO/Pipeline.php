<?php

namespace Diana\IO;

use Closure;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use Diana\Support\Helpers\Arr;
use Illuminate\Container\Container;

class Pipeline
{
    protected mixed $input = null;
    protected mixed $output = null;

    protected mixed $data = null;

    public function __construct(protected Container $container)
    {
    }

    /**
     * @throws UnexpectedOutputTypeException
     */
    public function expect(string $type): mixed
    {
        if (class_exists($type) && is_a($this->output, $type) || gettype($this->output) == $type) {
            return $this->finalize();
        }

        throw new UnexpectedOutputTypeException(
            "Unexpected pipeline output type. Expected [{$type}], got [" . $this->getType($this->output) . "]"
        );
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

    public function pipe(Closure|array|string $pipes = []): static
    {
        $next = function () use (&$next, &$pipes) {
            $current = array_shift($pipes);

            if ($current) {
                if ($current instanceof Closure) {
                    return $current($this->input, $next, $this->output, $this->data);
                } elseif (is_string($current)) {
                    $instance = $this->container->get($current);
                    return $instance->run($this->input, $next, $this->output, $this->data);
                }
            }
        };

        $pipes = Arr::wrap($pipes);

        $this->output = $next();

        return $this;
    }

    protected function getType(mixed $subject): string
    {
        $type = gettype($subject);

        if ($type == 'object') {
            $type = get_class($subject);
        }

        return $type;
    }
}