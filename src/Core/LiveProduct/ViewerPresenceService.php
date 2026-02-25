<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Core\LiveProduct;

use Doctrine\DBAL\Connection;
use Fib\LiveProductPulse\Config\LiveProductPulseConfig;
use Shopware\Core\Framework\Uuid\Uuid;

class ViewerPresenceService
{
    private const CLEANUP_PROBABILITY_DIVISOR = 60;

    public function __construct(
        private readonly Connection $connection,
        private readonly LiveProductPulseConfig $config
    ) {
    }

    public function touchAndCountViewers(string $productId, string $clientToken, ?string $salesChannelId = null): int
    {
        if (!Uuid::isValid($productId)) {
            return 0;
        }

        $normalizedToken = $this->normalizeClientToken($clientToken);
        if ($normalizedToken === null) {
            return 0;
        }

        $ttlSeconds = $this->config->getViewerTtlSeconds($salesChannelId);
        $tokenHash = hash('sha256', $normalizedToken, true);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $cutoff = (new \DateTimeImmutable(sprintf('-%d seconds', $ttlSeconds)))
            ->format('Y-m-d H:i:s.v');

        $this->connection->executeStatement(<<<SQL
INSERT INTO fib_live_product_pulse_viewer_presence (`product_id`, `viewer_token_hash`, `created_at`, `last_seen_at`)
VALUES (:productId, :tokenHash, :createdAt, :lastSeenAt)
ON DUPLICATE KEY UPDATE `last_seen_at` = VALUES(`last_seen_at`)
SQL, [
            'productId' => Uuid::fromHexToBytes($productId),
            'tokenHash' => $tokenHash,
            'createdAt' => $now,
            'lastSeenAt' => $now,
        ]);

        $this->cleanupStalePresenceMaybe($cutoff);

        $count = $this->connection->fetchOne(<<<SQL
SELECT COUNT(*)
FROM fib_live_product_pulse_viewer_presence
WHERE product_id = :productId
  AND last_seen_at >= :cutoff
  AND viewer_token_hash <> :tokenHash
SQL, [
            'productId' => Uuid::fromHexToBytes($productId),
            'cutoff' => $cutoff,
            'tokenHash' => $tokenHash,
        ]);

        return max(0, (int) $count);
    }

    public function removeViewer(string $productId, string $clientToken): void
    {
        if (!Uuid::isValid($productId)) {
            return;
        }

        $normalizedToken = $this->normalizeClientToken($clientToken);
        if ($normalizedToken === null) {
            return;
        }

        $this->connection->delete('fib_live_product_pulse_viewer_presence', [
            'product_id' => Uuid::fromHexToBytes($productId),
            'viewer_token_hash' => hash('sha256', $normalizedToken, true),
        ]);
    }

    private function normalizeClientToken(string $clientToken): ?string
    {
        $clientToken = trim($clientToken);
        if ($clientToken === '') {
            return null;
        }

        if (strlen($clientToken) > 128) {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $clientToken)) {
            return null;
        }

        return $clientToken;
    }

    private function cleanupStalePresenceMaybe(string $cutoff): void
    {
        if (random_int(1, self::CLEANUP_PROBABILITY_DIVISOR) !== 1) {
            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM fib_live_product_pulse_viewer_presence WHERE last_seen_at < :cutoff',
            ['cutoff' => $cutoff]
        );
    }
}
