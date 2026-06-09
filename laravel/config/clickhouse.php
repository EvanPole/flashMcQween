<?php

return [

    'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
    'port' => env('CLICKHOUSE_PORT', 8123),
    'database' => env('CLICKHOUSE_DATABASE', 'search'),
    'username' => env('CLICKHOUSE_USERNAME', 'default'),
    'password' => env('CLICKHOUSE_PASSWORD', ''),
    'secure' => env('CLICKHOUSE_SECURE', false),
    'timeout' => env('CLICKHOUSE_TIMEOUT', 10),

];
