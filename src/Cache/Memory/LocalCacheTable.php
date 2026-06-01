<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Memory\TableManager;
use Swoole\Table;

final class LocalCacheTable implements LocalCacheTableInterface
{
    private readonly string $channel;

    private readonly string $tableName;

    private readonly int $maxValueBytes;

    private readonly string $evictionPolicy;

    private readonly int $evictionBatchSize;

    public function __construct(ConfigInterface $config, string $channel = 'default')
    {
        $this->channel = $channel;

        $tableConfig = (array) $config->get("memory_cache.tables.{$channel}", []);

        $this->tableName = (string) ($tableConfig['table_name']
            ?? $config->get('memory_cache.table_name', 'memory_cache'));

        $this->maxValueBytes = max(64, (int) ($tableConfig['max_value_bytes']
            ?? $config->get('memory_cache.max_value_bytes', 3500)));

        $this->evictionPolicy = (string) ($tableConfig['eviction_policy']
            ?? $config->get('memory_cache.eviction_policy', 'lru'));

        $this->evictionBatchSize = max(1, (int) ($tableConfig['eviction_batch_size']
            ?? $config->get('memory_cache.eviction_batch_size', 8)));
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function get(string $key): ?array
    {
        $table = $this->table();
        if ($table === null || $key === '') {
            return null;
        }
        $row = $table->get($key);
        if (! is_array($row)) {
            return null;
        }

        $now = time();
        if ((int) ($row['expire_at'] ?? 0) <= $now) {
            $table->del($key);

            return null;
        }

        if ($this->evictionPolicy === 'lru' && random_int(1, 10) === 1) {
            $table->set($key, ['last_access_at' => $now]);
        }

        return [
            'value'          => (string) ($row['value'] ?? ''),
            'expire_at'      => (int) ($row['expire_at'] ?? 0),
            'created_at'     => (int) ($row['created_at'] ?? 0),
            'last_access_at' => (int) ($row['last_access_at'] ?? 0),
        ];
    }

    public function set(string $key, string $payload, int $expireAt): bool
    {
        $table = $this->table();
        if ($table === null || $key === '') {
            return false;
        }
        if (strlen($payload) > $this->maxValueBytes) {
            return false;
        }

        $now = time();
        $ok = $table->set($key, [
            'value'          => $payload,
            'expire_at'      => $expireAt,
            'created_at'     => $now,
            'last_access_at' => $now,
        ]);

        if (! $ok && ($this->evictionPolicy === 'lru' || $this->evictionPolicy === 'lru_lazy')) {
            $this->evictLru($table, $now);

            $ok = $table->set($key, [
                'value'          => $payload,
                'expire_at'      => $expireAt,
                'created_at'     => $now,
                'last_access_at' => $now,
            ]);
        }

        return $ok;
    }

    public function delete(string $key): bool
    {
        $table = $this->table();
        if ($table === null || $key === '') {
            return false;
        }

        return $table->del($key);
    }

    public function count(): int
    {
        return $this->table()?->count() ?? 0;
    }

    public function memoryUsage(): int
    {
        return $this->table()?->getMemorySize() ?? 0;
    }

    public function valueSizeLimit(): int
    {
        return $this->maxValueBytes;
    }

    public function evictionPolicy(): string
    {
        return $this->evictionPolicy;
    }

    public function clear(): int
    {
        $table = $this->table();
        if ($table === null) {
            return 0;
        }
        $cleared = 0;
        foreach ($table as $key => $row) {
            $table->del((string) $key);
            ++$cleared;
        }
        return $cleared;
    }

    private function evictLru(Table $table, int $now): int
    {
        $evicted = 0;
        $expired = [];
        $candidates = [];

        foreach ($table as $key => $row) {
            $expireAt = (int) ($row['expire_at'] ?? 0);
            if ($expireAt <= $now) {
                $expired[] = (string) $key;
                continue;
            }
            $candidates[] = [
                'key'            => (string) $key,
                'last_access_at' => (int) ($row['last_access_at'] ?? 0),
                'created_at'     => (int) ($row['created_at'] ?? 0),
            ];
        }

        foreach ($expired as $key) {
            $table->del($key);
            ++$evicted;
        }

        if ($evicted > 0) {
            return $evicted;
        }

        if ($this->evictionPolicy === 'lru_lazy') {
            usort($candidates, static fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);
        } else {
            usort($candidates, static fn (array $a, array $b): int => $a['last_access_at'] <=> $b['last_access_at']);
        }

        $batch = min($this->evictionBatchSize, count($candidates));
        for ($i = 0; $i < $batch; ++$i) {
            $table->del($candidates[$i]['key']);
            ++$evicted;
        }

        return $evicted;
    }

    private function table(): ?Table
    {
        return TableManager::has($this->tableName)
            ? TableManager::get($this->tableName)
            : null;
    }
}
