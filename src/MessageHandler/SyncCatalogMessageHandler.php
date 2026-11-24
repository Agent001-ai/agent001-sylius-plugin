<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\MessageHandler;

use Agent001\SyliusAiAssistantPlugin\DependencyInjection\Agent001SyliusAiAssistantExtension;
use Agent001\SyliusAiAssistantPlugin\Message\SyncCatalogMessage;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Sync\IndexingWatcher;
use Agent001\SyliusAiAssistantPlugin\Sync\Synchronizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: Agent001SyliusAiAssistantExtension::COMMAND_BUS)]
final class SyncCatalogMessageHandler
{
    public function __construct(
        private readonly Synchronizer $synchronizer,
        private readonly Agent001SettingRepository $settingRepository,
        private readonly IndexingWatcher $indexingWatcher,
    ) {
    }

    public function __invoke(SyncCatalogMessage $message): void
    {
        if (!$this->synchronizer->sync($message->channelCode)['success']) {
            return;
        }

        $setting = $this->settingRepository->findOneByChannelCode($message->channelCode);
        if (null !== $setting) {
            $this->indexingWatcher->watch($setting);
        }
    }
}
