<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

use Hyperf\Contract\ConfigInterface;

final class LocalCacheTablePool
{
    private readonly ConfigInterface $config;

    /** @var array<string, LocalCacheTable> */
    private array $tables = [];

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    public function get(string $channel = 'default'): LocalCacheTable
    {
        if (! isset($this->tables[$channel])) {
            $this->tables[$channel] = new LocalCacheTable($this->config, $channel);
        }

        return $this->tables[$channel];
    }

    public function has(string $channel): bool
    {
        return $this->config->has("memory_cache.tables.{$channel}");
    }

    /**
     * @return string[]
     */
    public function channels(): array
    {
        return array_keys($this->config->get('memory_cache.tables', []));
    }

    /**
     * @return array<string, LocalCacheTable>
     */
    public function all(): array
    {
        foreach ($this->channels() as $channel) {
            $this->get($channel);
        }

        return $this->tables;
    }

    public function clearAll(): int
    {
        $total = 0;
        foreach ($this->all() as $table) {
            $total += $table->clear();
        }

        return $total;
    }
}
