<?php
namespace PHPSTORM_META {

    $STATIC_METHOD_TYPES = [
        \Psr\Container\ContainerInterface::get('') => [
            "" == "@",
        ],
        \Illuminate\Container\Container::get('') => [
            "" == "@",
        ],
        \Illuminate\Container\Container::make('') => [
            "" == "@",
        ]
    ];
}