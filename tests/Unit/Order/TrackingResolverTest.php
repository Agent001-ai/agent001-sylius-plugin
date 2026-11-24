<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Order;

use Agent001\SyliusAiAssistantPlugin\Order\TrackingResolver;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;

final class TrackingResolverTest extends TestCase
{
    public function testItBuildsCarrierLinkFromShippingMethodName(): void
    {
        $order = $this->orderWith([['123456789012345678901234', 'InPost Paczkomaty 24/7']]);
        $result = (new TrackingResolver())->resolve($order);

        $this->assertSame('123456789012345678901234', $result[0]['number']);
        $this->assertSame('inpost', $result[0]['carrier']);
        $this->assertSame(
            'https://inpost.pl/pl/pomoc/znajdz-przesylke?parcel=123456789012345678901234',
            $result[0]['url'],
        );
    }

    public function testItParsesKnownCarrierUrls(): void
    {
        $order = $this->orderWith([['https://tracktrace.dpd.com.pl/parcelDetails?p1=1000012345678', 'Courier']]);
        $result = (new TrackingResolver())->resolve($order);

        $this->assertSame('1000012345678', $result[0]['number']);
        $this->assertSame('dpd', $result[0]['carrier']);
    }

    public function testItKeepsUnknownCarriersWithoutLinkAndDeduplicates(): void
    {
        $order = $this->orderWith([['ABC123456', 'Own courier'], ['ABC123456', 'Own courier']]);
        $result = (new TrackingResolver())->resolve($order);

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]['carrier']);
        $this->assertSame('', $result[0]['url']);
    }

    public function testItIgnoresShipmentsWithoutTracking(): void
    {
        $this->assertSame([], (new TrackingResolver())->resolve($this->orderWith([[null, 'DPD']])));
    }

    /**
     * @param list<array{0: ?string, 1: string}> $shipments
     */
    private function orderWith(array $shipments): OrderInterface
    {
        $collection = new ArrayCollection();
        foreach ($shipments as [$tracking, $methodName]) {
            $method = $this->createMock(ShippingMethodInterface::class);
            $method->method('getName')->willReturn($methodName);
            $shipment = $this->createMock(ShipmentInterface::class);
            $shipment->method('getTracking')->willReturn($tracking);
            $shipment->method('getMethod')->willReturn($method);
            $collection->add($shipment);
        }

        $order = $this->createMock(OrderInterface::class);
        $order->method('getShipments')->willReturn($collection);

        return $order;
    }
}
