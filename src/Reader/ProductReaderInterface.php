<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Reader;

use Sylius\Component\Core\Model\ChannelInterface;

interface ProductReaderInterface
{
    /**
     * @param list<string> $taxonCodes
     */
    public function count(ChannelInterface $channel, array $taxonCodes = []): int;

    /**
     * @param list<string> $taxonCodes
     *
     * @return list<array<string, mixed>>
     */
    public function readBatchAfterId(
        int $afterId,
        int $limit,
        ChannelInterface $channel,
        string $site,
        array $taxonCodes = [],
    ): array;

    /**
     * @param list<string> $taxonCodes
     *
     * @return array<string, mixed>|null
     */
    public function readOne(int $id, ChannelInterface $channel, string $site, array $taxonCodes = []): ?array;
}
