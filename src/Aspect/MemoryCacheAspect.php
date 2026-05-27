<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Aspect;

use Groupbuy\HyperfMemoryCache\Annotation\MemoryCache;
use Groupbuy\HyperfMemoryCache\Annotation\MemoryCacheEvict;
use Groupbuy\HyperfMemoryCache\Cache\Memory\CacheKeyGenerator;
use Groupbuy\HyperfMemoryCache\Cache\Memory\MemoryCacheManager;
use Groupbuy\HyperfMemoryCache\Cache\Memory\MemoryCacheMetrics;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

#[Aspect]
final class MemoryCacheAspect extends AbstractAspect
{
    public array $annotations = [MemoryCache::class, MemoryCacheEvict::class];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MemoryCacheManager $manager,
        private readonly CacheKeyGenerator $keyGenerator,
        private readonly MemoryCacheMetrics $metrics,
        private readonly ConfigInterface $config,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = $loggerFactory->get('cache.memory');
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        if (! $this->isEnabled()) {
            return $proceedingJoinPoint->process();
        }

        $metadata = $proceedingJoinPoint->getAnnotationMetadata()->method;
        $evict = $metadata[MemoryCacheEvict::class] ?? null;
        $read = $metadata[MemoryCache::class] ?? null;

        try {
            if ($evict instanceof MemoryCacheEvict) {
                return $this->handleEvict($proceedingJoinPoint, $evict);
            }
            if ($read instanceof MemoryCache) {
                return $this->handleRead($proceedingJoinPoint, $read);
            }
        } catch (\Throwable $e) {
            $this->metrics->recordError();
            $this->logger->warning('memory_cache aspect degraded to original method', [
                'class'  => $proceedingJoinPoint->className,
                'method' => $proceedingJoinPoint->methodName,
                'error'  => $e->getMessage(),
            ]);
        }

        return $proceedingJoinPoint->process();
    }

    private function handleRead(ProceedingJoinPoint $jp, MemoryCache $anno): mixed
    {
        $key = $this->keyGenerator->generate(
            $anno->key,
            $this->argsByName($jp),
            $jp->className . '::' . $jp->methodName,
        );

        return $this->manager->remember(
            $key,
            static fn () => $jp->process(),
            $anno->ttl,
            $anno->cacheNull,
            $anno->singleFlight,
            $anno->jitter,
        );
    }

    private function handleEvict(ProceedingJoinPoint $jp, MemoryCacheEvict $anno): mixed
    {
        $key = $this->keyGenerator->generate(
            $anno->key,
            $this->argsByName($jp),
            $jp->className . '::' . $jp->methodName,
        );

        try {
            $result = $jp->process();
            $this->manager->evict($key);

            return $result;
        } catch (\Throwable $e) {
            if ($anno->alwaysEvict) {
                $this->manager->evict($key);
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function argsByName(ProceedingJoinPoint $jp): array
    {
        $keys = $jp->arguments['keys'] ?? [];

        return is_array($keys) ? $keys : [];
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('memory_cache.enabled', false);
    }
}
