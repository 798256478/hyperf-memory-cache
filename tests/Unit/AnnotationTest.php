<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Tests\Unit;

use Groupbuy\HyperfMemoryCache\Annotation\MemoryCache;
use Groupbuy\HyperfMemoryCache\Annotation\MemoryCacheEvict;
use PHPUnit\Framework\TestCase;

class AnnotationTest extends TestCase
{
    public function testMemoryCacheDefaults(): void
    {
        $anno = new MemoryCache();

        $this->assertNull($anno->key);
        $this->assertNull($anno->ttl);
        $this->assertFalse($anno->cacheNull);
        $this->assertTrue($anno->singleFlight);
        $this->assertTrue($anno->jitter);
    }

    public function testMemoryCacheCustomValues(): void
    {
        $anno = new MemoryCache(
            key: 'cfg:{sid}:{column}',
            ttl: 30,
            cacheNull: true,
            singleFlight: false,
            jitter: false,
        );

        $this->assertSame('cfg:{sid}:{column}', $anno->key);
        $this->assertSame(30, $anno->ttl);
        $this->assertTrue($anno->cacheNull);
        $this->assertFalse($anno->singleFlight);
        $this->assertFalse($anno->jitter);
    }

    public function testMemoryCacheEvictDefaults(): void
    {
        $anno = new MemoryCacheEvict(key: 'cfg:{sid}:{column}');

        $this->assertSame('cfg:{sid}:{column}', $anno->key);
        $this->assertFalse($anno->alwaysEvict);
    }

    public function testMemoryCacheEvictAlwaysEvict(): void
    {
        $anno = new MemoryCacheEvict(key: 'cfg:{sid}', alwaysEvict: true);

        $this->assertSame('cfg:{sid}', $anno->key);
        $this->assertTrue($anno->alwaysEvict);
    }

    public function testMemoryCacheAttributeTarget(): void
    {
        $ref = new \ReflectionClass(MemoryCache::class);
        $attrs = $ref->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attrs);
        $attr = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_METHOD, $attr->flags);
    }

    public function testMemoryCacheEvictAttributeTarget(): void
    {
        $ref = new \ReflectionClass(MemoryCacheEvict::class);
        $attrs = $ref->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attrs);
        $attr = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_METHOD, $attr->flags);
    }
}
