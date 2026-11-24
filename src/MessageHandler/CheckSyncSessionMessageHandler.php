<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\MessageHandler;

use Agent001\SyliusAiAssistantPlugin\DependencyInjection\Agent001SyliusAiAssistantExtension;
use Agent001\SyliusAiAssistantPlugin\Message\CheckSyncSessionMessage;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Sync\IndexingWatcher;
use Agent001\SyliusAiAssistantPlugin\Sync\Synchronizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: Agent001SyliusAiAssistantExtension::COMMAND_BUS)]
final class CheckSyncSessionMessageHandler
{
    public function __construct(
        private readonly Agent001SettingRepository $settingRepository,
        private readonly Synchronizer $synchronizer,
        private readonly IndexingWatcher $indexingWatcher,
    ) {
    }

    public function __invoke(CheckSyncSessionMessage $message): void
    {
        $setting = $this->settingRepository->findOneByChannelCode($message->channelCode);
        if (null === $setting || $message->sessionId !== $setting->getSyncSessionId()) {
            return;
        }

        $this->synchronizer->refresh($setting);
        $this->indexingWatcher->watch($setting);
    }
}
