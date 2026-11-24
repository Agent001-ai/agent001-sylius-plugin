<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Order;

use Sylius\Component\Core\Model\OrderInterface;

interface TrackingResolverInterface
{
    /**
     * @return list<array{number: string, carrier: string, url: string}>
     */
    public function resolve(OrderInterface $order): array;
}
