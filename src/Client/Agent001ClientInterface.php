<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Client;

interface Agent001ClientInterface
{
    public const MAX_BATCH_BYTES = 8388608;

    public const BATCH_TOO_LARGE = 'batch_too_large';

    public function getBaseUrl(): string;

    public function getConnectUrl(string $siteUrl, string $returnToUrl, string $state, string $channel): string;

    public function getCreateAgentUrl(string $siteUrl, string $channel): string;

    /**
     * @return array{api_key: string, account_name: string}|null
     */
    public function exchangeConnectCode(string $code): ?array;

    /**
     * @return array{
     *     agents: list<array{id: string, name: string, embed_token: string, active: bool, channels: list<string>}>,
     *     selected_ids: list<string>,
     *     account_name: string,
     * }|null
     */
    public function fetchAgents(string $apiKey, string $channel): ?array;

    /**
     * @param list<string> $hosts
     */
    public function putStoreHosts(string $apiKey, array $hosts): bool;

    public function openCatalog(string $apiKey, string $channel): bool;

    public function deleteCatalog(string $apiKey, string $channel): int;

    public function disconnect(string $apiKey): int;

    /**
     * @param list<string> $agentIds
     *
     * @return array{
     *     success: bool,
     *     agents?: list<array{id: string, name: string, embed_token: string, active: bool, channels: list<string>}>,
     *     selected_ids?: list<string>,
     *     account_name?: string,
     *     error?: string,
     * }
     */
    public function updateSelectedAgents(string $apiKey, string $channel, array $agentIds): array;

    public function allowDomain(string $apiKey, string $channel, string $agentId, string $domain): ?string;

    public function setAgentActive(string $apiKey, string $channel, string $agentId, bool $active): ?string;

    /**
     * @return array{status: int, data: array<string, mixed>}
     */
    public function fetchSyncSession(string $apiKey, string $channel, string $sessionId): array;

    /**
     * @param list<array<string, mixed>> $batch
     * @param list<array<string, string>> $storeContext
     *
     * @return array{success: bool, error: string}
     */
    public function postProductBatch(
        string $apiKey,
        string $channel,
        string $siteUrl,
        string $sessionId,
        int $batchIndex,
        bool $isLast,
        array $batch,
        array $storeContext = [],
    ): array;

    /**
     * @param array<string, mixed> $productData
     */
    public function upsertProduct(
        string $apiKey,
        string $channel,
        string $siteUrl,
        int $productId,
        array $productData,
    ): int;

    public function deleteProduct(string $apiKey, string $channel, int $productId): int;
}
