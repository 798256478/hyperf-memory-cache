<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache;

use Groupbuy\HyperfMemoryCache\Command\MemoryCacheStatsCommand;
use Groupbuy\HyperfMemoryCache\Listener\MemoryCacheTableInitializer;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
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
