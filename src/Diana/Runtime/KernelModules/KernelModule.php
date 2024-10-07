<?php

namespace Diana\Runtime\KernelModules;

interface KernelModule
{
    public function init(): void;
}