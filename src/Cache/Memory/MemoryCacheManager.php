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

    private readonly int $defaultTtl;

    private readonly int $ttlJitter;

    private readonly int $maxKeyLength;

    public function __construct(
        private readonly LocalCacheTableInterface $table,
        private readonly CacheValueSerializerInterface $serializer,
        private readonly SingleFlightManagerInterface $singleFlight,
        private readonly MemoryCacheMetrics $metrics,
        ConfigInterface $config,
        LoggerFactory $loggerFactory,
    ) {
        $this->defaultTtl = max(1, (int) $config->get('memory_cache.default_ttl', 60));
        $this->ttlJitter = max(0, (int) $config->get('memory_cache.ttl_jitter', 5));
        $this->maxKeyLength = max(16, (int) $config->get('memory_cache.max_key_length', 60));
        $this->logger = $loggerFactory->get('cache.memory');
    }

    /**
     * @return array{hit: bool, value: mixed}
     */
    public function get(string $key): array
    {
        $key = $this->shortenKey($key);
        $row = $this->table->get($key);
        if ($row === null) {
            $this->metrics->recordMiss();

            return ['hit' => false, 'value' => null];
        }

        $decoded = $this->serializer->unserialize($row['value']);
        if (! $decoded['ok']) {
            $this->metrics->recordMiss();
            $this->metrics->recordError();
            $this->table->delete($key);

            return ['hit' => false, 'value' => null];
        }

        $this->metrics->recordHit();

        return ['hit' => true, 'value' => $decoded['value']];
    }

    public function set(string $key, mixed $value, int $ttl, bool $jitter = true): void
    {
        $key = $this->shortenKey($key);
        $effectiveTtl = max(1, $ttl);
        if ($jitter && $this->ttlJitter > 0) {
            $effectiveTtl += random_int(1, $this->ttlJitter);
        }

        $payload = $this->serializer->serialize($value);
        if (strlen($payload) > $this->table->valueSizeLimit()) {
            $this->metrics->recordSkippedTooLarge();
            $this->logger->info('memory_cache skip too large', [
                'key'        => $key,
                'bytes'      => strlen($payload),
                'limit'      => $this->table->valueSizeLimit(),
            ]);

            return;
        }

        $ok = $this->table->set($key, $payload, time() + $effectiveTtl);
        if ($ok) {
            $this->metrics->recordSet();
        } else {
            if ($this->table->evictionPolicy() === 'lru') {
                $this->metrics->recordEvictsLru(1);
            }
            $this->metrics->recordError();
        }
    }

    public function delete(string $key): void
    {
        $key = $this->shortenKey($key);
        if ($this->table->delete($key)) {
            $this->metrics->recordDelete();
        }
    }

    public function evict(string $key): void
    {
        $key = $this->shortenKey($key);
        if ($this->table->delete($key)) {
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
    ): mixed {
        $result = $this->get($key);
        if ($result['hit']) {
            return $result['value'];
        }

        $effectiveTtl = $ttl ?? $this->defaultTtl;

        $value = $this->singleFlight->do(
            $key,
            function () use ($key, $callback, $effectiveTtl, $cacheNull, $jitter) {
                $recheck = $this->get($key);
                if ($recheck['hit']) {
                    return $recheck['value'];
                }
                $value = $callback();
                if ($this->shouldCache($value, $cacheNull)) {
                    $this->set($key, $value, $effectiveTtl, $jitter);
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

    /**
     * @return array<string, int|float>
     */
    public function snapshot(): array
    {
        $data = $this->metrics->snapshot();
        $data['singleflight_dedupes'] = $this->singleFlight instanceof SingleFlightManager
            ? $this->singleFlight->getDedupes()
            : 0;

        return $data;
    }
}
