<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\EventListener;

use Agent001\SyliusAiAssistantPlugin\Message\SyncProductMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductImageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Product\Model\ProductTranslationInterface;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'dispatchPending')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'dispatchPending')]
#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'dispatchPending')]
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'dispatchPending')]
final class ProductLifecycleListener
{
    /** @var \SplObjectStorage<ProductInterface, null> */
    private \SplObjectStorage $changed;

    /** @var array<int, true> */
    private array $deleted = [];

    /** @var array<int, SyncProductMessage> */
    private array $pending = [];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        $this->changed = new \SplObjectStorage();
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->changed = new \SplObjectStorage();
        $this->deleted = [];
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof ProductInterface && null !== $entity->getId()) {
                $this->deleted[(int) $entity->getId()] = true;

                continue;
            }
            $this->collect($entity);
        }

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $this->collect($entity);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $this->collect($entity);
        }

        foreach ($unitOfWork->getScheduledCollectionUpdates() as $collection) {
            $this->collect($collection->getOwner());
        }

        foreach ($unitOfWork->getScheduledCollectionDeletions() as $collection) {
            $this->collect($collection->getOwner());
        }
    }

    public function postFlush(): void
    {
        foreach (array_keys($this->deleted) as $productId) {
            $this->pending[$productId] = new SyncProductMessage($productId, SyncProductMessage::OP_DELETE);
        }
        foreach ($this->changed as $product) {
            $productId = (int) $product->getId();
            if ($productId > 0) {
                $this->pending[$productId] ??= new SyncProductMessage($productId, SyncProductMessage::OP_UPSERT);
            }
        }

        $this->changed = new \SplObjectStorage();
        $this->deleted = [];
    }

    public function dispatchPending(): void
    {
        $messages = $this->pending;
        $this->pending = [];

        foreach ($messages as $message) {
            try {
                $this->messageBus->dispatch($message);
            } catch (MessengerException $exception) {
                $this->logger->error('Agent001 product sync failed, the nightly sync resends it.', [
                    'product_id' => $message->productId,
                    'operation' => $message->operation,
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function collect(?object $entity): void
    {
        $product = $this->product($entity);
        if ($product instanceof ProductInterface) {
            $this->changed->attach($product);
        }
    }

    private function product(?object $entity): ?object
    {
        return match (true) {
            $entity instanceof ProductInterface => $entity,
            $entity instanceof ProductTranslationInterface,
            $entity instanceof ProductVariantTranslationInterface => $this->product($entity->getTranslatable()),
            $entity instanceof ProductVariantInterface => $entity->getProduct(),
            $entity instanceof ChannelPricingInterface => $entity->getProductVariant()?->getProduct(),
            $entity instanceof ProductImageInterface => $entity->getOwner(),
            $entity instanceof ProductTaxonInterface => $entity->getProduct(),
            default => null,
        };
    }
}
