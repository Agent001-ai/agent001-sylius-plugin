<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Message;

final class SyncCatalogMessage
{
    public function __construct(
        public readonly string $channelCode,
    ) {
    }
}
