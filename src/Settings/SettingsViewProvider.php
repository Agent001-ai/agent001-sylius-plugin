<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Settings;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Connection;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Reader\StoreContextReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Sync\Synchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SettingsViewProvider
{
    public function __construct(
        private readonly Agent001ConnectionRepository $connectionRepository,
        private readonly Agent001SettingRepository $settingRepository,
        private readonly StoreChannels $channels,
        private readonly StoreContextReaderInterface $storeContextReader,
        private readonly Synchronizer $synchronizer,
        private readonly Agent001ClientInterface $client,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly AgentList $agentList,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        $connection = $this->connectionRepository->findOneBy([], ['id' => 'ASC']);
        $channels = $this->channels->enabled();
        $settings = $this->refreshedSettings();
        $connected = null !== $connection && $connection->isConnected();

        $agents = null;
        if ($connected && [] !== $channels) {
            $agents = $this->agentList->fetch(
                (string) $connection->getApiKey(),
                Agent001Setting::CATALOG_PREFIX . array_key_first($channels),
            );
            if (null !== $agents) {
                $this->applyAgents($agents, $settings, $connection);
            }
        }

        $taxons = [];
        $createAgentUrls = [];
        foreach ($channels as $code => $channel) {
            $catalog = Agent001Setting::CATALOG_PREFIX . $code;
            $createAgentUrls[$code] = $this->client->getCreateAgentUrl((string) $connection?->getSite(), $catalog);
            if (isset($settings[$code]) && $settings[$code]->hasAgent()) {
                $taxons[$code] = $this->storeContextReader->taxons($channel);
            }
        }

        return [
            'connection' => $connection,
            'channels' => $channels,
            'settings' => $settings,
            'agents' => $agents['agents'] ?? [],
            'agents_failed' => $connected && null === $agents,
            'owners' => $this->owners($agents['agents'] ?? [], $settings, array_keys($channels)),
            'taxons' => $taxons,
            'create_agent_urls' => $createAgentUrls,
        ];
    }

    /**
     * @return array<string, array{status: ?string, message: string, count: int, running: bool}>
     */
    public function progress(): array
    {
        $progress = [];
        foreach ($this->refreshedSettings() as $code => $setting) {
            $progress[$code] = [
                'status' => $setting->getSyncStatus(),
                'message' => $this->translator->trans(
                    (string) $setting->getSyncMessage(),
                    $setting->getSyncMessageParameters(),
                ),
                'count' => $setting->getSyncCount(),
                'running' => $setting->isSyncRunning(),
            ];
        }

        return $progress;
    }

    /**
     * @return array<string, Agent001Setting>
     */
    private function refreshedSettings(): array
    {
        $settings = $this->settingRepository->findAllIndexedByChannelCode();
        foreach ($settings as $setting) {
            $this->synchronizer->refresh($setting);
        }

        return $settings;
    }

    /**
     * @param array{
     *     agents: list<array{id: string, name: string, embed_token: string, active: bool, channels: list<string>}>,
     *     account_name: string,
     * } $agents
     * @param array<string, Agent001Setting> $settings
     */
    private function applyAgents(array $agents, array $settings, Agent001Connection $connection): void
    {
        foreach ($settings as $setting) {
            foreach ($agents['agents'] as $agent) {
                if ($agent['id'] === $setting->getAgentId() && '' !== $agent['embed_token']) {
                    $setting->setAgentActive($agent['active']);
                }
            }
        }
        if ('' !== $agents['account_name']) {
            $connection->setAccountName($agents['account_name']);
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<array{id: string, name: string, embed_token: string, active: bool, channels: list<string>}> $agents
     * @param array<string, Agent001Setting> $settings
     * @param list<string> $channelCodes
     *
     * @return array<string, string>
     */
    private function owners(array $agents, array $settings, array $channelCodes): array
    {
        $owners = [];
        foreach ($settings as $code => $setting) {
            if (null !== $setting->getAgentId()) {
                $owners[$setting->getAgentId()] = $code;
            }
        }
        foreach ($agents as $agent) {
            foreach ($channelCodes as $code) {
                $catalog = Agent001Setting::CATALOG_PREFIX . $code;
                if (!isset($owners[$agent['id']]) && in_array($catalog, $agent['channels'], true)) {
                    $owners[$agent['id']] = $code;
                }
            }
        }

        return $owners;
    }
}
