<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

final class MemoryCacheManager
{
    private readonly LoggerInterface $logger;

    private readonly bool $enabled;

    private readonly int $defaultTtl;

    private readonly int $ttlJitter;

    private readonly int $maxKeyLength;

    public function __construct(
        private readonly LocalCacheTablePool $pool,
        private readonly CacheValueSerializerInterface $serializer,
        private readonly SingleFlightManagerInterface $singleFlight,
        private readonly MemoryCacheMetrics $metrics,
        ConfigInterface $config,
        LoggerFactory $loggerFactory,
    ) {
        $this->enabled = (bool) $config->get('memory_cache.enabled', true);
        $this->defaultTtl = max(1, (int) $config->get('memory_cache.default_ttl', 60));
        $this->ttlJitter = max(0, (int) $config->get('memory_cache.ttl_jitter', 5));
        $this->maxKeyLength = max(16, (int) $config->get('memory_cache.max_key_length', 60));
        $this->logger = $loggerFactory->get('cache.memory');
    }

    /**
     * @return array{hit: bool, value: mixed}
     */
    public function get(string $key, string $channel = 'default'): array
    {
        if (! $this->enabled) {
            return ['hit' => false, 'value' => null];
        }
        $key = $this->shortenKey($key);
        $table = $this->pool->get($channel);
        $row = $table->get($key);
        if ($row === null) {
            $this->metrics->recordMiss();

            return ['hit' => false, 'value' => null];
        }

        $decoded = $this->serializer->unserialize($row['value']);
        if (! $decoded['ok']) {
            $this->metrics->recordMiss();
            $this->metrics->recordError();
            $table->delete($key);

            return ['hit' => false, 'value' => null];
        }

        $this->metrics->recordHit();

        return ['hit' => true, 'value' => $decoded['value']];
    }

    public function set(string $key, mixed $value, int $ttl, bool $jitter = true, string $channel = 'default'): void
    {
        if (! $this->enabled) {
            return;
        }
        $key = $this->shortenKey($key);
        $effectiveTtl = max(1, $ttl);
        if ($jitter && $this->ttlJitter > 0) {
            $effectiveTtl += random_int(1, $this->ttlJitter);
        }

        $table = $this->pool->get($channel);
        $payload = $this->serializer->serialize($value);
        if (strlen($payload) > $table->valueSizeLimit()) {
            $this->metrics->recordSkippedTooLarge();
            $this->logger->info('memory_cache skip too large', [
                'key'     => $key,
                'channel' => $channel,
                'bytes'   => strlen($payload),
                'limit'   => $table->valueSizeLimit(),
            ]);

            return;
        }

        $ok = $table->set($key, $payload, time() + $effectiveTtl);
        if ($ok) {
            $this->metrics->recordSet();
        } else {
            if ($table->evictionPolicy() === 'lru') {
                $this->metrics->recordEvictsLru(1);
            }
            $this->metrics->recordError();
        }
    }

    public function delete(string $key, string $channel = 'default'): void
    {
        if (! $this->enabled) {
            return;
        }
        $key = $this->shortenKey($key);
        if ($this->pool->get($channel)->delete($key)) {
            $this->metrics->recordDelete();
        }
    }

    public function evict(string $key, string $channel = 'default'): void
    {
        if (! $this->enabled) {
            return;
        }
        $key = $this->shortenKey($key);
        if ($this->pool->get($channel)->delete($key)) {
            $this->metrics->recordEvict();
        }
    }

    /**
     * Cache read + miss callback + single-flight + backfill.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function remember(
        string $key,
        Closure $callback,
        ?int $ttl = null,
        bool $cacheNull = false,
        bool $singleFlight = true,
        bool $jitter = true,
        string $channel = 'default',
    ): mixed {
        if (! $this->enabled) {
            return $callback();
        }
        $result = $this->get($key, $channel);
        if ($result['hit']) {
            return $result['value'];
        }

        $effectiveTtl = $ttl ?? $this->defaultTtl;
        $sfKey = "{$channel}:{$key}";

        $value = $this->singleFlight->do(
            $sfKey,
            function () use ($key, $callback, $effectiveTtl, $cacheNull, $jitter, $channel) {
                $recheck = $this->get($key, $channel);
                if ($recheck['hit']) {
                    return $recheck['value'];
                }
                $value = $callback();
                if ($this->shouldCache($value, $cacheNull)) {
                    $this->set($key, $value, $effectiveTtl, $jitter, $channel);
                }

                return $value;
            },
            $singleFlight,
        );

        return $value;
    }

    private function shouldCache(mixed $value, bool $cacheNull): bool
    {
        if ($cacheNull) {
            return true;
        }
        if ($value === null || $value === '' || $value === false) {
            return false;
        }
        if (is_array($value) && $value === []) {
            return false;
        }

        return true;
    }

    private function shortenKey(string $key): string
    {
        return strlen($key) > $this->maxKeyLength
            ? 'h:' . md5($key)
            : $key;
    }

    public function clear(string $channel = 'default'): int
    {
        if (! $this->enabled) {
            return 0;
        }
        return $this->pool->get($channel)->clear();
    }

    public function clearAll(): int
    {
        if (! $this->enabled) {
            return 0;
        }
        return $this->pool->clearAll();
    }

    /**
     * @return array<string, int|float|string>
     */
    public function snapshot(): array
    {
        $data = $this->metrics->snapshot();
        $data['singleflight_dedupes'] = $this->singleFlight instanceof SingleFlightManager
            ? $this->singleFlight->getDedupes()
            : 0;

        $perChannel = [];
        foreach ($this->pool->channels() as $ch) {
            $t = $this->pool->get($ch);
            $perChannel[$ch] = [
                'table_name'      => $t->channel(),
                'value_size_limit' => $t->valueSizeLimit(),
                'count'           => $t->count(),
                'memory_usage'    => $t->memoryUsage(),
            ];
        }
        $data['channels'] = $perChannel;

        return $data;
    }
}
