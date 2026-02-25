<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Storefront\Controller;

use Fib\LiveProductPulse\Core\LiveProduct\ProductLiveStateService;
use Shopware\Core\Framework\Uuid\Exception\InvalidUuidException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class LiveProductPulseController extends StorefrontController
{
    public function __construct(
        private readonly ProductLiveStateService $productLiveStateService
    ) {
    }

    #[Route(
        path: '/fib/live-product-pulse/stock-state/{productId}',
        name: 'frontend.fib.live_product_pulse.stock_state',
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function stockState(
        string $productId,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): JsonResponse {
        if (!Uuid::isValid($productId)) {
            throw new InvalidUuidException($productId);
        }

        $state = $this->productLiveStateService->loadStockState(
            $productId,
            $salesChannelContext->getSalesChannelId(),
            $salesChannelContext->getToken()
        );

        if (empty($state)) {
            return $this->jsonNoStore(['success' => false], Response::HTTP_NOT_FOUND);
        }

        $response = new JsonResponse([
            'success' => true,
            'data' => $state,
        ]);

        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->setEtag($this->productLiveStateService->buildStockStateEtag($state), true);

        return $response;
    }

    #[Route(
        path: '/fib/live-product-pulse/viewers/{productId}',
        name: 'frontend.fib.live_product_pulse.viewers',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function viewers(
        string $productId,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): JsonResponse {
        if (!Uuid::isValid($productId)) {
            throw new InvalidUuidException($productId);
        }

        $payload = json_decode($request->getContent(), true);

        $clientToken = '';
        if (is_array($payload) && is_string($payload['clientToken'] ?? null)) {
            $clientToken = $payload['clientToken'];
        }

        $state = $this->productLiveStateService->loadViewerState(
            $productId,
            $clientToken,
            $salesChannelContext->getSalesChannelId()
        );

        if (empty($state)) {
            return $this->jsonNoStore(['success' => false], Response::HTTP_NOT_FOUND);
        }

        return $this->jsonNoStore([
            'success' => true,
            'data' => $state,
        ]);
    }

    #[Route(
        path: '/fib/live-product-pulse/viewers/{productId}/leave',
        name: 'frontend.fib.live_product_pulse.viewers_leave',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function leaveViewers(string $productId, Request $request): JsonResponse
    {
        if (!Uuid::isValid($productId)) {
            throw new InvalidUuidException($productId);
        }

        $payload = json_decode($request->getContent(), true);
        $clientToken = '';
        if (!empty($payload['clientToken'] ?? null)) {
            $clientToken = $payload['clientToken'];
        }

        $this->productLiveStateService->removeViewer($productId, $clientToken);

        return $this->jsonNoStore(['success' => true]);
    }

    #[Route(
        path: '/fib/live-product-pulse/cart-presence/heartbeat',
        name: 'frontend.fib.live_product_pulse.cart_presence_heartbeat',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function cartPresenceHeartbeat(SalesChannelContext $salesChannelContext): JsonResponse
    {
        $this->productLiveStateService->touchCartPresence(
            $salesChannelContext->getToken(),
            $salesChannelContext->getSalesChannelId()
        );

        return $this->jsonNoStore(['success' => true]);
    }

    #[Route(
        path: '/fib/live-product-pulse/cart-presence/leave',
        name: 'frontend.fib.live_product_pulse.cart_presence_leave',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function cartPresenceLeave(SalesChannelContext $salesChannelContext): JsonResponse
    {
        $this->productLiveStateService->clearCartPresence($salesChannelContext->getToken());

        return $this->jsonNoStore(['success' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonNoStore(
        array $payload,
        int $status = Response::HTTP_OK
    ): JsonResponse {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
