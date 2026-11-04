<?php

namespace Diana\IO;

use Closure;
use Diana\Contracts\Core\Container;
use Diana\Contracts\Core\Pipeline as PipelineContract;
use Diana\IO\Exceptions\UnexpectedOutputTypeException;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Pipeline implements PipelineContract
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
        if (is_object($this->output) && is_a($this->output, $type) || gettype($this->output) == $type) {
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
        $next = static fn (mixed $input) => $this->output;

        // Build from the inside out: each middleware owns the rest of the chain.
        foreach (array_reverse(is_array($pipes) ? $pipes : [$pipes]) as $pipe) {
            /**
             * @throws ContainerExceptionInterface
             * @throws NotFoundExceptionInterface
             */
            $next = function (mixed $input) use ($pipe, $next) {
                $continue = static fn (mixed ...$args) => $next(
                    $args === [] ? $input : $args[0]
                );

                if ($pipe instanceof Closure) {
                    return $pipe($input, $continue, $this->output, $this->data);
                } elseif (is_string($pipe)) {
                    return $this->container->get($pipe)->run($input, $continue, $this->output, $this->data);
                }

                throw new InvalidArgumentException('Pipeline stages must be closures or middleware class names.');
            };
        }

        $this->output = $next($this->input);

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
