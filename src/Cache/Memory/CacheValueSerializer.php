<?php

declare(strict_types=1);

namespace Groupbuy\HyperfMemoryCache\Cache\Memory;

use Hyperf\Contract\ConfigInterface;

final class CacheValueSerializer implements CacheValueSerializerInterface
{
    public const MODE_IGBINARY = 'igbinary';

    public const MODE_PHP = 'php';

    private readonly string $mode;

    public function __construct(ConfigInterface $config)
    {
        $configured = (string) $config->get('memory_cache.serializer', 'auto');
        if ($configured === self::MODE_IGBINARY) {
            if (! \extension_loaded('igbinary')) {
                throw new \RuntimeException('memory_cache.serializer=igbinary but ext-igbinary is not installed');
            }
            $this->mode = self::MODE_IGBINARY;
        } elseif ($configured === self::MODE_PHP) {
            $this->mode = self::MODE_PHP;
        } else {
            $this->mode = \extension_loaded('igbinary') ? self::MODE_IGBINARY : self::MODE_PHP;
        }
    }

    public function serialize(mixed $value): string
    {
        $envelope = ['_v' => $value];

        if ($this->mode === self::MODE_IGBINARY) {
            $fn = 'igbinary_serialize';

            return (string) $fn($envelope);
        }

        return \serialize($envelope);
    }

    /**
     * @return array{ok: bool, value: mixed}
     */
    public function unserialize(string $payload): array
    {
        if ($payload === '') {
            return ['ok' => false, 'value' => null];
        }

        try {
            if ($this->mode === self::MODE_IGBINARY) {
                $fn = 'igbinary_unserialize';
                $decoded = @$fn($payload);
            } else {
                $decoded = @\unserialize($payload, ['allowed_classes' => true]);
            }
        } catch (\Throwable) {
            return ['ok' => false, 'value' => null];
        }

        if (! is_array($decoded) || ! \array_key_exists('_v', $decoded)) {
            return ['ok' => false, 'value' => null];
        }

        return ['ok' => true, 'value' => $decoded['_v']];
    }

    public function mode(): string
    {
        return $this->mode;
    }
}
