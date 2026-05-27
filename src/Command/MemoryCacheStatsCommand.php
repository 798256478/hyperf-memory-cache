<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Command;

use Groupbuy\HyperfMemoryCache\Cache\Memory\LocalCacheTable;
use Groupbuy\HyperfMemoryCache\Cache\Memory\MemoryCacheManager;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

#[Command]
final class MemoryCacheStatsCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('memory-cache:stats');
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('Show local L1 memory cache configuration and metrics');
    }

    public function handle(): int
    {
        /** @var ConfigInterface $config */
        $config = $this->container->get(ConfigInterface::class);
        $enabled = (bool) $config->get('memory_cache.enabled', false);

        $this->line('');
        $this->line('=== Local L1 Memory Cache Configuration ===', 'info');
        $this->line(sprintf('  enabled          : %s', $enabled ? 'true' : 'false'),
            $enabled ? 'info' : 'comment');
        $this->line(sprintf('  table_name       : %s', $config->get('memory_cache.table_name')));
        $this->line(sprintf('  max_key_length   : %d',  (int) $config->get('memory_cache.max_key_length')));
        $this->line(sprintf('  max_value_bytes  : %d',  (int) $config->get('memory_cache.max_value_bytes')));
        $this->line(sprintf('  default_ttl      : %ds', (int) $config->get('memory_cache.default_ttl')));
        $this->line(sprintf('  ttl_jitter       : %ds', (int) $config->get('memory_cache.ttl_jitter')));
        $this->line(sprintf('  singleflight     : %s, wait_timeout=%ss',
            ((bool) $config->get('memory_cache.singleflight.enabled')) ? 'true' : 'false',
            (string) $config->get('memory_cache.singleflight.wait_timeout')));
        $this->line(sprintf('  serializer       : %s', (string) $config->get('memory_cache.serializer')));

        try {
            /** @var LocalCacheTable $table */
            $table = $this->container->get(LocalCacheTable::class);
            $this->line('');
            $this->line('=== Swoole\Table Status (CLI process only) ===', 'info');
            $this->line(sprintf('  rows in use      : %d', $table->count()));
            $this->line(sprintf('  memory size      : %d bytes', $table->memoryUsage()));
            $this->line(sprintf('  value size limit : %d bytes', $table->valueSizeLimit()));
        } catch (\Throwable $e) {
            $this->line('Table unavailable: ' . $e->getMessage(), 'error');
        }

        try {
            /** @var MemoryCacheManager $manager */
            $manager = $this->container->get(MemoryCacheManager::class);
            $this->line('');
            $this->line('=== Metrics (note: all 0 in CLI, observe in Worker) ===', 'info');
            foreach ($manager->snapshot() as $k => $v) {
                $this->line(sprintf('  %-22s : %s', $k, var_export($v, true)));
            }
        } catch (\Throwable $e) {
            $this->line('Metrics unavailable: ' . $e->getMessage(), 'error');
        }

        $this->line('');

        return 0;
    }
}
