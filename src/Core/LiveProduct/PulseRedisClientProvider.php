<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Core\LiveProduct;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Adapter\Redis\RedisConnectionProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class PulseRedisClientProvider
{
    private const CONFIG_DOMAIN = 'FibLiveProductPulse.config.';

    /**
     * @var object|null
     */
    private ?object $cachedConnection = null;

    private ?string $cachedConnectionName = null;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly RedisConnectionProvider $redisConnectionProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(self::CONFIG_DOMAIN . 'useRedisBackend', $salesChannelId);
    }

    /**
     * @return object|null
     */
    public function getConnection(?string $salesChannelId = null): ?object
    {
        if (!$this->isEnabled($salesChannelId)) {
            return null;
        }

        $connectionName = $this->systemConfigService->getString(
            self::CONFIG_DOMAIN . 'redisConnectionName',
            $salesChannelId
        );

        if (empty($connectionName)) {
            $connectionName = 'default';
        }

        if ($this->cachedConnectionName === $connectionName && !empty($this->cachedConnection)) {
            return $this->cachedConnection;
        }

        try {
            if (!$this->redisConnectionProvider->hasConnection($connectionName)) {
                return null;
            }

            $connection = $this->redisConnectionProvider->getConnection($connectionName);

            if (str_starts_with($connection::class, 'Predis\\')) {
                $this->logger->warning('FibLiveProductPulse Redis fallback to SQL (Predis client not supported by pulse adapter)', [
                    'connectionName' => $connectionName,
                    'redisClass' => $connection::class,
                ]);

                return null;
            }

            $this->cachedConnectionName = $connectionName;
            $this->cachedConnection = $connection;

            return $connection;
        } catch (\Throwable $exception) {
            $this->logger->warning('FibLiveProductPulse Redis fallback to SQL', [
                'connectionName' => $connectionName,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
