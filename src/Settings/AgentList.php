<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Settings;

use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use Psr\Cache\CacheItemPoolInterface;

final class AgentList
{
    public const TTL_SECONDS = 300;

    private const CACHE_KEY = 'agent001_sylius_ai_assistant_agents';

    public function __construct(
        private readonly Agent001ClientInterface $client,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{
     *     agents: list<array{id: string, name: string, embed_token: string, active: bool, channels: list<string>}>,
     *     selected_ids: list<string>,
     *     account_name: string,
     * }|null
     */
    public function fetch(string $apiKey, string $channel): ?array
    {
        $scope = hash('sha256', $apiKey . '|' . $channel);
        $item = $this->cache->getItem(self::CACHE_KEY);
        $cached = $item->isHit() ? $item->get() : null;
        if (is_array($cached) && $scope === ($cached['scope'] ?? null) && is_array($cached['agents'] ?? null)) {
            /** @var array{
             *     agents: list<array{
             *         id: string,
             *         name: string,
             *         embed_token: string,
             *         active: bool,
             *         channels: list<string>,
             *     }>,
             *     selected_ids: list<string>,
             *     account_name: string,
             * } $agents
             */
            $agents = $cached['agents'];

            return $agents;
        }

        $agents = $this->client->fetchAgents($apiKey, $channel);
        if (null !== $agents && !empty($agents['agents'])) {
            $item->set(['scope' => $scope, 'agents' => $agents]);
            $item->expiresAfter(self::TTL_SECONDS);
            $this->cache->save($item);
        }

        return $agents;
    }

    public function forget(): void
    {
        $this->cache->deleteItem(self::CACHE_KEY);
    }
}
