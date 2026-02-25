<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Core\LiveProduct;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CartPresenceService
{
    private const CONFIG_DOMAIN = 'FibLiveProductPulse.config.';
    private const CLEANUP_PROBABILITY_DIVISOR = 60;

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService,
        private readonly PulseRedisConnectionResolverInterface $redisConnectionResolver
    ) {
    }

    public function touchCartToken(string $cartToken, ?string $salesChannelId = null): void
    {
        if (empty($cartToken)) {
            return;
        }

        $redis = $this->redisConnectionResolver->getConnection($salesChannelId);
        if (!empty($redis)) {
            $this->touchCartTokenRedis($redis, $cartToken, $salesChannelId);

            return;
        }

        $hash = hash('sha256', $cartToken, true);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $cutoff = (new \DateTimeImmutable(sprintf('-%d seconds', $this->getCartPresenceTtlSeconds($salesChannelId))))
            ->format('Y-m-d H:i:s.v');

        $this->connection->executeStatement(<<<SQL
            INSERT INTO fib_live_product_pulse_cart_presence (`cart_token_hash`, `created_at`, `last_seen_at`)
            VALUES (:cartTokenHash, :createdAt, :lastSeenAt)
            ON DUPLICATE KEY UPDATE `last_seen_at` = VALUES(`last_seen_at`)
        SQL, [
            'cartTokenHash' => $hash,
            'createdAt' => $now,
            'lastSeenAt' => $now,
        ]);

        $this->cleanupStalePresenceMaybe($cutoff);
    }

    public function clearCartToken(string $cartToken): void
    {
        if ($cartToken === '') {
            return;
        }

        $redis = $this->redisConnectionResolver->getConnection();
        if (!empty($redis)) {
            $this->clearCartTokenRedis($redis, $cartToken);

            return;
        }

        $this->connection->delete('fib_live_product_pulse_cart_presence', [
            'cart_token_hash' => hash('sha256', $cartToken, true),
        ]);
    }

    private function cleanupStalePresenceMaybe(string $cutoff): void
    {
        if (random_int(1, self::CLEANUP_PROBABILITY_DIVISOR) !== 1) {
            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM fib_live_product_pulse_cart_presence WHERE last_seen_at < :cutoff',
            ['cutoff' => $cutoff]
        );
    }

    private function getCartPresenceTtlSeconds(?string $salesChannelId = null): int
    {
        $value = $this->systemConfigService->getInt(self::CONFIG_DOMAIN . 'cartPresenceTtlSeconds', $salesChannelId);

        if ($value < 10 || $value > 3600) {
            return 120;
        }

        return $value;
    }

    /**
     */
    private function touchCartTokenRedis(object $redis, string $cartToken, ?string $salesChannelId): void
    {
        $ttlSeconds = $this->getCartPresenceTtlSeconds($salesChannelId);
        $now = time();
        $cutoff = $now - $ttlSeconds;
        $member = bin2hex(hash('sha256', $cartToken, true));
        $key = 'fib:lpp:cart_presence';

        $redis->zAdd($key, $now, $member);
        $redis->zRemRangeByScore($key, '-inf', (string) $cutoff);
    }

    /**
     */
    private function clearCartTokenRedis(object $redis, string $cartToken): void
    {
        $member = bin2hex(hash('sha256', $cartToken, true));
        $redis->zRem('fib:lpp:cart_presence', $member);
    }
}
