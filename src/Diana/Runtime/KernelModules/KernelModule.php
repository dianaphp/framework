<?php

namespace Diana\Runtime\KernelModules;

interface KernelModule
{
    public function __invoke(): void;
}
