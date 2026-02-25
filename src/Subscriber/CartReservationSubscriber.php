<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Subscriber;

use Fib\LiveProductPulse\Core\LiveProduct\CartPresenceService;
use Fib\LiveProductPulse\Core\LiveProduct\CartReservationService;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemRemovedEvent;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CartSavedEvent;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CartReservationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CartReservationService $cartReservationService,
        private readonly CartPresenceService $cartPresenceService,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AfterLineItemAddedEvent::class => 'onCartMutated',
            AfterLineItemRemovedEvent::class => 'onCartMutated',
            CartSavedEvent::class => 'onCartSaved',
            CartConvertedEvent::class => 'onCartConverted',
        ];
    }

    public function onCartMutated(AfterLineItemAddedEvent|AfterLineItemRemovedEvent $event): void
    {
        try {
            $this->cartReservationService->syncCart($event->getCart());
            $this->cartPresenceService->touchCartToken(
                $event->getCart()->getToken(),
                $event->getSalesChannelContext()->getSalesChannelId()
            );
        } catch (\Throwable $exception) {
            $this->logger->error('FibLiveProductPulse failed to sync cart reservations on cart mutation', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function onCartSaved(CartSavedEvent $event): void
    {
        try {
            $this->cartReservationService->syncCart($event->getCart());
            $this->cartPresenceService->touchCartToken(
                $event->getCart()->getToken(),
                $event->getSalesChannelContext()->getSalesChannelId()
            );
        } catch (\Throwable $exception) {
            $this->logger->error('FibLiveProductPulse failed to sync cart reservations', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        try {
            $this->cartReservationService->clearCartReservations($event->getCart()->getToken());
            $this->cartPresenceService->clearCartToken($event->getCart()->getToken());
        } catch (\Throwable $exception) {
            $this->logger->error('FibLiveProductPulse failed to clear cart reservations on checkout', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
