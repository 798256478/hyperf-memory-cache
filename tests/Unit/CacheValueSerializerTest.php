<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Tests\Unit;

use Groupbuy\HyperfMemoryCache\Cache\Memory\CacheValueSerializer;
use Hyperf\Contract\ConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CacheValueSerializerTest extends TestCase
{
    private function createSerializer(string $mode = 'auto'): CacheValueSerializer
    {
        /** @var ConfigInterface&MockObject $config */
        $config = $this->createMock(ConfigInterface::class);
        $config->method('get')->willReturnMap([
            ['memory_cache.serializer', 'auto', $mode],
        ]);

        return new CacheValueSerializer($config);
    }

    public function testSerializeAndUnserializeString(): void
    {
        $ser = $this->createSerializer('php');

        $payload = $ser->serialize('hello');
        $result = $ser->unserialize($payload);

        $this->assertTrue($result['ok']);
        $this->assertSame('hello', $result['value']);
    }

    public function testSerializeAndUnserializeArray(): void
    {
        $ser = $this->createSerializer('php');

        $data = ['foo' => 'bar', 'num' => 42];
        $payload = $ser->serialize($data);
        $result = $ser->unserialize($payload);

        $this->assertTrue($result['ok']);
        $this->assertSame($data, $result['value']);
    }

    public function testSerializeAndUnserializeNull(): void
    {
        $ser = $this->createSerializer('php');

        $payload = $ser->serialize(null);
        $result = $ser->unserialize($payload);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['value']);
    }

    public function testSerializeAndUnserializeFalse(): void
    {
        $ser = $this->createSerializer('php');

        $payload = $ser->serialize(false);
        $result = $ser->unserialize($payload);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['value']);
    }

    public function testSerializeAndUnserializeEmptyArray(): void
    {
        $ser = $this->createSerializer('php');

        $payload = $ser->serialize([]);
        $result = $ser->unserialize($payload);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['value']);
    }

    public function testSerializeAndUnserializeInteger(): void
    {
        $ser = $this->createSerializer('php');

        $payload = $ser->serialize(42);
        $result = $ser->unserialize($payload);

        $this->assertTrue($result['ok']);
        $this->assertSame(42, $result['value']);
    }

    public function testSerializeAndUnserializeFloat(): void
    {
        $ser = $this->createSerializer('php');

        $payload = $ser->serialize(3.14);
        $result = $ser->unserialize($payload);

        $this->assertTrue($result['ok']);
        $this->assertEqualsWithDelta(3.14, $result['value'], 0.001);
    }

    public function testUnserializeEmptyStringReturnsFailure(): void
    {
        $ser = $this->createSerializer('php');

        $result = $ser->unserialize('');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['value']);
    }

    public function testUnserializeGarbageReturnsFailure(): void
    {
        $ser = $this->createSerializer('php');

        $result = $ser->unserialize('not_serialized_data');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['value']);
    }

    public function testUnserializeMissingEnvelopeKeyReturnsFailure(): void
    {
        $ser = $this->createSerializer('php');

        $payload = serialize(['wrong_key' => 'value']);
        $result = $ser->unserialize($payload);

        $this->assertFalse($result['ok']);
    }

    public function testModePhp(): void
    {
        $ser = $this->createSerializer('php');
        $this->assertSame('php', $ser->mode());
    }

    public function testIgbinaryModeThrowsWhenNotInstalled(): void
    {
        if (\extension_loaded('igbinary')) {
            $this->markTestSkipped('igbinary is installed, cannot test missing extension');
        }

        $this->expectException(\RuntimeException::class);

        /** @var ConfigInterface&MockObject $config */
        $config = $this->createMock(ConfigInterface::class);
        $config->method('get')->willReturnMap([
            ['memory_cache.serializer', 'auto', 'igbinary'],
        ]);

        new CacheValueSerializer($config);
    }

    public function testEnvelopeStructure(): void
    {
        $ser = $this->createSerializer('php');

        $payload = $ser->serialize('test');
        $decoded = unserialize($payload);

        $this->assertArrayHasKey('_v', $decoded);
        $this->assertSame('test', $decoded['_v']);
    }
}
