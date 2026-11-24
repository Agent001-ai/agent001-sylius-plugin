<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\EventListener;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001Client;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\EventListener\ProductLifecycleListener;
use Agent001\SyliusAiAssistantPlugin\Message\SyncProductMessage;
use Agent001\SyliusAiAssistantPlugin\MessageHandler\SyncProductMessageHandler;
use Agent001\SyliusAiAssistantPlugin\Reader\ProductReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductTranslation;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\Taxon;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

final class ProductLifecycleListenerTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    private ProductLifecycleListener $listener;

    protected function setUp(): void
    {
        $this->dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });
        $this->listener = new ProductLifecycleListener($bus, new NullLogger());
    }

    public function testANewProductIsUpsertedWithTheIdAssignedDuringTheFlush(): void
    {
        $product = new Product();

        $this->flush(insertions: [$product], afterFlush: fn () => $this->setId($product, 7));

        $this->assertEquals([new SyncProductMessage(7, SyncProductMessage::OP_UPSERT)], $this->sent());
    }

    public function testChangesOfTranslationsVariantsAndPricesUpsertTheirProductOnce(): void
    {
        $product = $this->product(5);
        $translation = new ProductTranslation();
        $translation->setLocale('en_US');
        $translation->setTranslatable($product);
        $variant = new ProductVariant();
        $product->addVariant($variant);
        $pricing = new ChannelPricing();
        $pricing->setProductVariant($variant);

        $this->flush(updates: [$translation, $variant, $pricing, $product]);

        $this->assertEquals([new SyncProductMessage(5, SyncProductMessage::OP_UPSERT)], $this->sent());
    }

    public function testAChangedProductCollectionUpsertsItsOwner(): void
    {
        $product = $this->product(9);
        $collection = (new \ReflectionClass(PersistentCollection::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(PersistentCollection::class, 'owner'))->setValue($collection, $product);

        $this->flush(collections: [$collection]);

        $this->assertEquals([new SyncProductMessage(9, SyncProductMessage::OP_UPSERT)], $this->sent());
    }

    public function testARemovedProductIsDeletedWithTheIdItHadBeforeTheFlush(): void
    {
        $product = $this->product(3);
        $variant = new ProductVariant();
        $product->addVariant($variant);

        $this->flush(deletions: [$product, $variant], afterFlush: fn () => $this->setId($product, null));

        $this->assertEquals([new SyncProductMessage(3, SyncProductMessage::OP_DELETE)], $this->sent());
    }

    public function testARemovedVariantUpsertsItsProduct(): void
    {
        $product = $this->product(4);
        $variant = new ProductVariant();
        $product->addVariant($variant);

        $this->flush(deletions: [$variant]);

        $this->assertEquals([new SyncProductMessage(4, SyncProductMessage::OP_UPSERT)], $this->sent());
    }

    public function testUnrelatedChangesDispatchNothing(): void
    {
        $this->flush(insertions: [new Taxon()], updates: [new ChannelPricing()]);

        $this->assertSame([], $this->sent());
    }

    public function testAFailedSynchronousSyncIsLoggedInsteadOfBreakingTheFlush(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 503]);
        });
        $logger = new BufferingLogger();
        $listener = new ProductLifecycleListener($this->synchronousBus($http), $logger);
        $product = $this->product(11);

        $listener->onFlush($this->args(updates: [$product]));
        $listener->postFlush();
        $listener->dispatchPending();

        $this->assertSame(3, $calls);
        $errors = array_values(array_filter(
            $logger->cleanLogs(),
            static fn (array $log): bool => LogLevel::ERROR === $log[0],
        ));
        $this->assertCount(1, $errors);
        $this->assertSame(11, $errors[0][2]['product_id'] ?? null);
        $this->assertSame(SyncProductMessage::OP_UPSERT, $errors[0][2]['operation'] ?? null);
    }

    public function testEveryProductIsDispatchedEvenWhenOneFails(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            static function (SyncProductMessage $message): Envelope {
                if (1 === $message->productId) {
                    throw new HandlerFailedException(new Envelope($message), [new \RuntimeException('HTTP 503')]);
                }

                return new Envelope($message);
            },
        );
        $listener = new ProductLifecycleListener($bus, new NullLogger());

        $listener->onFlush($this->args(updates: [$this->product(1), $this->product(2)]));
        $listener->postFlush();
        $listener->dispatchPending();
    }

    public function testRepeatedChangesOfAProductInOneRequestAreSentOnceAtItsEnd(): void
    {
        $product = $this->product(5);

        $this->flush(updates: [$product]);
        $this->flush(updates: [$product]);

        $this->assertSame([], $this->dispatched);
        $this->assertEquals([new SyncProductMessage(5, SyncProductMessage::OP_UPSERT)], $this->sent());
        $this->assertEquals([new SyncProductMessage(5, SyncProductMessage::OP_UPSERT)], $this->sent());
    }

    public function testADeletionLaterInTheRequestReplacesTheUpsert(): void
    {
        $product = $this->product(3);

        $this->flush(updates: [$product]);
        $this->flush(deletions: [$product]);

        $this->assertEquals([new SyncProductMessage(3, SyncProductMessage::OP_DELETE)], $this->sent());
    }

    public function testChangesOfAFailedFlushAreNotDispatchedByTheNextOne(): void
    {
        $this->listener->onFlush($this->args(deletions: [$this->product(3)]));

        $this->flush();

        $this->assertSame([], $this->sent());
    }

    /**
     * @return list<object>
     */
    private function sent(): array
    {
        $this->listener->dispatchPending();

        return $this->dispatched;
    }

    /**
     * @param list<object> $insertions
     * @param list<object> $updates
     * @param list<object> $deletions
     * @param list<PersistentCollection<array-key, object>> $collections
     */
    private function flush(
        array $insertions = [],
        array $updates = [],
        array $deletions = [],
        array $collections = [],
        ?\Closure $afterFlush = null,
    ): void {
        $this->listener->onFlush($this->args($insertions, $updates, $deletions, $collections));
        if (null !== $afterFlush) {
            $afterFlush();
        }
        $this->listener->postFlush();
    }

    /**
     * @param list<object> $insertions
     * @param list<object> $updates
     * @param list<object> $deletions
     * @param list<PersistentCollection<array-key, object>> $collections
     */
    private function args(
        array $insertions = [],
        array $updates = [],
        array $deletions = [],
        array $collections = [],
    ): OnFlushEventArgs {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn($insertions);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn($updates);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn($deletions);
        $unitOfWork->method('getScheduledCollectionUpdates')->willReturn($collections);
        $unitOfWork->method('getScheduledCollectionDeletions')->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return new OnFlushEventArgs($entityManager);
    }

    private function synchronousBus(MockHttpClient $http): MessageBusInterface
    {
        $channel = new Channel();
        $channel->setCode('WEB');
        $channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepository->method('findBy')->willReturn([$channel]);

        $setting = new Agent001Setting('WEB');
        $setting->assignAgent('0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f', 'token', true, 'https://shop.test');
        $setting->setSetupCompleted(true);
        $settingRepository = $this->createMock(Agent001SettingRepository::class);
        $settingRepository->method('findWithAgent')->willReturn([$setting]);

        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->method('apiKey')->willReturn('api-key');

        $productReader = $this->createMock(ProductReaderInterface::class);
        $productReader->method('readOne')->willReturn(['id' => 11]);

        $handler = new SyncProductMessageHandler(
            $connectionRepository,
            $settingRepository,
            new StoreChannels($channelRepository),
            $productReader,
            new Agent001Client($http, 'https://backend.test', null, new NullLogger(), 0),
            new NullLogger(),
        );

        return new MessageBus([
            new SendMessageMiddleware(new SendersLocator([], new ServiceLocator([]))),
            new HandleMessageMiddleware(new HandlersLocator([SyncProductMessage::class => [$handler]])),
        ]);
    }

    private function product(int $id): Product
    {
        $product = new Product();
        $this->setId($product, $id);

        return $product;
    }

    private function setId(Product $product, ?int $id): void
    {
        (new \ReflectionProperty($product, 'id'))->setValue($product, $id);
    }
}
