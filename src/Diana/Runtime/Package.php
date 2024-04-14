<?php

namespace Diana\Runtime;

use Diana\Runtime\Contracts\Bootable;
use Diana\Runtime\Contracts\Configurable;
use Diana\Runtime\Contracts\HasPath;
use Diana\Runtime\Implementations\Boot;
use Diana\Runtime\Implementations\Config;
use Diana\Runtime\Implementations\Path;

abstract class Package implements Bootable, Configurable
{
    use Boot, Config;
}