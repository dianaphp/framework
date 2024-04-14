<?php

namespace Diana\Tests\Controllers;

use Diana\Routing\Attributes\Get;

class ParamController
{
    #[Get('/noparam')]
    public function noparam()
    {
        return 'noparam';
    }

    #[Get('/param/:param')]
    public function param($param)
    {
        return $param;
    }
}