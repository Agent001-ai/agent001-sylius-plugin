<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Functional\Sync;

use Agent001\SyliusAiAssistantPlugin\Message\SyncProductMessage;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Product\Factory\ProductFactoryInterface;
use Tests\Agent001\SyliusAiAssistantPlugin\Functional\FunctionalTestCase;

final class ProductChangeDispatchTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->purge();
    }

    public function testChangesMadeOutsideTheAdminPanelAreSynchronized(): void
    {
        $channel = $this->createChannel();
        $product = $this->createProduct();
        $productId = (int) $product->getId();
        $this->assertGreaterThan(0, $productId);
        $this->endRequest();
        $this->assertEquals([new SyncProductMessage($productId)], $this->takeQueuedMessages());

        $product->setName('Renamed outside the admin panel');
        $this->entityManager()->flush();
        $this->endRequest();
        $this->assertEquals([new SyncProductMessage($productId)], $this->takeQueuedMessages());

        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $pricing = $this->factory('sylius.factory.channel_pricing')->createNew();
        self::assertInstanceOf(ChannelPricingInterface::class, $pricing);
        $pricing->setChannelCode((string) $channel->getCode());
        $pricing->setPrice(1999);
        $variant->addChannelPricing($pricing);
        $this->entityManager()->flush();
        $this->endRequest();
        $this->assertEquals([new SyncProductMessage($productId)], $this->takeQueuedMessages());

        $this->entityManager()->remove($product);
        $this->entityManager()->flush();
        $this->endRequest();
        $this->assertEquals(
            [new SyncProductMessage($productId, SyncProductMessage::OP_DELETE)],
            $this->takeQueuedMessages(),
        );
    }

    public function testAProductChangedSeveralTimesInOneRequestIsSentOnceAtItsEnd(): void
    {
        $product = $this->createProduct();
        $product->setName('First change');
        $this->entityManager()->flush();
        $product->setName('Second change');
        $this->entityManager()->flush();

        $this->assertSame([], $this->queuedMessages());

        $this->endRequest();
        $this->assertEquals([new SyncProductMessage((int) $product->getId())], $this->takeQueuedMessages());
    }

    private function createProduct(): ProductInterface
    {
        $factory = self::getContainer()->get('sylius.factory.product');
        self::assertInstanceOf(ProductFactoryInterface::class, $factory);
        $product = $factory->createWithVariant();
        self::assertInstanceOf(ProductInterface::class, $product);
        $product->setCurrentLocale(self::LOCALE);
        $product->setFallbackLocale(self::LOCALE);
        $product->setCode(self::PRODUCT_PREFIX . 'API');
        $product->setName('Created outside the admin panel');
        $product->setSlug('agent001-ft-api');

        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $variant->setCode(self::PRODUCT_PREFIX . 'API');

        $this->entityManager()->persist($product);
        $this->entityManager()->flush();

        return $product;
    }
}
