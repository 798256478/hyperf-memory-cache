<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

interface SingleFlightManagerInterface
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function do(string $key, callable $callback, bool $enabled = true): mixed;
}
