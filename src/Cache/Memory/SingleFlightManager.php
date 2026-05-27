<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Engine\Channel;

final class SingleFlightManager implements SingleFlightManagerInterface
{
    private const SIG_OK = 'ok';

    private const SIG_FAIL = 'fail';

    /** @var array<string, true> */
    private array $leaders = [];

    /** @var array<string, list<Channel>> */
    private array $waiters = [];

    private readonly float $waitTimeout;

    private readonly bool $globallyEnabled;

    public function __construct(ConfigInterface $config)
    {
        $this->globallyEnabled = (bool) $config->get('memory_cache.singleflight.enabled', true);
        $this->waitTimeout = (float) $config->get('memory_cache.singleflight.wait_timeout', 3.0);
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function do(string $key, Closure $callback, bool $enabled = true): mixed
    {
        if (! $this->globallyEnabled || ! $enabled || $key === '') {
            return $callback();
        }

        if (isset($this->leaders[$key])) {
            return $this->waitOrRetry($key, $callback);
        }

        $this->leaders[$key] = true;
        $this->waiters[$key] ??= [];

        try {
            $value = $callback();
            $this->broadcast($key, self::SIG_OK, $value);

            return $value;
        } catch (\Throwable $e) {
            $this->broadcast($key, self::SIG_FAIL, null);

            throw $e;
        } finally {
            unset($this->leaders[$key], $this->waiters[$key]);
        }
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function waitOrRetry(string $key, Closure $callback): mixed
    {
        $ch = new Channel(1);
        $this->waiters[$key][] = $ch;

        $msg = $ch->pop($this->waitTimeout);

        if ($msg === false || ! is_array($msg)) {
            return $callback();
        }

        if (($msg['sig'] ?? null) === self::SIG_OK) {
            return $msg['v'];
        }

        return $callback();
    }

    private function broadcast(string $key, string $signal, mixed $value): void
    {
        $waiters = $this->waiters[$key] ?? [];
        foreach ($waiters as $ch) {
            $ch->push(['sig' => $signal, 'v' => $value], 0.05);
        }
    }
}
