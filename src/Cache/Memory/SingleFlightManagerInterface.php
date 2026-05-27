<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

use Closure;

interface SingleFlightManagerInterface
{
    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function do(string $key, Closure $callback, bool $enabled = true): mixed;
}
