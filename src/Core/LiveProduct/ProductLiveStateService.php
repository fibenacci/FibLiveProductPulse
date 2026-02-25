<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Core\LiveProduct;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProductLiveStateService
{
    private const CONFIG_DOMAIN = 'FibLiveProductPulse.config.';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService,
        private readonly CartReservationService $cartReservationService,
        private readonly CartPresenceService $cartPresenceService,
        private readonly ViewerPresenceService $viewerPresenceService
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadStockState(
        string $productId,
        ?string $salesChannelId = null,
        ?string $excludeCartToken = null
    ): ?array
    {
        if (!Uuid::isValid($productId)) {
            return null;
        }

        $product = $this->fetchProductState($productId);
        if ($product === null) {
            return null;
        }

        $reservedQuantity = $this->cartReservationService->getReservedQuantityForProduct(
            $productId,
            $salesChannelId,
            $excludeCartToken
        );

        $stock = max(0, (int) ($product['stock'] ?? 0));
        $effectiveStock = max(0, $stock - $reservedQuantity);
        $minPurchase = max(1.0, (float) ($product['minPurchase'] ?? 1));
        $isCloseout = (bool) ($product['isCloseout'] ?? false);
        $currentCartAllocatedQuantity = 0;
        if (is_string($excludeCartToken) && $excludeCartToken !== '') {
            $currentCartAllocatedQuantity = $this->cartReservationService->getAllocatedQuantityForCartToken(
                $productId,
                $excludeCartToken,
                $stock,
                $salesChannelId
            );
        }
        $statusCode = $this->resolveStatusCode(
            (bool) ($product['active'] ?? false),
            $stock,
            $effectiveStock,
            $reservedQuantity,
            $currentCartAllocatedQuantity,
            $minPurchase,
            $isCloseout,
            (int) ($product['restockTime'] ?? 0),
            (bool) ($product['hasDeliveryTime'] ?? false),
            $product['releaseDate'] ?? null
        );

        return [
            'productId' => $productId,
            'stock' => $stock,
            'effectiveStock' => $effectiveStock,
            'reservedQuantity' => $reservedQuantity,
            'currentCartAllocatedQuantity' => $currentCartAllocatedQuantity,
            'statusCode' => $statusCode,
            'isReservedByOtherCart' => $statusCode === 'reserved',
            'lockReservedProducts' => $this->systemConfigService->getBool(self::CONFIG_DOMAIN . 'lockReservedProducts', $salesChannelId),
            'restockTime' => (int) ($product['restockTime'] ?? 0),
            'isCloseout' => $isCloseout,
            'minPurchase' => $minPurchase,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadViewerState(string $productId, string $viewerClientToken, ?string $salesChannelId = null): ?array
    {
        if (!Uuid::isValid($productId)) {
            return null;
        }

        $viewerCount = $this->viewerPresenceService->touchAndCountViewers($productId, $viewerClientToken, $salesChannelId);

        return [
            'productId' => $productId,
            'viewerCount' => $viewerCount,
        ];
    }

    public function removeViewer(string $productId, string $viewerClientToken): void
    {
        $this->viewerPresenceService->removeViewer($productId, $viewerClientToken);
    }

    public function touchCartPresence(string $cartToken, ?string $salesChannelId = null): void
    {
        $this->cartPresenceService->touchCartToken($cartToken, $salesChannelId);
    }

    public function clearCartPresence(string $cartToken): void
    {
        $this->cartPresenceService->clearCartToken($cartToken);
    }

    public function buildStockStateEtag(array $stockState): string
    {
        $fingerprint = [
            'productId' => $stockState['productId'] ?? null,
            'stock' => $stockState['stock'] ?? null,
            'effectiveStock' => $stockState['effectiveStock'] ?? null,
            'reservedQuantity' => $stockState['reservedQuantity'] ?? null,
            'currentCartAllocatedQuantity' => $stockState['currentCartAllocatedQuantity'] ?? null,
            'statusCode' => $stockState['statusCode'] ?? null,
            'isReservedByOtherCart' => $stockState['isReservedByOtherCart'] ?? null,
            'lockReservedProducts' => $stockState['lockReservedProducts'] ?? null,
            'restockTime' => $stockState['restockTime'] ?? null,
            'isCloseout' => $stockState['isCloseout'] ?? null,
            'minPurchase' => $stockState['minPurchase'] ?? null,
        ];

        return hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProductState(string $productId): ?array
    {
        $row = $this->connection->fetchAssociative(<<<SQL
            SELECT
                p.active,
                p.stock,
                p.min_purchase,
                p.is_closeout,
                p.restock_time,
                p.release_date,
                p.delivery_time_id
            FROM product p
            WHERE p.id = :productId
              AND p.version_id = :versionId
            LIMIT 1
        SQL, [
            'productId' => Uuid::fromHexToBytes($productId),
            'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
        ]);

        if (!is_array($row)) {
            return null;
        }

        return [
            'active' => (bool) ($row['active'] ?? false),
            'stock' => (int) ($row['stock'] ?? 0),
            'minPurchase' => (float) ($row['min_purchase'] ?? 1),
            'isCloseout' => (bool) ($row['is_closeout'] ?? false),
            'restockTime' => (int) ($row['restock_time'] ?? 0),
            'releaseDate' => is_string($row['release_date'] ?? null) ? $row['release_date'] : null,
            'hasDeliveryTime' => !empty($row['delivery_time_id']),
        ];
    }

    private function resolveStatusCode(
        bool $active,
        int $stock,
        int $effectiveStock,
        int $reservedQuantity,
        int $currentCartAllocatedQuantity,
        float $minPurchase,
        bool $isCloseout,
        int $restockTime,
        bool $hasDeliveryTime,
        ?string $releaseDate
    ): string {
        if (!$active) {
            return 'not_available';
        }

        if ($this->isFutureDate($releaseDate)) {
            return 'preorder';
        }

        if ($effectiveStock >= $minPurchase) {
            return $hasDeliveryTime ? 'available' : 'available';
        }

        if ($stock >= $minPurchase && $currentCartAllocatedQuantity >= $minPurchase) {
            return $hasDeliveryTime ? 'available' : 'available';
        }

        if ($stock >= $minPurchase && $reservedQuantity > 0 && $currentCartAllocatedQuantity < $minPurchase) {
            return 'reserved';
        }

        if ($isCloseout) {
            return 'soldout';
        }

        if ($restockTime > 0 && $hasDeliveryTime) {
            return 'restock';
        }

        return 'not_available';
    }

    private function isFutureDate(?string $releaseDate): bool
    {
        if (empty($releaseDate)) {
            return false;
        }

        $timestamp = strtotime($releaseDate);

        return $timestamp !== false && $timestamp > time();
    }
}
