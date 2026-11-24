<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\MessageHandler;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use Agent001\SyliusAiAssistantPlugin\DependencyInjection\Agent001SyliusAiAssistantExtension;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Message\SyncProductMessage;
use Agent001\SyliusAiAssistantPlugin\Reader\ProductReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: Agent001SyliusAiAssistantExtension::COMMAND_BUS)]
final class SyncProductMessageHandler
{
    public function __construct(
        private readonly Agent001ConnectionRepository $connectionRepository,
        private readonly Agent001SettingRepository $settingRepository,
        private readonly StoreChannels $channels,
        private readonly ProductReaderInterface $productReader,
        private readonly Agent001ClientInterface $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncProductMessage $message): void
    {
        $apiKey = $this->connectionRepository->apiKey();
        if ('' === $apiKey) {
            return;
        }

        $failures = [];
        foreach ($this->settingRepository->findWithAgent() as $setting) {
            if (!$setting->isSetupCompleted()) {
                continue;
            }

            try {
                $this->syncOne($apiKey, $setting, $message);
            } catch (\RuntimeException $e) {
                $failures[] = $e->getMessage();
            }
        }

        if ([] !== $failures) {
            throw new \RuntimeException(implode(' ', $failures));
        }
    }

    private function syncOne(string $apiKey, Agent001Setting $setting, SyncProductMessage $message): void
    {
        $channel = $this->channels->find($setting->getChannelCode());
        $site = (string) $setting->getSite();

        $data = null;
        if (SyncProductMessage::OP_DELETE !== $message->operation && null !== $channel && '' !== $site) {
            $data = $this->productReader->readOne($message->productId, $channel, $site, $setting->getTaxonCodes());
        }

        if (null === $data) {
            $status = $this->client->deleteProduct($apiKey, $setting->getCatalogChannel(), $message->productId);
            if (404 !== $status) {
                $this->guard($status, SyncProductMessage::OP_DELETE, $message->productId, $setting);
            }

            return;
        }

        $status = $this->client->upsertProduct(
            $apiKey,
            $setting->getCatalogChannel(),
            $site,
            $message->productId,
            $data,
        );
        $this->guard($status, SyncProductMessage::OP_UPSERT, $message->productId, $setting);
        $this->logger->info('Upserted product into Agent001', [
            'product_id' => $message->productId,
            'channel' => $setting->getChannelCode(),
        ]);
    }

    private function guard(int $status, string $operation, int $productId, Agent001Setting $setting): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        if ($status >= 400 && $status < 500 && 408 !== $status && 429 !== $status) {
            $this->logger->warning('Agent001 rejected a product change', [
                'product_id' => $productId,
                'channel' => $setting->getChannelCode(),
                'status' => $status,
            ]);

            return;
        }

        throw new \RuntimeException(sprintf(
            '%s failed for product %d in channel %s (HTTP %d).',
            $operation,
            $productId,
            $setting->getChannelCode(),
            $status,
        ));
    }
}
