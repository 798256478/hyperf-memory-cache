<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Tests\Unit;

use Groupbuy\HyperfMemoryCache\Cache\Memory\CacheKeyGenerator;
use Hyperf\Contract\ConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CacheKeyGeneratorTest extends TestCase
{
    private CacheKeyGenerator $generator;

    protected function setUp(): void
    {
        /** @var ConfigInterface&MockObject $config */
        $config = $this->createMock(ConfigInterface::class);
        $config->method('get')->willReturnMap([
            ['memory_cache.max_key_length', 60, 60],
        ]);

        $this->generator = new CacheKeyGenerator($config);
    }

    public function testTemplateWithNamedArgs(): void
    {
        $key = $this->generator->generate('cfg:{sid}:{column}', [
            'sid' => 'shop001',
            'column' => 'theme',
        ], 'Svc::method');

        $this->assertSame('cfg:shop001:theme', $key);
    }

    public function testTemplateWithNullArg(): void
    {
        $key = $this->generator->generate('cfg:{sid}:{column}', [
            'sid' => 'shop001',
            'column' => null,
        ], 'Svc::method');

        $this->assertSame('cfg:shop001:_null_', $key);
    }

    public function testTemplateWithBoolArg(): void
    {
        $key = $this->generator->generate('flag:{active}', [
            'active' => true,
        ], 'Svc::method');

        $this->assertSame('flag:1', $key);

        $key2 = $this->generator->generate('flag:{active}', [
            'active' => false,
        ], 'Svc::method');

        $this->assertSame('flag:0', $key2);
    }

    public function testUnresolvedPlaceholderThrowsException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not fully resolved');

        $this->generator->generate('cfg:{sid}:{missing}', [
            'sid' => 'shop001',
        ], 'Svc::method');
    }

    public function testNonScalarArgLeftUnresolved(): void
    {
        $this->expectException(\LogicException::class);

        $this->generator->generate('cfg:{obj}', [
            'obj' => new \stdClass(),
        ], 'Svc::method');
    }

    public function testNullTemplateUsesFallback(): void
    {
        $key = $this->generator->generate(null, [
            'sid' => 'shop001',
            'column' => 'theme',
        ], 'App\Svc::getCfg');

        $this->assertStringStartsWith('App\Svc::getCfg:', $key);
        $this->assertSame(strlen('App\Svc::getCfg:') + 32, strlen($key));
    }

    public function testEmptyTemplateUsesFallback(): void
    {
        $key = $this->generator->generate('', [
            'a' => 1,
        ], 'Svc::m');

        $this->assertStringContainsString(':', $key);
    }

    public function testLongKeyGetsHashed(): void
    {
        /** @var ConfigInterface&MockObject $config */
        $config = $this->createMock(ConfigInterface::class);
        $config->method('get')->willReturnMap([
            ['memory_cache.max_key_length', 60, 20],
        ]);

        $gen = new CacheKeyGenerator($config);

        $longTemplate = 'very_long_prefix_' . str_repeat('x', 30) . ':{sid}';
        $key = $gen->generate($longTemplate, ['sid' => '1'], 'Svc::m');

        $this->assertStringStartsWith('h:', $key);
        $this->assertSame(2 + 32, strlen($key));
    }

    public function testMaxKeyLength(): void
    {
        $this->assertSame(60, $this->generator->maxKeyLength());
    }

    public function testSameArgsProduceSameKey(): void
    {
        $args = ['sid' => 'shop001', 'column' => 'theme'];

        $key1 = $this->generator->generate('cfg:{sid}:{column}', $args, 'Svc::m');
        $key2 = $this->generator->generate('cfg:{sid}:{column}', $args, 'Svc::m');

        $this->assertSame($key1, $key2);
    }

    public function testDifferentArgsProduceDifferentKey(): void
    {
        $key1 = $this->generator->generate('cfg:{sid}', ['sid' => 'a'], 'Svc::m');
        $key2 = $this->generator->generate('cfg:{sid}', ['sid' => 'b'], 'Svc::m');

        $this->assertNotSame($key1, $key2);
    }
}
