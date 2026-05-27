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

        $tableName = (string) $config->get('memory_cache.table_name', 'memory_cache');

        if (TableManager::has($tableName)) {
            return;
        }

        $size = max(1, (int) $config->get('memory_cache.table_size', 16384));
        $valueBytes = max(64, (int) $config->get('memory_cache.max_value_bytes', 3500));
        $conflictProportion = (float) $config->get('memory_cache.conflict_proportion', 0.2);

        $table = TableManager::initialize($tableName, $size, $conflictProportion);
        $table->column('value', Table::TYPE_STRING, $valueBytes);
        $table->column('expire_at', Table::TYPE_INT);
        $table->column('created_at', Table::TYPE_INT);
        $table->column('last_access_at', Table::TYPE_INT);
        $table->create();
    }
}
