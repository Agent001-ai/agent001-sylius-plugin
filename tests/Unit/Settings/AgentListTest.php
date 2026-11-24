<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Settings;

use Agent001\SyliusAiAssistantPlugin\Client\Agent001Client;
use Agent001\SyliusAiAssistantPlugin\Settings\AgentList;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AgentListTest extends TestCase
{
    private const AGENTS = '{"agents":[{"id":"0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f","name":"Shop",'
        . '"embed_token":"token","active":true}],"account_name":"Acme"}';

    private int $requests = 0;

    private int $status = 200;

    private string $body = self::AGENTS;

    protected function setUp(): void
    {
        $this->requests = 0;
        $this->status = 200;
        $this->body = self::AGENTS;
    }

    public function testTheListIsFetchedOnceWithinItsLifetime(): void
    {
        $agentList = $this->agentList();

        $first = $agentList->fetch('key', 'channel:WEB');
        $second = $agentList->fetch('key', 'channel:WEB');

        $this->assertSame(1, $this->requests);
        $this->assertSame('Acme', $first['account_name'] ?? null);
        $this->assertSame($first, $second);
    }

    public function testForgettingTheListFetchesItAgain(): void
    {
        $agentList = $this->agentList();

        $agentList->fetch('key', 'channel:WEB');
        $agentList->forget();
        $agentList->fetch('key', 'channel:WEB');

        $this->assertSame(2, $this->requests);
    }

    public function testAnotherApiKeyOrChannelIsNotServedFromTheCache(): void
    {
        $agentList = $this->agentList();

        $agentList->fetch('key', 'channel:WEB');
        $agentList->fetch('other-key', 'channel:WEB');
        $agentList->fetch('other-key', 'channel:B2B');

        $this->assertSame(3, $this->requests);
    }

    public function testAFailedFetchIsNotCached(): void
    {
        $agentList = $this->agentList();
        $this->status = 503;

        $this->assertNull($agentList->fetch('key', 'channel:WEB'));
        $this->assertNull($agentList->fetch('key', 'channel:WEB'));

        $this->assertSame(2, $this->requests);
    }

    public function testAnEmptyListIsNotCached(): void
    {
        $this->body = '{"agents":[],"account_name":"Acme"}';
        $agentList = $this->agentList();

        $this->assertSame([], $agentList->fetch('key', 'channel:WEB')['agents'] ?? null);
        $agentList->fetch('key', 'channel:WEB');

        $this->assertSame(2, $this->requests);
    }

    public function testTheListExpiresAfterFiveMinutes(): void
    {
        $this->assertSame(300, AgentList::TTL_SECONDS);
    }

    private function agentList(): AgentList
    {
        $http = new MockHttpClient(function (): MockResponse {
            ++$this->requests;

            return new MockResponse($this->body, ['http_code' => $this->status]);
        });

        return new AgentList(
            new Agent001Client($http, 'https://backend.test', null, new NullLogger(), 0),
            new ArrayAdapter(),
        );
    }
}
