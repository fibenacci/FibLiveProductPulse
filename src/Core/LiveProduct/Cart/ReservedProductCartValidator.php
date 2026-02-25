<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Core\LiveProduct\Cart;

use Fib\LiveProductPulse\Core\LiveProduct\ProductLiveStateService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ReservedProductCartValidator implements CartValidatorInterface
{
    private const CONFIG_DOMAIN = 'FibLiveProductPulse.config.';
    private const ERROR_KEY = 'fib-live-product-pulse.storefront.cart.reserved';

    public function __construct(
        private readonly ProductLiveStateService $productLiveStateService,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function validate(
        Cart $cart,
        ErrorCollection $errors,
        SalesChannelContext $context
    ): void {
        if (!$this->systemConfigService->getBool(
            self::CONFIG_DOMAIN . 'lockReservedProducts',
            $context->getSalesChannelId()
        )) {
            return;
        }

        $productIds = [];
        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $productId = $lineItem->getReferencedId();
            if (!is_string($productId) || !Uuid::isValid($productId)) {
                continue;
            }

            $productIds[$productId] = $productId;
        }

        foreach ($productIds as $productId) {
            $requestedQuantity = $this->getRequestedQuantityForProduct($cart, $productId);
            if ($requestedQuantity < 1) {
                continue;
            }

            $state = $this->productLiveStateService->loadStockState(
                $productId,
                $context->getSalesChannelId(),
                $context->getToken()
            );

            if (!is_array($state)) {
                continue;
            }

            if (($state['statusCode'] ?? null) !== 'reserved') {
                continue;
            }

            $allocatedQuantity = (int) ($state['currentCartAllocatedQuantity'] ?? 0);
            if ($allocatedQuantity >= $requestedQuantity) {
                continue;
            }

            $errorId = self::ERROR_KEY . '-' . $productId;
            if ($errors->has($errorId)) {
                continue;
            }

            $errors->add(new GenericCartError(
                $errorId,
                self::ERROR_KEY,
                [],
                Error::LEVEL_ERROR,
                true,
                false,
                true
            ));
        }
    }

    private function getRequestedQuantityForProduct(Cart $cart, string $productId): int
    {
        $quantity = 0;

        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            if ($lineItem->getReferencedId() !== $productId) {
                continue;
            }

            $quantity += max(0, $lineItem->getQuantity());
        }

        return $quantity;
    }
}
