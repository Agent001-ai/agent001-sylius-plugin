<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Order;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

final class OrderSerializer implements OrderSerializerInterface
{
    public function __construct(
        private readonly TrackingResolverInterface $trackingResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(OrderInterface $order): array
    {
        $shippingMethods = [];

        /** @var ShipmentInterface $shipment */
        foreach ($order->getShipments() as $shipment) {
            if (null !== $shipment->getMethod() && null !== $shipment->getMethod()->getName()) {
                $shippingMethods[] = (string) $shipment->getMethod()->getName();
            }
        }

        $tracking = $this->trackingResolver->resolve($order);
        $trackingNumbers = array_column($tracking, 'number');

        $paidAt = null;
        /** @var PaymentInterface $payment */
        foreach ($order->getPayments() as $payment) {
            if (PaymentInterface::STATE_COMPLETED === $payment->getState() && null !== $payment->getUpdatedAt()) {
                $paidAt = $payment->getUpdatedAt()->format('c');

                break;
            }
        }

        $completedAt = null;
        if (OrderInterface::STATE_FULFILLED === $order->getState() && null !== $order->getUpdatedAt()) {
            $completedAt = $order->getUpdatedAt()->format('c');
        }

        $items = [];
        /** @var OrderItemInterface $item */
        foreach ($order->getItems() as $item) {
            $items[] = [
                'name' => (string) ($item->getProductName() ?? ''),
                'quantity' => (int) $item->getQuantity(),
                'subtotal' => number_format(($item->getUnitPrice() * $item->getQuantity()) / 100, 2, '.', ''),
                'total' => number_format($item->getTotal() / 100, 2, '.', ''),
            ];
        }

        return [
            'order_number' => (string) $order->getNumber(),
            'status' => (string) $order->getState(),
            'status_label' => ucfirst(str_replace('_', ' ', (string) $order->getState())),
            'created_at' => $order->getCreatedAt()?->format('c'),
            'updated_at' => $order->getUpdatedAt()?->format('c'),
            'paid_at' => $paidAt,
            'completed_at' => $completedAt,
            'currency' => (string) $order->getCurrencyCode(),
            'total' => number_format($order->getTotal() / 100, 2, '.', ''),
            'shipping_method' => implode(', ', array_unique($shippingMethods)),
            'tracking_number' => $trackingNumbers[0] ?? null,
            'tracking_numbers' => $trackingNumbers,
            'tracking' => $tracking,
            'items' => $items,
        ];
    }
}
