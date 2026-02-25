<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Core\LiveProduct;

interface PulseRedisConnectionResolverInterface
{
    public function isEnabled(?string $salesChannelId = null): bool;

    /**
     * @return object|null
     */
    public function getConnection(?string $salesChannelId = null): ?object;
}
