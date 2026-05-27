<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

interface CacheValueSerializerInterface
{
    public function serialize(mixed $value): string;

    /**
     * @return array{ok: bool, value: mixed}
     */
    public function unserialize(string $payload): array;

    public function mode(): string;
}
