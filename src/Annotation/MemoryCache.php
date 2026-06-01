<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

#[Attribute(Attribute::TARGET_METHOD)]
final class MemoryCache extends AbstractAnnotation
{
    public function __construct(
        public ?string $key = null,
        public ?int $ttl = null,
        public bool $cacheNull = false,
        public bool $singleFlight = true,
        public bool $jitter = true,
        public string $channel = 'default',
    ) {
    }
}
