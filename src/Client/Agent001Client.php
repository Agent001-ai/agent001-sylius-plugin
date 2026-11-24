<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Client;

use Agent001\SyliusAiAssistantPlugin\Agent\AgentId;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Agent001Client implements Agent001ClientInterface
{
    public const VERSION = '1.0.0';

    public const PLATFORM = 'sylius';

    private const MAX_ATTEMPTS = 3;

    private const BASE_DELAY_MS = 500;

    private const TIMEOUT_SECONDS = 30;

    private const ADMIN_TIMEOUT_SECONDS = 10;

    private const JSON_FLAGS = \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_AMP | \JSON_HEX_QUOT
        | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly ?string $apiBaseUrl,
        private readonly LoggerInterface $logger,
        private readonly int $baseDelayMs = self::BASE_DELAY_MS,
    ) {
    }

    public function getBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function getConnectUrl(string $siteUrl, string $returnToUrl, string $state, string $channel): string
    {
        return $this->getBaseUrl() . '/connect?' . http_build_query([
            'site' => $siteUrl,
            'return_to' => $returnToUrl,
            'state' => $state,
            'channel' => $channel,
            'platform' => self::PLATFORM,
        ]);
    }

    public function getCreateAgentUrl(string $siteUrl, string $channel): string
    {
        $query = http_build_query(['site' => $siteUrl, 'channel' => $channel]);

        return $this->getBaseUrl() . '/connect/store-agent?' . $query;
    }

    /**
     * @return array{api_key: string, account_name: string}|null
     */
    public function exchangeConnectCode(string $code): ?array
    {
        $response = $this->request(
            'POST',
            '/connect/exchange',
            json: ['code' => $code],
            attempts: 1,
            timeout: self::ADMIN_TIMEOUT_SECONDS,
        );
        if (!$this->isSuccess($response['status'])) {
            $this->logger->error('Agent001 exchange code failed', ['status' => $response['status']]);

            return null;
        }

        $data = $this->decode($response['body']);
        $apiKey = $data['api_key'] ?? null;
        if (!is_string($apiKey) || '' === trim($apiKey) || 1 === preg_match('/[\r\n]/', $apiKey)) {
            return null;
        }

        return [
            'api_key' => $apiKey,
            'account_name' => is_string($data['account_name'] ?? null) ? trim($data['account_name']) : '',
        ];
    }

    /**
     * @return array{
     *     agents: list<array{id: string, name: string, embed_token: string, active: bool, channels: list<string>}>,
     *     selected_ids: list<string>,
     *     account_name: string,
     * }|null
     */
    public function fetchAgents(string $apiKey, string $channel): ?array
    {
        $response = $this->request('GET', '/api/v1/integrations/me/agents', $apiKey, $channel, null, [], 1, 5);
        if (!$this->isSuccess($response['status'])) {
            return null;
        }

        return $this->normalizeAgents($this->decode($response['body']));
    }

    /**
     * @param list<string> $hosts
     */
    public function putStoreHosts(string $apiKey, array $hosts): bool
    {
        $response = $this->request(
            'PUT',
            '/api/v1/integrations/me/store-hosts',
            $apiKey,
            json: ['hosts' => array_values($hosts)],
            attempts: 1,
            timeout: self::ADMIN_TIMEOUT_SECONDS,
        );

        return $this->isSuccess($response['status']);
    }

    public function openCatalog(string $apiKey, string $channel): bool
    {
        $response = $this->request(
            'PUT',
            '/api/v1/integrations/me/catalogs/' . rawurlencode($channel),
            $apiKey,
            attempts: 1,
            timeout: self::ADMIN_TIMEOUT_SECONDS,
        );

        return $this->isSuccess($response['status']);
    }

    public function deleteCatalog(string $apiKey, string $channel): int
    {
        return $this->request(
            'DELETE',
            '/api/v1/integrations/me/catalogs/' . rawurlencode($channel),
            $apiKey,
            attempts: 1,
            timeout: self::ADMIN_TIMEOUT_SECONDS,
        )['status'];
    }

    public function disconnect(string $apiKey): int
    {
        return $this->request(
            'DELETE',
            '/api/v1/integrations/me',
            $apiKey,
            attempts: 1,
            timeout: self::ADMIN_TIMEOUT_SECONDS,
        )['status'];
    }

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
    public function updateSelectedAgents(string $apiKey, string $channel, array $agentIds): array
    {
        $response = $this->request(
            'PUT',
            '/api/v1/integrations/me/agents',
            $apiKey,
            $channel,
            ['agent_ids' => AgentId::normalizeList($agentIds)],
            attempts: 1,
            timeout: self::ADMIN_TIMEOUT_SECONDS,
        );
        if (!$this->isSuccess($response['status'])) {
            return ['success' => false, 'error' => $response['error']];
        }

        $data = $this->decode($response['body']);
        if (!isset($data['agents']) || !is_array($data['agents'])) {
            return ['success' => false, 'error' => 'Invalid response.'];
        }

        return ['success' => true] + $this->normalizeAgents($data);
    }

    public function allowDomain(string $apiKey, string $channel, string $agentId, string $domain): ?string
    {
        $response = $this->request(
            'POST',
            sprintf('/api/v1/integrations/me/agents/%s/domain', rawurlencode(AgentId::normalize($agentId))),
            $apiKey,
            $channel,
            ['domain' => $domain],
            [],
            1,
            self::ADMIN_TIMEOUT_SECONDS,
        );

        if ($this->isSuccess($response['status'])) {
            return null;
        }

        return $response['error'] ?: sprintf('HTTP %d', $response['status']);
    }

    public function setAgentActive(string $apiKey, string $channel, string $agentId, bool $active): ?string
    {
        $agentId = AgentId::normalize($agentId);
        $response = $this->request(
            'POST',
            sprintf('/api/v1/integrations/me/agents/%s/active', rawurlencode($agentId)),
            $apiKey,
            $channel,
            ['active' => $active],
            [],
            1,
            self::ADMIN_TIMEOUT_SECONDS,
        );
        if (!$this->isSuccess($response['status'])) {
            return $response['error'] ?: sprintf('HTTP %d', $response['status']);
        }

        $data = $this->decode($response['body']);
        if (AgentId::normalize($data['agent_id'] ?? null) !== $agentId || ($data['active'] ?? null) !== $active) {
            return 'The backend did not confirm the agent status update.';
        }

        return null;
    }

    /**
     * @return array{status: int, data: array<string, mixed>}
     */
    public function fetchSyncSession(string $apiKey, string $channel, string $sessionId): array
    {
        $response = $this->request('GET', '/api/v1/ingest/sessions/' . rawurlencode($sessionId), $apiKey, $channel);

        return ['status' => $response['status'], 'data' => $this->decode($response['body'])];
    }

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
    ): array {
        $payload = [
            'site' => $siteUrl,
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            'version' => self::VERSION,
            'session_id' => $sessionId,
            'batch_index' => $batchIndex,
            'is_first' => 0 === $batchIndex,
            'is_last' => $isLast,
            'products' => $batch,
            'store_context' => $storeContext,
        ];

        try {
            $body = json_encode($payload, self::JSON_FLAGS);
        } catch (\JsonException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        if (strlen($body) > self::MAX_BATCH_BYTES) {
            return ['success' => false, 'error' => self::BATCH_TOO_LARGE];
        }

        $response = $this->request('POST', '/api/v1/ingest', $apiKey, $channel, headers: [
            'Content-Type' => 'application/json',
            'X-Agent001-Batch' => sprintf('%d/%s', $batchIndex, $isLast ? 'final' : 'partial'),
            'X-Agent001-Session' => $sessionId,
        ], body: $body);

        if (!$this->isSuccess($response['status'])) {
            $this->logger->error('Agent001 post batch failed', [
                'channel' => $channel,
                'batch_index' => $batchIndex,
                'status' => $response['status'],
            ]);

            return ['success' => false, 'error' => $response['error']];
        }

        return ['success' => true, 'error' => ''];
    }

    /**
     * @param array<string, mixed> $productData
     */
    public function upsertProduct(
        string $apiKey,
        string $channel,
        string $siteUrl,
        int $productId,
        array $productData,
    ): int {
        return $this->request(
            'PUT',
            sprintf('/api/v1/ingest/products/%d', $productId),
            $apiKey,
            $channel,
            ['site' => $siteUrl, 'product' => $productData],
        )['status'];
    }

    public function deleteProduct(string $apiKey, string $channel, int $productId): int
    {
        return $this->request('DELETE', sprintf('/api/v1/ingest/products/%d', $productId), $apiKey, $channel)['status'];
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, error: string}
     */
    private function request(
        string $method,
        string $path,
        string $apiKey = '',
        string $channel = '',
        ?array $json = null,
        array $headers = [],
        int $attempts = self::MAX_ATTEMPTS,
        int $timeout = self::TIMEOUT_SECONDS,
        ?string $body = null,
    ): array {
        $headers += ['Accept' => 'application/json', 'User-Agent' => 'Agent001-Sylius/' . self::VERSION];
        if ('' !== $apiKey) {
            $headers['X-API-Key'] = $apiKey;
        }
        if ('' !== $channel) {
            $headers['X-Channel-Code'] = $channel;
        }

        $options = ['headers' => $headers, 'timeout' => $timeout, 'max_redirects' => 0];
        if (null !== $json) {
            $options['json'] = $json;
        }
        if (null !== $body) {
            $options['body'] = $body;
        }

        $result = ['status' => 0, 'body' => '', 'error' => ''];
        $apiBaseUrl = rtrim($this->apiBaseUrl ?: $this->baseUrl, '/');
        if (!BackendUrl::isAllowed($apiBaseUrl)) {
            $this->logger->error('Agent001 API address is not secure, the request was not sent.', ['path' => $path]);

            return ['status' => 0, 'body' => '', 'error' => 'insecure_api_base_url'];
        }

        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            try {
                $response = $this->httpClient->request($method, $apiBaseUrl . $path, $options);
                $body = $response->getContent(false);
                $result = [
                    'status' => $response->getStatusCode(),
                    'body' => $body,
                    'error' => $this->remoteError($body),
                ];
            } catch (TransportExceptionInterface $e) {
                $result = ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
            }

            if (!$this->shouldRetry($result['status']) || $attempt >= $attempts) {
                break;
            }

            usleep((int) min(30000, $this->baseDelayMs * (2 ** ($attempt - 1))) * 1000);
        }

        return $result;
    }

    private function shouldRetry(int $status): bool
    {
        return 0 === $status || ($status >= 500 && $status < 600) || 408 === $status || 429 === $status;
    }

    private function isSuccess(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function remoteError(string $body): string
    {
        $error = $this->decode($body)['error'] ?? '';

        return is_string($error) ? substr(trim($error), 0, 300) : '';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{
     *     agents: list<array{id: string, name: string, embed_token: string, active: bool, channels: list<string>}>,
     *     selected_ids: list<string>,
     *     account_name: string,
     * }
     */
    private function normalizeAgents(array $payload): array
    {
        $agents = [];
        foreach ((array) ($payload['agents'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = AgentId::normalize($entry['id'] ?? null);
            $name = is_scalar($entry['name'] ?? null) ? trim((string) $entry['name']) : '';
            if ('' === $id || '' === $name) {
                continue;
            }
            $channels = array_filter((array) ($entry['channels'] ?? []), 'is_string');
            $agents[] = [
                'id' => $id,
                'name' => $name,
                'embed_token' => is_scalar($entry['embed_token'] ?? null) ? (string) $entry['embed_token'] : '',
                'active' => !array_key_exists('active', $entry) || (bool) $entry['active'],
                'channels' => array_values($channels),
            ];
        }

        return [
            'agents' => $agents,
            'selected_ids' => AgentId::normalizeList((array) ($payload['selected_ids'] ?? [])),
            'account_name' => is_string($payload['account_name'] ?? null) ? trim($payload['account_name']) : '',
        ];
    }
}
