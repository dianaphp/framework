<?php

use Diana\Database\Drivers\PDOConnection;

return [
    'default' => [
        'driver' => PDOConnection::class,
        // 'dsn' => 'mysql:unix_socket=/tmp/mysql.sock;dbname=testdb',
        'dsn' => 'mysql:host=localhost;dbname=diana',
        'username' => 'root',
        'password' => ''
    ]
];
