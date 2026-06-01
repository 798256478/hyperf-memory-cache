<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

#[Attribute(Attribute::TARGET_METHOD)]
final class MemoryCacheEvict extends AbstractAnnotation
{
    public function __construct(
        public string $key,
        public bool $alwaysEvict = false,
        public string $channel = 'default',
    ) {
    }
}
