<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Listener;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;
use Hyperf\Memory\TableManager;
use Psr\Container\ContainerInterface;
use Swoole\Table;

class MemoryCacheTableInitializer implements ListenerInterface
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function listen(): array
    {
        return [
            BeforeMainServerStart::class,
        ];
    }

    public function process(object $event): void
    {
        /** @var ConfigInterface $config */
        $config = $this->container->get(ConfigInterface::class);

        $tables = (array) $config->get('memory_cache.tables', []);

        if (empty($tables)) {
            $this->createTable($config, [
                'table_name'         => (string) $config->get('memory_cache.table_name', 'memory_cache'),
                'table_size'         => max(1, (int) $config->get('memory_cache.table_size', 16384)),
                'max_value_bytes'    => max(64, (int) $config->get('memory_cache.max_value_bytes', 3500)),
                'conflict_proportion' => (float) $config->get('memory_cache.conflict_proportion', 0.2),
            ]);

            return;
        }

        foreach ($tables as $channel => $tableConfig) {
            $this->createTable($config, $tableConfig);
        }
    }

    /**
     * @param array<string, mixed> $tableConfig
     */
    private function createTable(ConfigInterface $config, array $tableConfig): void
    {
        $tableName = (string) ($tableConfig['table_name'] ?? 'memory_cache');

        if (TableManager::has($tableName)) {
            return;
        }

        $size = max(1, (int) ($tableConfig['table_size'] ?? $config->get('memory_cache.table_size', 16384)));
        $valueBytes = max(64, (int) ($tableConfig['max_value_bytes'] ?? $config->get('memory_cache.max_value_bytes', 3500)));
        $conflictProportion = (float) ($tableConfig['conflict_proportion'] ?? $config->get('memory_cache.conflict_proportion', 0.2));

        $table = TableManager::initialize($tableName, $size, $conflictProportion);
        $table->column('value', Table::TYPE_STRING, $valueBytes);
        $table->column('expire_at', Table::TYPE_INT);
        $table->column('created_at', Table::TYPE_INT);
        $table->column('last_access_at', Table::TYPE_INT);
        $table->create();
    }
}
