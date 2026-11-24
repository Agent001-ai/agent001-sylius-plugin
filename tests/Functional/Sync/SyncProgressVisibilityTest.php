<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Functional\Sync;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Sync\Synchronizer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Product\Factory\ProductFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Agent001\SyliusAiAssistantPlugin\Functional\FunctionalTestCase;

final class SyncProgressVisibilityTest extends FunctionalTestCase
{
    private const SITE = 'https://shop.test';

    private const MESSAGE = 'agent001_sylius_ai_assistant.sync.';

    private Connection $observer;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->observer = DriverManager::getConnection($this->entityManager()->getConnection()->getParams());
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->observer->close();

        parent::tearDown();
    }

    public function testProgressIsCommittedWhileTheCatalogIsStillBeingSent(): void
    {
        $channel = $this->createChannel();
        $this->createProducts($channel, Synchronizer::DEFAULT_BATCH_SIZE + 1);
        $this->endRequest();
        $this->assertCount(Synchronizer::DEFAULT_BATCH_SIZE + 1, $this->takeQueuedMessages());

        $this->connectStore(self::SITE);
        $setting = new Agent001Setting(self::CHANNEL_CODE);
        $setting->assignAgent(self::AGENT_ID, 'embed-token', true, self::SITE);
        $this->entityManager()->persist($setting);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $observed = [];
        $this->backend()->onRequest(function (string $method, string $url) use (&$observed): void {
            if ('POST' === $method && str_ends_with($url, '/api/v1/ingest')) {
                $observed[] = $this->syncState();
            }
        });

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('agent001:sync'));
        $tester->execute(['--channel' => self::CHANNEL_CODE]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame([Agent001Setting::STATUS_QUEUED, null, []], $this->syncState());
        $this->assertSame([], $observed);

        $worker = new CommandTester($application->find('messenger:consume'));
        $worker->execute(['receivers' => ['agent001_sylius_ai_assistant_test'], '--limit' => 1]);
        $this->assertSame(Command::SUCCESS, $worker->getStatusCode());
        $this->assertSame([
            [Agent001Setting::STATUS_READING, self::MESSAGE . 'starting', []],
            [Agent001Setting::STATUS_READING, self::MESSAGE . 'progress', ['%sent%' => 500, '%total%' => 501]],
        ], $observed);
        $this->assertSame(
            [Agent001Setting::STATUS_INDEXING, self::MESSAGE . 'sent', ['%count%' => 501]],
            $this->syncState(),
        );
    }

    /**
     * @return array{mixed, mixed, mixed}
     */
    private function syncState(): array
    {
        $row = $this->observer->fetchAssociative(
            'SELECT sync_status, sync_message, sync_message_parameters FROM agent001_setting WHERE channel_code = ?',
            [self::CHANNEL_CODE],
        );
        self::assertIsArray($row);
        $parameters = $row['sync_message_parameters'];
        $parameters = is_string($parameters) ? json_decode($parameters, true) : [];

        return [$row['sync_status'], $row['sync_message'], $parameters];
    }

    private function createProducts(ChannelInterface $channel, int $count): void
    {
        $factory = self::getContainer()->get('sylius.factory.product');
        self::assertInstanceOf(ProductFactoryInterface::class, $factory);

        for ($i = 1; $i <= $count; ++$i) {
            $product = $factory->createWithVariant();
            self::assertInstanceOf(ProductInterface::class, $product);
            $product->setCurrentLocale(self::LOCALE);
            $product->setFallbackLocale(self::LOCALE);
            $product->setCode(self::PRODUCT_PREFIX . $i);
            $product->setName('Agent001 product ' . $i);
            $product->setSlug('agent001-ft-product-' . $i);
            $product->addChannel($channel);

            $variant = $product->getVariants()->first();
            self::assertInstanceOf(ProductVariantInterface::class, $variant);
            $variant->setCode(self::PRODUCT_PREFIX . $i);
            $variant->setTracked(false);

            $this->entityManager()->persist($product);
            if (0 === $i % 100) {
                $this->entityManager()->flush();
            }
        }

        $this->entityManager()->flush();
    }
}
