<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Client;

use Agent001\SyliusAiAssistantPlugin\Client\Agent001Client;
use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class Agent001ClientTest extends TestCase
{
    private const AGENT = '0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f';

    public function testItKeepsUuidAgentsAndActiveFlag(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'agents' => [
                ['id' => self::AGENT, 'name' => 'Shop', 'embed_token' => 'tok', 'active' => false],
                ['id' => 12, 'name' => 'Legacy', 'embed_token' => 'x'],
            ],
            'selected_ids' => [self::AGENT],
            'account_name' => 'Acme',
        ]), ['http_code' => 200]));

        $result = $this->client($http)->fetchAgents('key', 'channel:WEB');

        $this->assertNotNull($result);
        $this->assertCount(1, $result['agents']);
        $this->assertSame(self::AGENT, $result['agents'][0]['id']);
        $this->assertFalse($result['agents'][0]['active']);
        $this->assertSame([self::AGENT], $result['selected_ids']);
        $this->assertSame('Acme', $result['account_name']);
    }

    public function testItRetriesServerErrorsOnProductUpsert(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => $calls < 3 ? 503 : 200]);
        });

        $status = $this->client($http)
            ->upsertProduct('key', 'channel:WEB', 'https://shop.test', 5, ['id' => 5]);

        $this->assertSame(200, $status);
        $this->assertSame(3, $calls);
    }

    public function testItDoesNotRetryClientErrors(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 422]);
        });

        $status = $this->client($http)->deleteProduct('key', 'channel:WEB', 5);

        $this->assertSame(422, $status);
        $this->assertSame(1, $calls);
    }

    public function testNetworkErrorsAreRetriedAndReportedAsStatusZero(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['error' => 'Connection refused']);
        });

        $status = $this->client($http)
            ->upsertProduct('key', 'channel:WEB', 'https://shop.test', 5, ['id' => 5]);

        $this->assertSame(0, $status);
        $this->assertSame(3, $calls);
    }

    public function testProgrammingErrorsAreNeitherRetriedNorHidden(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            throw new \LogicException('Misconfigured HTTP client.');
        });

        try {
            $this->client($http)->upsertProduct('key', 'channel:WEB', 'https://shop.test', 5, ['id' => 5]);
            $this->fail('A programming error may not be turned into a status.');
        } catch (\LogicException $e) {
            $this->assertSame('Misconfigured HTTP client.', $e->getMessage());
        }

        $this->assertSame(1, $calls);
    }

    public function testItConfirmsAgentActiveStatus(): void
    {
        $body = (string) json_encode(['agent_id' => self::AGENT, 'active' => false]);
        $http = new MockHttpClient(new MockResponse($body, ['http_code' => 200]));

        $this->assertNull($this->client($http)->setAgentActive('key', 'channel:WEB', self::AGENT, false));
    }

    public function testItSendsStoreContextWithFirstBatch(): void
    {
        $captured = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$captured,
        ): MockResponse {
            $captured = [
                'url' => $url,
                'body' => json_decode($options['body'], true),
                'headers' => $options['normalized_headers'],
            ];

            return new MockResponse('{}', ['http_code' => 202]);
        });

        $context = [['type' => 'category', 'id' => 'taxon:t', 'title' => 'T', 'content' => 'c', 'url' => '']];
        $result = $this->client($http)
            ->postProductBatch('key', 'channel:WEB', 'https://shop.test', 'sess', 0, true, [], $context);

        $this->assertTrue($result['success']);
        $this->assertSame('https://backend.test/api/v1/ingest', $captured['url']);
        $this->assertSame($context, $captured['body']['store_context']);
        $this->assertSame(Agent001Client::VERSION, $captured['body']['version']);
        $this->assertTrue($captured['body']['is_first']);
        $this->assertSame(['X-Channel-Code: channel:WEB'], $captured['headers']['x-channel-code']);
    }

    public function testABatchAboveTheBodyLimitIsNotSent(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            $this->fail('A batch above the limit may not be sent.');
        });
        $escapedToSixBytes = str_repeat('<', intdiv(Agent001ClientInterface::MAX_BATCH_BYTES, 6));
        $batch = [['id' => 1, 'description' => $escapedToSixBytes]];

        $result = $this->client($http)
            ->postProductBatch('key', 'channel:WEB', 'https://shop.test', 'sess', 0, true, $batch);

        $this->assertSame(['success' => false, 'error' => Agent001ClientInterface::BATCH_TOO_LARGE], $result);
    }

    public function testABatchIsSentAsTheJsonBodyThatWasMeasured(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$sent,
        ): MockResponse {
            $sent = ['body' => $options['body'], 'type' => $options['normalized_headers']['content-type'] ?? []];

            return new MockResponse('{}', ['http_code' => 202]);
        });

        $this->client($http)
            ->postProductBatch('key', 'channel:WEB', 'https://shop.test', 'sess', 0, true, [['id' => 1]]);

        $this->assertSame(['Content-Type: application/json'], $sent['type']);
        $this->assertSame([['id' => 1]], json_decode($sent['body'], true)['products'] ?? null);
    }

    public function testItBuildsConnectUrlForSylius(): void
    {
        $url = $this->client(new MockHttpClient())
            ->getConnectUrl('https://shop.test', 'https://shop.test/agent001/callback', 'state', 'channel:WEB');

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://backend.test/connect?', $url);
        $this->assertSame('channel:WEB', $query['channel']);
        $this->assertSame('sylius', $query['platform']);
    }

    public function testItCallsTheApiAddressAndLinksToThePublicAddress(): void
    {
        $urls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse('{"agents":[]}');
        });
        $client = $this->client($http, 'https://app.agent001.test/', 'http://agent001.localhost/');

        $client->fetchAgents('key', 'channel:WEB');

        $this->assertSame(['http://agent001.localhost/api/v1/integrations/me/agents'], $urls);
        $this->assertSame('https://app.agent001.test', $client->getBaseUrl());
        $this->assertStringStartsWith(
            'https://app.agent001.test/connect?',
            $client->getConnectUrl('https://shop.test', 'https://shop.test/callback', 'state', 'channel:WEB'),
        );
    }

    public function testItDoesNotSendTheApiKeyToAnInsecureAddress(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            $this->fail('No request may leave for an insecure address.');
        });
        $client = $this->client($http, 'https://app.agent001.test', 'http://example.com');

        $this->assertNull($client->fetchAgents('key', 'channel:WEB'));
        $this->assertSame(0, $client->deleteProduct('key', 'channel:WEB', 5));
    }

    public function testItCallsThePublicAddressWhenNoApiAddressIsSet(): void
    {
        $urls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse('{"agents":[]}');
        });

        $this->client($http, 'https://app.agent001.test', null)->fetchAgents('key', 'channel:WEB');

        $this->assertSame(['https://app.agent001.test/api/v1/integrations/me/agents'], $urls);
    }

    private function client(
        MockHttpClient $http,
        string $baseUrl = 'https://backend.test',
        ?string $apiBaseUrl = 'https://backend.test',
    ): Agent001Client {
        return new Agent001Client($http, $baseUrl, $apiBaseUrl, new NullLogger(), 0);
    }
}
