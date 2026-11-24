<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Connect;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use Agent001\SyliusAiAssistantPlugin\Client\BackendUrl;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Connection;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Exception\Agent001Exception;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Settings\AgentList;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

final class ConnectionManager
{
    private const NONCE_TTL_SECONDS = 900;

    private const NONCE_PREFIX = 'agent001_sylius_ai_assistant_connect_';

    public function __construct(
        private readonly Agent001ClientInterface $client,
        private readonly Agent001ConnectionRepository $connectionRepository,
        private readonly Agent001SettingRepository $settingRepository,
        private readonly StoreChannels $channels,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheItemPoolInterface $cache,
        private readonly AgentList $agentList,
    ) {
    }

    public function start(string $site, string $callbackPath, string $settingsUrl, string $initiator): string
    {
        $firstChannel = array_key_first($this->channels->enabled());
        if (null === $firstChannel) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.connect.no_channel');
        }
        if (!BackendUrl::isAllowed($this->client->getBaseUrl())) {
            throw new Agent001Exception('agent001_sylius_ai_assistant.connect.insecure_base_url');
        }

        $connection = $this->connection();
        $revision = $connection->rotateConnectRevision();
        $this->entityManager->flush();

        $token = bin2hex(random_bytes(32));
        $item = $this->cache->getItem(self::NONCE_PREFIX . hash('sha256', $token));
        $item->set([
            'site' => $site,
            'settings_url' => $settingsUrl,
            'revision' => $revision,
            'initiator' => $this->fingerprint($initiator),
        ]);
        $item->expiresAfter(self::NONCE_TTL_SECONDS);
        $this->cache->save($item);

        return $this->client->getConnectUrl(
            $site,
            $site . $callbackPath,
            $token,
            Agent001Setting::CATALOG_PREFIX . $firstChannel,
        );
    }

    /**
     * @return array{site: string, settings_url: string, revision: string}|null
     */
    public function consumeState(string $token, string $initiator): ?array
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return null;
        }

        $key = self::NONCE_PREFIX . hash('sha256', $token);
        $item = $this->cache->getItem($key);
        $state = $item->isHit() ? $item->get() : null;

        if (
            !is_array($state) ||
            !is_string($state['site'] ?? null) ||
            !is_string($state['settings_url'] ?? null) ||
            !is_string($state['revision'] ?? null)
        ) {
            return null;
        }

        $initiatorFingerprint = $state['initiator'] ?? null;
        if (!is_string($initiatorFingerprint) || !hash_equals($initiatorFingerprint, $this->fingerprint($initiator))) {
            return null;
        }

        $this->cache->deleteItem($key);

        return ['site' => $state['site'], 'settings_url' => $state['settings_url'], 'revision' => $state['revision']];
    }

    /**
     * @param array{site: string, settings_url: string, revision: string} $state
     */
    public function complete(array $state, string $code): bool
    {
        $connection = $this->connection();
        if (!$this->connectionRepository->claimConnectRevision($connection, $state['revision'])) {
            return false;
        }

        $result = $this->client->exchangeConnectCode($code);
        if (null === $result) {
            return false;
        }

        $connection->connect($result['api_key'], $result['account_name'], $state['site']);
        $this->forgetUnconfirmedAgents($result['api_key']);
        $this->entityManager->flush();
        $this->agentList->forget();

        return true;
    }

    public function disconnect(): void
    {
        $connection = $this->connection();
        $apiKey = (string) $connection->getApiKey();
        if ('' !== $apiKey) {
            $status = $this->client->disconnect($apiKey);
            if (($status < 200 || $status >= 300) && 401 !== $status && 404 !== $status) {
                throw new Agent001Exception('agent001_sylius_ai_assistant.disconnect.failed');
            }
        }

        foreach ($this->settingRepository->findAll() as $setting) {
            $this->entityManager->remove($setting);
        }
        $connection->disconnect();
        $this->entityManager->flush();
        $this->agentList->forget();
    }

    private function connection(): Agent001Connection
    {
        $connection = $this->connectionRepository->findCurrent();
        if (null === $connection) {
            $connection = new Agent001Connection();
            $this->entityManager->persist($connection);
        }

        return $connection;
    }

    private function fingerprint(string $initiator): string
    {
        return hash('sha256', $initiator);
    }

    private function forgetUnconfirmedAgents(string $apiKey): void
    {
        $settings = $this->settingRepository->findAll();
        if ([] === $settings) {
            return;
        }

        $agents = $this->client->fetchAgents($apiKey, $settings[0]->getCatalogChannel())['agents'] ?? [];
        foreach ($settings as $setting) {
            foreach ($agents as $agent) {
                $servesChannel = in_array($setting->getCatalogChannel(), $agent['channels'], true);
                if ($agent['id'] === $setting->getAgentId() && $servesChannel) {
                    continue 2;
                }
            }
            $this->entityManager->remove($setting);
        }
    }
}
