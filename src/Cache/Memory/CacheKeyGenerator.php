<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

use Hyperf\Contract\ConfigInterface;

final class CacheKeyGenerator
{
    private const HASH_PREFIX = 'h:';

    private readonly int $maxKeyLength;

    public function __construct(ConfigInterface $config)
    {
        $this->maxKeyLength = max(16, (int) $config->get('memory_cache.max_key_length', 60));
    }

    /**
     * @param array<string, mixed> $argsByName
     */
    public function generate(?string $template, array $argsByName, string $methodFqn): string
    {
        if ($template === null || $template === '') {
            return $this->shorten(
                $methodFqn . ':' . md5(serialize($this->scalarOnly($argsByName)))
            );
        }

        $key = $this->applyTemplate($template, $argsByName);
        if (str_contains($key, '{')) {
            throw new \LogicException(sprintf(
                'MemoryCache key template not fully resolved: template=%s, args=%s',
                $template,
                implode(',', array_keys($argsByName))
            ));
        }

        return $this->shorten($key);
    }

    public function maxKeyLength(): int
    {
        return $this->maxKeyLength;
    }

    /**
     * @param array<string, mixed> $argsByName
     */
    private function applyTemplate(string $template, array $argsByName): string
    {
        foreach ($argsByName as $name => $value) {
            $placeholder = '{' . $name . '}';
            if (! str_contains($template, $placeholder)) {
                continue;
            }
            if (is_bool($value)) {
                $template = str_replace($placeholder, $value ? '1' : '0', $template);

                continue;
            }
            if ($value === null) {
                $template = str_replace($placeholder, '_null_', $template);

                continue;
            }
            if (! is_scalar($value)) {
                continue;
            }
            $template = str_replace($placeholder, (string) $value, $template);
        }

        return $template;
    }

    private function shorten(string $key): string
    {
        return strlen($key) > $this->maxKeyLength
            ? self::HASH_PREFIX . md5($key)
            : $key;
    }

    /**
     * @param array<string, mixed> $argsByName
     * @return array<string, scalar|null>
     */
    private function scalarOnly(array $argsByName): array
    {
        $out = [];
        foreach ($argsByName as $k => $v) {
            $out[(string) $k] = is_scalar($v) || $v === null ? $v : '<obj>';
        }

        return $out;
    }
}
