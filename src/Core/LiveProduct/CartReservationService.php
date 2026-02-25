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
        private readonly SystemConfigService $systemConfigService,
        private readonly PulseRedisClientProvider $redisClientProvider
    ) {
    }

    public function syncCart(Cart $cart): void
    {
        $cartToken = $cart->getToken();
        if (empty($cartToken)) {
            return;
        }

        $quantities = $this->collectProductQuantities($cart->getLineItems());
        $redis = $this->redisClientProvider->getConnection();
        if ($redis !== null) {
            $this->syncCartRedis($redis, $cartToken, $quantities);

            return;
        }

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

        $redis = $this->redisClientProvider->getConnection();
        if ($redis !== null) {
            $this->clearCartReservationsRedis($redis, $cartToken);

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

        $redis = $this->redisClientProvider->getConnection($salesChannelId);
        if ($redis !== null) {
            return $this->getReservedQuantityForProductRedis($redis, $productId, $salesChannelId, $excludeCartToken);
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

        $redis = $this->redisClientProvider->getConnection($salesChannelId);
        if ($redis !== null) {
            return $this->getAllocatedQuantityForCartTokenRedis($redis, $productId, $cartToken, $stock, $salesChannelId);
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
     * @param object $redis
     * @param array<string,int> $quantities
     */
    private function syncCartRedis(object $redis, string $cartToken, array $quantities): void
    {
        if (!method_exists($redis, 'sMembers')) {
            return;
        }

        $cartHashHex = $this->cartTokenHashHex($cartToken);
        $cartProductsKey = $this->redisCartProductsKey($cartHashHex);
        $now = time();

        $existingProducts = $redis->sMembers($cartProductsKey);
        if (!is_array($existingProducts)) {
            $existingProducts = [];
        }

        foreach ($existingProducts as $existingProductId) {
            if (!is_string($existingProductId) || isset($quantities[$existingProductId])) {
                continue;
            }

            $this->removeRedisReservationForCartProduct($redis, $cartHashHex, $existingProductId);
        }

        foreach ($quantities as $productId => $quantity) {
            if (!Uuid::isValid($productId) || $quantity < 1) {
                continue;
            }

            $qtyKey = $this->redisReservationQtyKey($productId);
            $orderKey = $this->redisReservationOrderKey($productId);
            $updatedKey = $this->redisReservationUpdatedKey($productId);

            if (method_exists($redis, 'zScore')) {
                $existingOrder = $redis->zScore($orderKey, $cartHashHex);
                if ($existingOrder === false) {
                    $redis->zAdd($orderKey, $now, $cartHashHex);
                }
            } else {
                $redis->zAdd($orderKey, $now, $cartHashHex);
            }

            $redis->hSet($qtyKey, $cartHashHex, (string) $quantity);
            $redis->hSet($updatedKey, $cartHashHex, (string) $now);
            $redis->sAdd($cartProductsKey, $productId);
        }
    }

    /**
     * @param object $redis
     */
    private function clearCartReservationsRedis(object $redis, string $cartToken): void
    {
        if (!method_exists($redis, 'sMembers')) {
            return;
        }

        $cartHashHex = $this->cartTokenHashHex($cartToken);
        $cartProductsKey = $this->redisCartProductsKey($cartHashHex);
        $products = $redis->sMembers($cartProductsKey);
        if (!is_array($products)) {
            $products = [];
        }

        foreach ($products as $productId) {
            if (!is_string($productId)) {
                continue;
            }

            $this->removeRedisReservationForCartProduct($redis, $cartHashHex, $productId);
        }

        if (method_exists($redis, 'del')) {
            $redis->del($cartProductsKey);
        }
    }

    /**
     * @param object $redis
     */
    private function getReservedQuantityForProductRedis(
        object $redis,
        string $productId,
        ?string $salesChannelId,
        ?string $excludeCartToken
    ): int {
        $rows = $this->fetchActiveReservationRowsRedis($redis, $productId, $salesChannelId);
        if ($rows === []) {
            return 0;
        }

        $excludeHash = null;
        if (is_string($excludeCartToken) && $excludeCartToken !== '') {
            $excludeHash = $this->cartTokenHashHex($excludeCartToken);
        }

        $sum = 0;
        foreach ($rows as $row) {
            if ($excludeHash !== null && ($row['cartTokenHash'] ?? '') === $excludeHash) {
                continue;
            }

            $sum += max(0, (int) ($row['quantity'] ?? 0));
        }

        return max(0, $sum);
    }

    /**
     * @param object $redis
     */
    private function getAllocatedQuantityForCartTokenRedis(
        object $redis,
        string $productId,
        string $cartToken,
        int $stock,
        ?string $salesChannelId
    ): int {
        $rows = $this->fetchActiveReservationRowsRedis($redis, $productId, $salesChannelId);
        if ($rows === [] || $stock < 1) {
            return 0;
        }

        $targetHash = $this->cartTokenHashHex($cartToken);
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

    /**
     * @param object $redis
     */
    private function removeRedisReservationForCartProduct(object $redis, string $cartHashHex, string $productId): void
    {
        if (!Uuid::isValid($productId)) {
            return;
        }

        $qtyKey = $this->redisReservationQtyKey($productId);
        $orderKey = $this->redisReservationOrderKey($productId);
        $updatedKey = $this->redisReservationUpdatedKey($productId);
        $cartProductsKey = $this->redisCartProductsKey($cartHashHex);

        if (method_exists($redis, 'hDel')) {
            $redis->hDel($qtyKey, $cartHashHex);
            $redis->hDel($updatedKey, $cartHashHex);
        }

        if (method_exists($redis, 'zRem')) {
            $redis->zRem($orderKey, $cartHashHex);
        }

        if (method_exists($redis, 'sRem')) {
            $redis->sRem($cartProductsKey, $productId);
        }
    }

    /**
     * @param object $redis
     *
     * @return list<array{cartTokenHash:string,quantity:int}>
     */
    private function fetchActiveReservationRowsRedis(object $redis, string $productId, ?string $salesChannelId = null): array
    {
        if (!method_exists($redis, 'zRange') || !method_exists($redis, 'zScore') || !method_exists($redis, 'hGet')) {
            return [];
        }

        $ttlSeconds = $this->getReservationTtlSeconds($salesChannelId);
        $cartPresenceTtlSeconds = $this->getCartPresenceTtlSeconds($salesChannelId);
        $reservationCutoff = time() - $ttlSeconds;
        $presenceCutoff = time() - $cartPresenceTtlSeconds;

        $orderKey = $this->redisReservationOrderKey($productId);
        $qtyKey = $this->redisReservationQtyKey($productId);
        $updatedKey = $this->redisReservationUpdatedKey($productId);
        $presenceKey = 'fib:lpp:cart_presence';

        $members = $redis->zRange($orderKey, 0, -1);
        if (!is_array($members) || $members === []) {
            return [];
        }

        $result = [];
        foreach ($members as $member) {
            if (!is_string($member) || $member === '') {
                continue;
            }

            $presenceScore = $redis->zScore($presenceKey, $member);
            if ($presenceScore === false || (int) $presenceScore < $presenceCutoff) {
                continue;
            }

            $updatedAt = $redis->hGet($updatedKey, $member);
            if ($updatedAt === false || (int) $updatedAt < $reservationCutoff) {
                continue;
            }

            $quantity = $redis->hGet($qtyKey, $member);
            if ($quantity === false) {
                continue;
            }

            $quantityInt = (int) $quantity;
            if ($quantityInt < 1) {
                continue;
            }

            $result[] = [
                'cartTokenHash' => $member,
                'quantity' => $quantityInt,
            ];
        }

        return $result;
    }

    private function cartTokenHashHex(string $cartToken): string
    {
        return bin2hex(hash('sha256', $cartToken, true));
    }

    private function redisCartProductsKey(string $cartHashHex): string
    {
        return 'fib:lpp:cart:' . $cartHashHex . ':products';
    }

    private function redisReservationQtyKey(string $productId): string
    {
        return 'fib:lpp:resv:' . $productId . ':qty';
    }

    private function redisReservationOrderKey(string $productId): string
    {
        return 'fib:lpp:resv:' . $productId . ':order';
    }

    private function redisReservationUpdatedKey(string $productId): string
    {
        return 'fib:lpp:resv:' . $productId . ':upd';
    }
}
