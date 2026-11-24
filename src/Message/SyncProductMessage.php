<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Message;

final class SyncProductMessage
{
    public const OP_UPSERT = 'upsert';

    public const OP_DELETE = 'delete';

    public function __construct(
        public readonly int $productId,
        public readonly string $operation = self::OP_UPSERT,
    ) {
    }
}
