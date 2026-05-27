<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

final class MemoryCacheMetrics
{
    private int $hits = 0;

    private int $misses = 0;

    private int $sets = 0;

    private int $deletes = 0;

    private int $evicts = 0;

    private int $errors = 0;

    private int $skippedTooLarge = 0;

    public function recordHit(): void
    {
        ++$this->hits;
    }

    public function recordMiss(): void
    {
        ++$this->misses;
    }

    public function recordSet(): void
    {
        ++$this->sets;
    }

    public function recordDelete(): void
    {
        ++$this->deletes;
    }

    public function recordEvict(): void
    {
        ++$this->evicts;
    }

    public function recordError(): void
    {
        ++$this->errors;
    }

    public function recordSkippedTooLarge(): void
    {
        ++$this->skippedTooLarge;
    }

    /**
     * @return array<string, int|float>
     */
    public function snapshot(): array
    {
        $reads = $this->hits + $this->misses;
        $hitRate = $reads > 0 ? round($this->hits / $reads, 4) : 0.0;

        return [
            'hits'                  => $this->hits,
            'misses'                => $this->misses,
            'hit_rate'              => $hitRate,
            'sets'                  => $this->sets,
            'deletes'               => $this->deletes,
            'evicts'                => $this->evicts,
            'errors'                => $this->errors,
            'skipped_too_large'     => $this->skippedTooLarge,
        ];
    }

    public function reset(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        $this->sets = 0;
        $this->deletes = 0;
        $this->evicts = 0;
        $this->errors = 0;
        $this->skippedTooLarge = 0;
    }
}
