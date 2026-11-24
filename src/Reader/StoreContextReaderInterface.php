<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Reader;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;

interface StoreContextReaderInterface
{
    /**
     * @return list<TaxonInterface>
     */
    public function taxons(ChannelInterface $channel): array;

    /**
     * @return list<array{type: string, id: string, title: string, content: string, url: string}>
     */
    public function read(ChannelInterface $channel, string $site): array;
}
