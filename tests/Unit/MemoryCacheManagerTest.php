<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Tests\Unit;

use Groupbuy\HyperfMemoryCache\Cache\Memory\MemoryCacheManager;
use Groupbuy\HyperfMemoryCache\Cache\Memory\CacheValueSerializerInterface;
use Groupbuy\HyperfMemoryCache\Cache\Memory\LocalCacheTableInterface;
use Groupbuy\HyperfMemoryCache\Cache\Memory\SingleFlightManagerInterface;
use Groupbuy\HyperfMemoryCache\Cache\Memory\MemoryCacheMetrics;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Logger\LoggerFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MemoryCacheManagerTest extends TestCase
{
    private MemoryCacheManager $manager;

    private MemoryCacheMetrics $metrics;

    private LocalCacheTableInterface&MockObject $table;

    private CacheValueSerializerInterface&MockObject $serializer;

    protected function setUp(): void
    {
        $this->metrics = new MemoryCacheMetrics();
        $this->table = $this->createMock(LocalCacheTableInterface::class);
        $this->serializer = $this->createMock(CacheValueSerializerInterface::class);

        /** @var ConfigInterface&MockObject $config */
        $config = $this->createMock(ConfigInterface::class);
        $config->method('get')->willReturnMap([
            ['memory_cache.default_ttl', 60, 60],
            ['memory_cache.ttl_jitter', 5, 0],
            ['memory_cache.max_key_length', 60, 60],
        ]);

        /** @var LoggerFactory&MockObject $loggerFactory */
        $loggerFactory = $this->createMock(LoggerFactory::class);
        $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));

        /** @var SingleFlightManagerInterface&MockObject $singleFlight */
        $singleFlight = $this->createMock(SingleFlightManagerInterface::class);
        $singleFlight->method('do')->willReturnCallback(
            fn (string $key, callable $cb, bool $enabled) => $cb()
        );

        $this->manager = new MemoryCacheManager(
            $this->table,
            $this->serializer,
            $singleFlight,
            $this->metrics,
            $config,
            $loggerFactory,
        );
    }

    public function testGetHit(): void
    {
        $this->table->method('get')->willReturn([
            'value'      => 'serialized_data',
            'expire_at'  => time() + 3600,
            'created_at' => time(),
        ]);

        $this->serializer->method('unserialize')->willReturn([
            'ok'   => true,
            'value' => ['foo' => 'bar'],
        ]);

        $result = $this->manager->get('test_key');

        $this->assertTrue($result['hit']);
        $this->assertSame(['foo' => 'bar'], $result['value']);
    }

    public function testGetMissKeyNotFound(): void
    {
        $this->table->method('get')->willReturn(null);

        $result = $this->manager->get('missing_key');

        $this->assertFalse($result['hit']);
        $this->assertNull($result['value']);
    }

    public function testGetMissExpired(): void
    {
        $this->table->method('get')->willReturn([
            'value'      => 'old_data',
            'expire_at'  => time() - 10,
            'created_at' => time() - 100,
        ]);
        $this->table->method('delete')->willReturn(true);

        $result = $this->manager->get('expired_key');

        $this->assertFalse($result['hit']);
    }

    public function testGetMissUnserializeFailure(): void
    {
        $this->table->method('get')->willReturn([
            'value'      => 'corrupt_data',
            'expire_at'  => time() + 3600,
            'created_at' => time(),
        ]);
        $this->table->method('delete')->willReturn(true);

        $this->serializer->method('unserialize')->willReturn([
            'ok'   => false,
            'value' => null,
        ]);

        $result = $this->manager->get('corrupt_key');

        $this->assertFalse($result['hit']);
    }

    public function testSetSuccess(): void
    {
        $this->serializer->method('serialize')->willReturn('serialized_payload');
        $this->table->method('valueSizeLimit')->willReturn(4096);
        $this->table->method('set')->willReturn(true);

        $this->manager->set('test_key', ['data' => 'value'], 60, false);

        $snap = $this->metrics->snapshot();
        $this->assertSame(1, $snap['sets']);
    }

    public function testSetSkipsTooLargeValue(): void
    {
        $this->serializer->method('serialize')->willReturn(str_repeat('x', 5000));
        $this->table->method('valueSizeLimit')->willReturn(4096);

        $this->manager->set('big_key', ['huge' => 'data'], 60, false);

        $snap = $this->metrics->snapshot();
        $this->assertSame(0, $snap['sets']);
        $this->assertSame(1, $snap['skipped_too_large']);
    }

    public function testDelete(): void
    {
        $this->table->method('delete')->willReturn(true);

        $this->manager->delete('test_key');

        $this->assertSame(1, $this->metrics->snapshot()['deletes']);
    }

    public function testEvict(): void
    {
        $this->table->method('delete')->willReturn(true);

        $this->manager->evict('test_key');

        $this->assertSame(1, $this->metrics->snapshot()['evicts']);
    }

    public function testRememberHit(): void
    {
        $this->table->method('get')->willReturn([
            'value'      => 'cached',
            'expire_at'  => time() + 3600,
            'created_at' => time(),
        ]);

        $this->serializer->method('unserialize')->willReturn([
            'ok'   => true,
            'value' => 'cached_result',
        ]);

        $result = $this->manager->remember('key', fn () => 'fresh_result', 60);

        $this->assertSame('cached_result', $result);
    }

    public function testRememberMissCallsCallback(): void
    {
        $callCount = 0;

        $this->table->method('get')->willReturn(null);

        $this->serializer->method('serialize')->willReturn('serialized');
        $this->table->method('valueSizeLimit')->willReturn(4096);
        $this->table->method('set')->willReturn(true);

        $result = $this->manager->remember(
            'miss_key',
            function () use (&$callCount) {
                ++$callCount;

                return 'fresh_value';
            },
            60,
        );

        $this->assertSame('fresh_value', $result);
        $this->assertSame(1, $callCount);
    }

    public function testShouldCacheSkipsNullWhenCacheNullFalse(): void
    {
        $this->table->method('get')->willReturn(null);
        $this->serializer->method('serialize')->willReturn('s');
        $this->table->method('valueSizeLimit')->willReturn(4096);
        $this->table->method('set')->willReturn(true);

        $this->manager->remember(
            'null_key',
            fn () => null,
            60,
            cacheNull: false,
        );

        $this->assertSame(0, $this->metrics->snapshot()['sets']);
    }

    public function testShouldCacheNullWhenCacheNullTrue(): void
    {
        $this->table->method('get')->willReturn(null);
        $this->serializer->method('serialize')->willReturn('s');
        $this->table->method('valueSizeLimit')->willReturn(4096);
        $this->table->method('set')->willReturn(true);

        $this->manager->remember(
            'null_key',
            fn () => null,
            60,
            cacheNull: true,
        );

        $this->assertSame(1, $this->metrics->snapshot()['sets']);
    }

    public function testShouldCacheSkipsEmptyArrayWhenCacheNullFalse(): void
    {
        $this->table->method('get')->willReturn(null);
        $this->serializer->method('serialize')->willReturn('s');
        $this->table->method('valueSizeLimit')->willReturn(4096);
        $this->table->method('set')->willReturn(true);

        $this->manager->remember(
            'empty_key',
            fn () => [],
            60,
            cacheNull: false,
        );

        $this->assertSame(0, $this->metrics->snapshot()['sets']);
    }

    public function testShouldCacheSkipsEmptyStringWhenCacheNullFalse(): void
    {
        $this->table->method('get')->willReturn(null);
        $this->serializer->method('serialize')->willReturn('s');
        $this->table->method('valueSizeLimit')->willReturn(4096);
        $this->table->method('set')->willReturn(true);

        $this->manager->remember(
            'empty_str_key',
            fn () => '',
            60,
            cacheNull: false,
        );

        $this->assertSame(0, $this->metrics->snapshot()['sets']);
    }

    public function testShouldCacheSkipsFalseWhenCacheNullFalse(): void
    {
        $this->table->method('get')->willReturn(null);
        $this->serializer->method('serialize')->willReturn('s');
        $this->table->method('valueSizeLimit')->willReturn(4096);
        $this->table->method('set')->willReturn(true);

        $this->manager->remember(
            'false_key',
            fn () => false,
            60,
            cacheNull: false,
        );

        $this->assertSame(0, $this->metrics->snapshot()['sets']);
    }

    public function testGetShortensLongKey(): void
    {
        $longKey = str_repeat('a', 100);
        $shortenedKey = 'h:' . md5($longKey);

        $this->table->expects($this->once())
            ->method('get')
            ->with($shortenedKey)
            ->willReturn(null);

        $this->manager->get($longKey);
    }

    public function testGetKeepsShortKey(): void
    {
        $shortKey = 'short_key';

        $this->table->expects($this->once())
            ->method('get')
            ->with($shortKey)
            ->willReturn(null);

        $this->manager->get($shortKey);
    }

    public function testSetShortensLongKey(): void
    {
        $longKey = str_repeat('b', 100);
        $shortenedKey = 'h:' . md5($longKey);

        $this->serializer->method('serialize')->willReturn('payload');
        $this->table->method('valueSizeLimit')->willReturn(4096);
        $this->table->expects($this->once())
            ->method('set')
            ->with($shortenedKey, $this->anything(), $this->anything())
            ->willReturn(true);

        $this->manager->set($longKey, 'value', 60, false);
    }

    public function testDeleteShortensLongKey(): void
    {
        $longKey = str_repeat('c', 100);
        $shortenedKey = 'h:' . md5($longKey);

        $this->table->expects($this->once())
            ->method('delete')
            ->with($shortenedKey)
            ->willReturn(true);

        $this->manager->delete($longKey);
    }
}
