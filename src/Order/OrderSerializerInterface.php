<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Order;

use Sylius\Component\Core\Model\OrderInterface;

interface OrderSerializerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(OrderInterface $order): array;
}
