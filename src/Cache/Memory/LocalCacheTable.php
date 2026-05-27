<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Memory\TableManager;
use Swoole\Table;

final class LocalCacheTable implements LocalCacheTableInterface
{
    private readonly string $tableName;

    private readonly int $maxValueBytes;

    public function __construct(ConfigInterface $config)
    {
        $this->tableName = (string) $config->get('memory_cache.table_name', 'memory_cache');
        $this->maxValueBytes = max(64, (int) $config->get('memory_cache.max_value_bytes', 3500));
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

        return [
            'value'      => (string) ($row['value'] ?? ''),
            'expire_at'  => (int) ($row['expire_at'] ?? 0),
            'created_at' => (int) ($row['created_at'] ?? 0),
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

        return $table->set($key, [
            'value'      => $payload,
            'expire_at'  => $expireAt,
            'created_at' => time(),
        ]);
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

    private function table(): ?Table
    {
        return TableManager::has($this->tableName)
            ? TableManager::get($this->tableName)
            : null;
    }
}
