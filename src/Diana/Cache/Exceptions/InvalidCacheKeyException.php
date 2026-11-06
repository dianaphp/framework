<?php

namespace Diana\Cache\Exceptions;

use InvalidArgumentException;

class InvalidCacheKeyException extends InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}
