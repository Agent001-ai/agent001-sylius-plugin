<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Sync;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Message\CheckSyncSessionMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

final class IndexingWatcher
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly SendersLocatorInterface $sendersLocator,
    ) {
    }

    public function watch(Agent001Setting $setting): void
    {
        $sessionId = (string) $setting->getSyncSessionId();
        if (Agent001Setting::STATUS_INDEXING !== $setting->getSyncStatus() || empty($sessionId)) {
            return;
        }

        $envelope = new Envelope(
            new CheckSyncSessionMessage($setting->getChannelCode(), $sessionId),
            [new DelayStamp(Synchronizer::CHECK_INTERVAL_SECONDS * 1000)],
        );
        if ($this->isHandledAsynchronously($envelope)) {
            $this->messageBus->dispatch($envelope);
        }
    }

    private function isHandledAsynchronously(Envelope $envelope): bool
    {
        foreach ($this->sendersLocator->getSenders($envelope) as $sender) {
            if (!$sender instanceof SyncTransport) {
                return true;
            }
        }

        return false;
    }
}
