<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'enabled' => (bool) env('MEMORY_CACHE_ENABLE', false),

    'tables' => [
        'default' => [
            'table_name' => 'memory_cache',
            'table_size' => (int) env('MEMORY_CACHE_TABLE_SIZE', 16384),
            'max_value_bytes' => 3500,
            'conflict_proportion' => 0.2,
            // 'eviction_policy' => 'lru', // 表级淘汰策略，不设置则使用全局 eviction_policy
            // 'eviction_batch_size' => 8, // 表级淘汰批量大小，不设置则使用全局 eviction_batch_size
        ],
        'goods' => [
            'table_name' => 'memory_cache_goods',
            'table_size' => (int) env('MEMORY_CACHE_GOODS_TABLE_SIZE', 4096),
            'max_value_bytes' => 6000,
            'conflict_proportion' => 0.2,
            // 'eviction_policy' => 'lru_lazy', // 商品表读密集，可用 lru_lazy 纯读不写
            // 'eviction_batch_size' => 8,
        ],
    ],

    'max_key_length' => 60,

    'default_ttl' => 60,

    'ttl_jitter' => 5,

    'singleflight' => [
        'enabled' => true,
        'wait_timeout' => 3.0,
    ],

    'serializer' => env('MEMORY_CACHE_SERIALIZER', 'auto'),

    'eviction_policy' => env('MEMORY_CACHE_EVICTION_POLICY', 'lru'), // 淘汰策略：lru=概率更新访问时间戳(约10%命中率时更新), lru_lazy=纯读不写(按写入时间淘汰, FIFO语义)

    'eviction_batch_size' => (int) env('MEMORY_CACHE_EVICTION_BATCH_SIZE', 8),

    'log_value' => false,
];
