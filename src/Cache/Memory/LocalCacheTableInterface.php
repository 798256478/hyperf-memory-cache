<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

interface LocalCacheTableInterface
{
    public function get(string $key): ?array;

    public function set(string $key, string $payload, int $expireAt): bool;

    public function delete(string $key): bool;

    public function count(): int;

    public function memoryUsage(): int;

    public function valueSizeLimit(): int;

    public function evictionPolicy(): string;

    public function clear(): int;
}
