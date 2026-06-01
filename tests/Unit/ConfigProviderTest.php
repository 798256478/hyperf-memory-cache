<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Tests\Unit;

use Groupbuy\HyperfMemoryCache\Cache\Memory\LocalCacheTablePool;
use Groupbuy\HyperfMemoryCache\ConfigProvider;
use Groupbuy\HyperfMemoryCache\Command\MemoryCacheStatsCommand;
use Groupbuy\HyperfMemoryCache\Listener\MemoryCacheTableInitializer;
use PHPUnit\Framework\TestCase;

class ConfigProviderTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $provider = new ConfigProvider();
        $this->config = $provider();
    }

    public function testAnnotationsScanPaths(): void
    {
        $this->assertArrayHasKey('annotations', $this->config);
        $this->assertArrayHasKey('scan', $this->config['annotations']);
        $this->assertArrayHasKey('paths', $this->config['annotations']['scan']);
        $this->assertNotEmpty($this->config['annotations']['scan']['paths']);
    }

    public function testListenersRegistered(): void
    {
        $this->assertArrayHasKey('listeners', $this->config);
        $this->assertArrayHasKey(MemoryCacheTableInitializer::class, $this->config['listeners']);
    }

    public function testCommandsRegistered(): void
    {
        $this->assertArrayHasKey('commands', $this->config);
        $this->assertContains(MemoryCacheStatsCommand::class, $this->config['commands']);
    }

    public function testPublishConfig(): void
    {
        $this->assertArrayHasKey('publish', $this->config);
        $this->assertNotEmpty($this->config['publish']);

        $publishItem = $this->config['publish'][0];
        $this->assertSame('config', $publishItem['id']);
        $this->assertArrayHasKey('source', $publishItem);
        $this->assertArrayHasKey('destination', $publishItem);
        $this->assertSame('config/autoload/memory_cache.php', $publishItem['destination']);
    }

    public function testLocalCacheTablePoolRegistered(): void
    {
        $this->assertArrayHasKey('dependencies', $this->config);
        $this->assertArrayHasKey(LocalCacheTablePool::class, $this->config['dependencies']);
    }
}
