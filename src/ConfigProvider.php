<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache;

use Groupbuy\HyperfMemoryCache\Cache\Memory\CacheValueSerializer;
use Groupbuy\HyperfMemoryCache\Cache\Memory\CacheValueSerializerInterface;
use Groupbuy\HyperfMemoryCache\Cache\Memory\LocalCacheTable;
use Groupbuy\HyperfMemoryCache\Cache\Memory\LocalCacheTableInterface;
use Groupbuy\HyperfMemoryCache\Cache\Memory\SingleFlightManager;
use Groupbuy\HyperfMemoryCache\Cache\Memory\SingleFlightManagerInterface;
use Groupbuy\HyperfMemoryCache\Command\MemoryCacheStatsCommand;
use Groupbuy\HyperfMemoryCache\Listener\MemoryCacheTableInitializer;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                LocalCacheTableInterface::class   => LocalCacheTable::class,
                CacheValueSerializerInterface::class => CacheValueSerializer::class,
                SingleFlightManagerInterface::class => SingleFlightManager::class,
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [__DIR__],
                ],
            ],
            'listeners' => [
                MemoryCacheTableInitializer::class => 1,
            ],
            'commands' => [
                MemoryCacheStatsCommand::class,
            ],
            'publish' => [
                [
                    'id'          => 'config',
                    'description' => 'The default configuration of hyperf-memory-cache',
                    'source'      => __DIR__ . '/../publish/memory_cache.php',
                    'destination' => 'config/autoload/memory_cache.php',
                ],
            ],
        ];
    }
}
