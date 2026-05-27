<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Tests\Unit;

use Groupbuy\HyperfMemoryCache\Cache\Memory\MemoryCacheMetrics;
use PHPUnit\Framework\TestCase;

class MemoryCacheMetricsTest extends TestCase
{
    private MemoryCacheMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new MemoryCacheMetrics();
    }

    public function testInitialSnapshotAllZero(): void
    {
        $snap = $this->metrics->snapshot();

        $this->assertSame(0, $snap['hits']);
        $this->assertSame(0, $snap['misses']);
        $this->assertSame(0.0, $snap['hit_rate']);
        $this->assertSame(0, $snap['sets']);
        $this->assertSame(0, $snap['deletes']);
        $this->assertSame(0, $snap['evicts']);
        $this->assertSame(0, $snap['errors']);
        $this->assertSame(0, $snap['singleflight_dedupes']);
        $this->assertSame(0, $snap['skipped_too_large']);
    }

    public function testRecordHit(): void
    {
        $this->metrics->recordHit();
        $this->metrics->recordHit();
        $this->metrics->recordHit();

        $this->assertSame(3, $this->metrics->snapshot()['hits']);
    }

    public function testRecordMiss(): void
    {
        $this->metrics->recordMiss();

        $this->assertSame(1, $this->metrics->snapshot()['misses']);
    }

    public function testHitRateCalculation(): void
    {
        $this->metrics->recordHit();
        $this->metrics->recordHit();
        $this->metrics->recordMiss();

        $this->assertSame(0.6667, $this->metrics->snapshot()['hit_rate']);
    }

    public function testHitRateZeroWhenNoReads(): void
    {
        $this->assertSame(0.0, $this->metrics->snapshot()['hit_rate']);
    }

    public function testRecordSet(): void
    {
        $this->metrics->recordSet();

        $this->assertSame(1, $this->metrics->snapshot()['sets']);
    }

    public function testRecordDelete(): void
    {
        $this->metrics->recordDelete();

        $this->assertSame(1, $this->metrics->snapshot()['deletes']);
    }

    public function testRecordEvict(): void
    {
        $this->metrics->recordEvict();

        $this->assertSame(1, $this->metrics->snapshot()['evicts']);
    }

    public function testRecordError(): void
    {
        $this->metrics->recordError();

        $this->assertSame(1, $this->metrics->snapshot()['errors']);
    }

    public function testRecordSingleflightDedupe(): void
    {
        $this->metrics->recordSingleflightDedupe();

        $this->assertSame(1, $this->metrics->snapshot()['singleflight_dedupes']);
    }

    public function testRecordSkippedTooLarge(): void
    {
        $this->metrics->recordSkippedTooLarge();

        $this->assertSame(1, $this->metrics->snapshot()['skipped_too_large']);
    }

    public function testReset(): void
    {
        $this->metrics->recordHit();
        $this->metrics->recordMiss();
        $this->metrics->recordSet();
        $this->metrics->recordError();

        $this->metrics->reset();

        $snap = $this->metrics->snapshot();
        $this->assertSame(0, $snap['hits']);
        $this->assertSame(0, $snap['misses']);
        $this->assertSame(0, $snap['sets']);
        $this->assertSame(0, $snap['errors']);
        $this->assertSame(0.0, $snap['hit_rate']);
    }

    public function testMultipleOperations(): void
    {
        for ($i = 0; $i < 80; ++$i) {
            $this->metrics->recordHit();
        }
        for ($i = 0; $i < 20; ++$i) {
            $this->metrics->recordMiss();
        }

        $snap = $this->metrics->snapshot();
        $this->assertSame(80, $snap['hits']);
        $this->assertSame(20, $snap['misses']);
        $this->assertSame(0.8, $snap['hit_rate']);
    }
}
