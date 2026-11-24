<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Channel;

use Agent001\SyliusAiAssistantPlugin\Agent\AgentId;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Exception\Agent001Exception;
use Agent001\SyliusAiAssistantPlugin\Message\SyncCatalogMessage;
use Agent001\SyliusAiAssistantPlugin\Reader\StoreContextReaderInterface;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Settings\AgentList;
use Agent001\SyliusAiAssistantPlugin\Sync\Synchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChannelConfigurator
{
    private const LEGACY_CATALOG = 'default';

    public function __construct(
        private readonly Agent001ClientInterface $client,
        private readonly Agent001ConnectionRepository $connectionRepository,
        private readonly Agent001SettingRepository $settingRepository,
        private readonly StoreChannels $channels,
        private readonly StoreContextReaderInterface $storeContextReader,
        private readonly Synchronizer $synchronizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly AgentList $agentList,
    ) {
    }

    /**
     * @param array<mixed> $picked
     */
    public function assignAgents(array $picked, Request $request): void
    {
        $apiKey = $this->apiKey();
        $settings = $this->settingRepository->findAllIndexedByChannelCode();

        $choices = [];
        foreach ($this->channels->enabled() as $code => $channel) {
            $value = $picked[$code] ?? (string) ($settings[$code] ?? null)?->getAgentId();
            $id = AgentId::normalize($value);
            if (!is_string($value) || ('' !== $value && '' === $id)) {
                throw new Agent001Exception('agent001_sylius_ai_assistant.settings.invalid_agent');
            }
            $choices[$code] = $id;
        }

        $chosen = array_filter($choices);
        if ([] === $chosen) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.settings.agent_required');
        }
        if (count(array_unique($chosen)) !== count($chosen)) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.settings.agent_per_channel');
        }

        $changed = array_filter(
            $choices,
            static fn (string $id, string $code): bool => $id !== (string) ($settings[$code] ?? null)?->getAgentId(),
            \ARRAY_FILTER_USE_BOTH,
        );
        foreach (array_keys($changed) as $code) {
            if (isset($settings[$code]) && $this->synchronizer->isReading($settings[$code])) {
                throw new Agent001Exception('agent001_sylius_ai_assistant.settings.sync_running');
            }
        }

        uasort($changed, static fn (string $a, string $b): int => ('' !== $a) <=> ('' !== $b));

        if ([] === $settings) {
            $this->deleteCatalog($apiKey, self::LEGACY_CATALOG);
        }

        $hostsSent = false;
        $queued = [];

        try {
            foreach ($changed as $code => $id) {
                $setting = $settings[$code] ?? null;
                if ('' === $id) {
                    if (null !== $setting) {
                        $this->deleteCatalog($apiKey, $setting->getCatalogChannel());
                        $this->entityManager->remove($setting);
                        $this->entityManager->flush();
                    }

                    continue;
                }

                if (!$hostsSent) {
                    $this->client->putStoreHosts($apiKey, $this->channels->hosts());
                    $hostsSent = true;
                }

                $setting ??= new Agent001Setting($code);
                $site = $this->channels->site($this->channels->enabled()[$code], $request);
                $agent = $this->selectAgent($apiKey, $setting->getCatalogChannel(), $id, $site);

                $setting->assignAgent($agent['id'], $agent['embed_token'], $agent['active'], $site);
                $setting->queueSync();
                $this->entityManager->persist($setting);
                $this->entityManager->flush();
                $queued[] = $code;
            }
        } finally {
            $this->agentList->forget();
            foreach ($queued as $code) {
                $this->messageBus->dispatch(new SyncCatalogMessage($code));
            }
        }
    }

    public function toggle(string $channelCode): bool
    {
        $setting = $this->setting($channelCode);
        $active = !$setting->isAgentActive();
        $error = $this->client->setAgentActive(
            $this->apiKey(),
            $setting->getCatalogChannel(),
            (string) $setting->getAgentId(),
            $active,
        );
        if (null !== $error) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.settings.active_failed');
        }

        $setting->setAgentActive($active);
        $this->entityManager->flush();
        $this->agentList->forget();

        return $active;
    }

    /**
     * @param array<mixed> $taxonCodes
     */
    public function synchronize(string $channelCode, array $taxonCodes): void
    {
        $this->apiKey();
        $setting = $this->setting($channelCode);
        $channel = $this->channels->find($channelCode)
            ?? throw new Agent001Exception('agent001_sylius_ai_assistant.settings.invalid_channel');

        $available = [];
        foreach ($this->storeContextReader->taxons($channel) as $taxon) {
            $available[(string) $taxon->getCode()] = true;
        }

        $selected = [];
        foreach ($taxonCodes as $code) {
            if (!is_string($code) || !isset($available[$code])) {
                throw new Agent001Exception('agent001_sylius_ai_assistant.settings.invalid_taxons');
            }
            $selected[$code] = $code;
        }
        if ([] !== $available && [] === $selected) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.settings.taxons_required');
        }

        $selected = count($selected) === count($available) ? [] : array_values($selected);
        $changed = $selected !== $setting->getTaxonCodes();
        if ($this->synchronizer->isReading($setting) || (!$changed && $setting->isSyncRunning())) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.settings.sync_running');
        }

        $setting->setTaxonCodes($selected);
        if ($changed) {
            $setting->setSetupCompleted(false);
        }
        $setting->queueSync();
        $this->entityManager->flush();
        $this->messageBus->dispatch(new SyncCatalogMessage($channelCode));
    }

    /**
     * @return array{id: string, embed_token: string, active: bool}
     */
    private function selectAgent(string $apiKey, string $catalog, string $id, string $site): array
    {
        if (!$this->client->openCatalog($apiKey, $catalog)) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.settings.remote_agent_update_failed');
        }

        $result = $this->client->updateSelectedAgents($apiKey, $catalog, [$id]);
        if (!$result['success']) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.settings.remote_agent_update_failed');
        }

        foreach ($result['agents'] ?? [] as $agent) {
            $selected = in_array($id, $result['selected_ids'] ?? [], true);
            if ($agent['id'] === $id && '' !== $agent['embed_token'] && $selected) {
                $host = parse_url($site, \PHP_URL_HOST);
                if (!is_string($host) || '' === $host) {
                    throw new Agent001Exception('agent001_sylius_ai_assistant.settings.domain_unknown');
                }
                if (null !== $this->client->allowDomain($apiKey, $catalog, $id, $host)) {
                    throw new Agent001Exception('agent001_sylius_ai_assistant.settings.domain_failed');
                }

                return $agent;
            }
        }

        throw new Agent001Exception('agent001_sylius_ai_assistant.settings.agent_not_confirmed');
    }

    private function deleteCatalog(string $apiKey, string $catalog): void
    {
        $status = $this->client->deleteCatalog($apiKey, $catalog);
        if (($status < 200 || $status >= 300) && 404 !== $status) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.settings.remote_agent_update_failed');
        }
    }

    private function setting(string $channelCode): Agent001Setting
    {
        $setting = $this->settingRepository->findOneByChannelCode($channelCode);
        if (null === $setting || !$setting->hasAgent()) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.settings.no_agent');
        }

        return $setting;
    }

    private function apiKey(): string
    {
        $apiKey = $this->connectionRepository->apiKey();
        if ('' === $apiKey) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.settings.not_connected');
        }

        return $apiKey;
    }
}
