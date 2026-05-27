<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'enabled' => (bool) env('MEMORY_CACHE_ENABLE', false),

    'table_name' => 'memory_cache',

    'table_size' => (int) env('MEMORY_CACHE_TABLE_SIZE', 16384),

    'conflict_proportion' => 0.2,

    'max_key_length' => 60,

    'max_value_bytes' => 3500,

    'default_ttl' => 60,

    'ttl_jitter' => 5,

    'singleflight' => [
        'enabled' => true,
        'wait_timeout' => 3.0,
    ],

    'serializer' => env('MEMORY_CACHE_SERIALIZER', 'auto'),

    'log_value' => false,
];
