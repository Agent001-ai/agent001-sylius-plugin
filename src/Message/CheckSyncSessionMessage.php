<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Message;

final class CheckSyncSessionMessage
{
    public function __construct(
        public readonly string $channelCode,
        public readonly string $sessionId,
    ) {
    }
}
