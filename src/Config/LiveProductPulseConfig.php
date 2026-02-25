<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class LiveProductPulseConfig
{
    private const CONFIG_DOMAIN = 'FibLiveProductPulse.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function getPollIntervalMs(?string $salesChannelId = null): int
    {
        return $this->getInt('pollIntervalMs', 4000, 1000, 60000, $salesChannelId);
    }

    public function isLockReservedProducts(?string $salesChannelId = null): bool
    {
        return $this->getBool('lockReservedProducts', true, $salesChannelId);
    }

    public function getBackgroundPollIntervalMs(?string $salesChannelId = null): int
    {
        return $this->getInt('backgroundPollIntervalMs', 15000, 2000, 120000, $salesChannelId);
    }

    public function getRequestTimeoutMs(?string $salesChannelId = null): int
    {
        return $this->getInt('requestTimeoutMs', 4000, 1000, 30000, $salesChannelId);
    }

    public function getMaxBackoffMs(?string $salesChannelId = null): int
    {
        return $this->getInt('maxBackoffMs', 60000, 2000, 300000, $salesChannelId);
    }

    public function getJitterRatio(?string $salesChannelId = null): float
    {
        $percent = $this->getInt('jitterPercent', 15, 0, 50, $salesChannelId);

        return $percent / 100;
    }

    public function getReservationTtlSeconds(?string $salesChannelId = null): int
    {
        return $this->getInt('reservationTtlSeconds', 1800, 60, 86400, $salesChannelId);
    }

    public function getViewerTtlSeconds(?string $salesChannelId = null): int
    {
        return $this->getInt('viewerTtlSeconds', 45, 10, 600, $salesChannelId);
    }

    public function getCartPresenceTtlSeconds(?string $salesChannelId = null): int
    {
        return $this->getInt('cartPresenceTtlSeconds', 120, 10, 3600, $salesChannelId);
    }

    private function getInt(string $key, int $default, int $min, int $max, ?string $salesChannelId): int
    {
        $value = $this->systemConfigService->get(self::CONFIG_DOMAIN . $key, $salesChannelId);

        if ($value === null) {
            return $default;
        }

        $intValue = (int) $value;
        if ($intValue < $min || $intValue > $max) {
            return $default;
        }

        return $intValue;
    }

    private function getBool(string $key, bool $default, ?string $salesChannelId): bool
    {
        $value = $this->systemConfigService->get(self::CONFIG_DOMAIN . $key, $salesChannelId);

        if ($value === null) {
            return $default;
        }

        return (bool) $value;
    }
}
