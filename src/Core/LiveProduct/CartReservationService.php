<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Core\LiveProduct;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CartReservationService
{
    private const CONFIG_DOMAIN = 'FibLiveProductPulse.config.';
    private const CLEANUP_PROBABILITY_DIVISOR = 100;

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function syncCart(Cart $cart): void
    {
        $cartToken = $cart->getToken();
        if (empty($cartToken)) {
            return;
        }

        $quantities = $this->collectProductQuantities($cart->getLineItems());
        $cartTokenHash = hash('sha256', $cartToken, true);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        $existingProductIds = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(product_id)) FROM fib_live_product_pulse_cart_reservation WHERE cart_token_hash = :cartTokenHash',
            ['cartTokenHash' => $cartTokenHash]
        );

        foreach ($existingProductIds as $existingProductId) {
            if (!is_string($existingProductId) || isset($quantities[$existingProductId])) {
                continue;
            }

            $this->connection->delete('fib_live_product_pulse_cart_reservation', [
                'cart_token_hash' => $cartTokenHash,
                'product_id' => Uuid::fromHexToBytes($existingProductId),
            ]);
        }

        foreach ($quantities as $productId => $quantity) {
            if (!Uuid::isValid($productId) || $quantity < 1) {
                continue;
            }

            $this->connection->executeStatement(<<<SQL
                INSERT INTO fib_live_product_pulse_cart_reservation (`cart_token_hash`, `product_id`, `quantity`, `created_at`, `updated_at`)
                VALUES (:cartTokenHash, :productId, :quantity, :createdAt, :updatedAt)
                ON DUPLICATE KEY UPDATE
                    `quantity` = VALUES(`quantity`),
                    `updated_at` = VALUES(`updated_at`)
            SQL, [
                'cartTokenHash' => $cartTokenHash,
                'productId' => Uuid::fromHexToBytes($productId),
                'quantity' => $quantity,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }

        $this->cleanupStaleReservationsMaybe();
    }

    public function clearCartReservations(string $cartToken): void
    {
        if (empty($cartToken)) {
            return;
        }

        $this->connection->delete('fib_live_product_pulse_cart_reservation', [
            'cart_token_hash' => hash('sha256', $cartToken, true),
        ]);
    }

    public function getReservedQuantityForProduct(
        string $productId,
        ?string $salesChannelId = null,
        ?string $excludeCartToken = null
    ): int
    {
        if (!Uuid::isValid($productId)) {
            return 0;
        }

        $ttlSeconds = $this->getReservationTtlSeconds($salesChannelId);
        $cartPresenceTtlSeconds = $this->getCartPresenceTtlSeconds($salesChannelId);
        $cutoff = (new \DateTimeImmutable(sprintf('-%d seconds', $ttlSeconds)))
            ->format('Y-m-d H:i:s.v');
        $presenceCutoff = (new \DateTimeImmutable(sprintf('-%d seconds', $cartPresenceTtlSeconds)))
            ->format('Y-m-d H:i:s.v');

        $params = [
            'productId' => Uuid::fromHexToBytes($productId),
            'cutoff' => $cutoff,
        ];

        $excludeClause = '';
        if (is_string($excludeCartToken) && $excludeCartToken !== '') {
            $excludeClause = ' AND r.cart_token_hash <> :excludeCartTokenHash';
            $params['excludeCartTokenHash'] = hash('sha256', $excludeCartToken, true);
        }

        $quantity = $this->connection->fetchOne(<<<SQL
            SELECT COALESCE(SUM(quantity), 0)
            FROM fib_live_product_pulse_cart_reservation r
            INNER JOIN fib_live_product_pulse_cart_presence cp
                ON cp.cart_token_hash = r.cart_token_hash
               AND cp.last_seen_at >= :presenceCutoff
            WHERE r.product_id = :productId
              AND r.updated_at >= :cutoff
            {$excludeClause}
        SQL, [
            ...$params,
            'presenceCutoff' => $presenceCutoff,
        ]);

        return max(0, (int) $quantity);
    }

    public function getAllocatedQuantityForCartToken(
        string $productId,
        string $cartToken,
        int $stock,
        ?string $salesChannelId = null
    ): int {
        if (!Uuid::isValid($productId) || $cartToken === '' || $stock < 1) {
            return 0;
        }

        $rows = $this->fetchActiveReservationRows($productId, $salesChannelId);
        if ($rows === []) {
            return 0;
        }

        $targetHash = bin2hex(hash('sha256', $cartToken, true));
        $cursor = 0;
        $allocated = 0;

        foreach ($rows as $row) {
            $quantity = max(0, (int) ($row['quantity'] ?? 0));
            if ($quantity < 1) {
                continue;
            }

            $rangeStart = $cursor;
            $rangeEnd = $cursor + $quantity;
            $overlap = max(0, min($rangeEnd, $stock) - $rangeStart);

            if ($overlap > 0 && (($row['cartTokenHash'] ?? '') === $targetHash)) {
                $allocated += $overlap;
            }

            $cursor = $rangeEnd;
            if ($cursor >= $stock && $allocated > 0) {
                // We already crossed the available stock window.
                continue;
            }
        }

        return $allocated;
    }

    /**
     * @return list<array{cartTokenHash:string,quantity:int}>
     */
    private function fetchActiveReservationRows(string $productId, ?string $salesChannelId = null): array
    {
        $ttlSeconds = $this->getReservationTtlSeconds($salesChannelId);
        $cartPresenceTtlSeconds = $this->getCartPresenceTtlSeconds($salesChannelId);
        $cutoff = (new \DateTimeImmutable(sprintf('-%d seconds', $ttlSeconds)))
            ->format('Y-m-d H:i:s.v');
        $presenceCutoff = (new \DateTimeImmutable(sprintf('-%d seconds', $cartPresenceTtlSeconds)))
            ->format('Y-m-d H:i:s.v');

        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT LOWER(HEX(r.cart_token_hash)) AS cart_token_hash, r.quantity
            FROM fib_live_product_pulse_cart_reservation r
            INNER JOIN fib_live_product_pulse_cart_presence cp
                ON cp.cart_token_hash = r.cart_token_hash
               AND cp.last_seen_at >= :presenceCutoff
            WHERE r.product_id = :productId
              AND r.updated_at >= :cutoff
            ORDER BY r.created_at ASC, r.cart_token_hash ASC
        SQL, [
            'productId' => Uuid::fromHexToBytes($productId),
            'cutoff' => $cutoff,
            'presenceCutoff' => $presenceCutoff,
        ]);

        $result = [];
        foreach ($rows as $row) {
            $hash = $row['cart_token_hash'] ?? null;
            if (!is_string($hash) || $hash === '') {
                continue;
            }

            $result[] = [
                'cartTokenHash' => $hash,
                'quantity' => (int) ($row['quantity'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function collectProductQuantities(LineItemCollection $lineItems): array
    {
        $quantities = [];
        $this->collectProductQuantitiesRecursive($lineItems, $quantities);

        return $quantities;
    }

    /**
     * @param array<string, int> $quantities
     */
    private function collectProductQuantitiesRecursive(
        LineItemCollection $lineItems,
        array &$quantities
    ): void {
        foreach ($lineItems as $lineItem) {
            if ($lineItem->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
                $referencedId = $lineItem->getReferencedId();

                if (is_string($referencedId) && Uuid::isValid($referencedId)) {
                    $quantities[$referencedId] = ($quantities[$referencedId] ?? 0) + max(0, $lineItem->getQuantity());
                }
            }

            $children = $lineItem->getChildren();
            if ($children instanceof LineItemCollection && $children->count() > 0) {
                $this->collectProductQuantitiesRecursive($children, $quantities);
            }
        }
    }

    private function cleanupStaleReservationsMaybe(): void
    {
        if (random_int(1, self::CLEANUP_PROBABILITY_DIVISOR) !== 1) {
            return;
        }

        $ttlSeconds = $this->getReservationTtlSeconds();
        $cutoff = (new \DateTimeImmutable(sprintf('-%d seconds', $ttlSeconds)))
            ->format('Y-m-d H:i:s.v');

        $this->connection->executeStatement(
            'DELETE FROM fib_live_product_pulse_cart_reservation WHERE updated_at < :cutoff',
            ['cutoff' => $cutoff]
        );
    }

    private function getReservationTtlSeconds(?string $salesChannelId = null): int
    {
        return $this->getConfiguredInt('reservationTtlSeconds', 1800, 60, 86400, $salesChannelId);
    }

    private function getCartPresenceTtlSeconds(?string $salesChannelId = null): int
    {
        return $this->getConfiguredInt('cartPresenceTtlSeconds', 120, 10, 3600, $salesChannelId);
    }

    private function getConfiguredInt(
        string $key,
        int $default,
        int $min,
        int $max,
        ?string $salesChannelId = null
    ): int {
        $value = $this->systemConfigService->getInt(self::CONFIG_DOMAIN . $key, $salesChannelId);

        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }
}
