<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Functional\Api;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tests\Agent001\SyliusAiAssistantPlugin\Functional\FunctionalTestCase;
use Tests\Agent001\SyliusAiAssistantPlugin\Http\FakeAgent001Backend;

final class OrderLookupTest extends FunctionalTestCase
{
    private const OTHER_CHANNEL = self::CHANNEL_CODE . '_B2B';

    private const NUMBER = self::ORDER_PREFIX . '1001';

    private const EMAIL = 'jane' . self::CUSTOMER_DOMAIN;

    private KernelBrowser $client;

    private string $ip;

    private Agent001Setting $setting;

    private Agent001Setting $otherSetting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->purge();
        $this->ip = sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        $channel = $this->createChannel(self::CHANNEL_CODE, self::HOST);
        $this->createChannel(self::OTHER_CHANNEL, self::OTHER_HOST);
        $this->createOrder($channel, self::NUMBER, self::EMAIL);
        $this->connectStore();
        $this->setting = $this->createSetting();
        $this->otherSetting = $this->createSetting(
            self::OTHER_CHANNEL,
            self::OTHER_AGENT_ID,
            site: 'http://' . self::OTHER_HOST,
        );
    }

    public function testTheBackendKeyReturnsTheOrderWithoutPersonalData(): void
    {
        $this->lookup(FakeAgent001Backend::API_KEY, self::EMAIL);

        $this->assertResponseIsSuccessful();
        $this->assertNoStore($this->client);
        $content = (string) $this->client->getResponse()->getContent();
        $order = json_decode($content, true);
        $this->assertIsArray($order);
        $this->assertSame(self::NUMBER, $order['order_number']);
        $this->assertSame('new', $order['status']);
        $this->assertSame('USD', $order['currency']);
        $this->assertStringNotContainsString(self::EMAIL, $content);
        $this->assertStringNotContainsString('Secret', $content);
    }

    public function testTheWidgetKeyOfTheOrderChannelReturnsTheOrder(): void
    {
        $this->lookup($this->setting->getWidgetKey(), strtoupper(self::EMAIL));

        $this->assertResponseIsSuccessful();
    }

    public function testAnotherCustomerEmailIsNotFound(): void
    {
        $this->lookup(FakeAgent001Backend::API_KEY, 'john' . self::CUSTOMER_DOMAIN);

        $this->assertError(404, 'agent001_order_not_found');
    }

    public function testTheWidgetKeyOfAnotherChannelDoesNotFindTheOrder(): void
    {
        $this->lookup($this->otherSetting->getWidgetKey(), self::EMAIL, self::OTHER_HOST);

        $this->assertError(404, 'agent001_order_not_found');
    }

    public function testTheWidgetKeyOfAnotherChannelIsRejectedOnThisChannel(): void
    {
        $this->lookup($this->otherSetting->getWidgetKey(), self::EMAIL);

        $this->assertError(401, 'agent001_unauthorized');
    }

    public function testMissingOrWrongKeyIsUnauthorized(): void
    {
        $this->lookup('', self::EMAIL);
        $this->assertError(401, 'agent001_unauthorized');

        $this->lookup('wrong-key', self::EMAIL);
        $this->assertError(401, 'agent001_unauthorized');
    }

    public function testRepeatedFailedWidgetLookupsAreRateLimited(): void
    {
        $email = 'guess-' . bin2hex(random_bytes(4)) . self::CUSTOMER_DOMAIN;
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->lookup($this->setting->getWidgetKey(), $email);
            $this->assertError(404, 'agent001_order_not_found');
        }

        $this->lookup($this->setting->getWidgetKey(), $email);

        $this->assertError(429, 'agent001_rate_limited');
        $this->assertResponseHeaderSame('Retry-After', '60');
    }

    public function testPreflightAllowsOnlyKnownOrigins(): void
    {
        $this->client->request('OPTIONS', '/agent001/v1/orders/' . self::NUMBER, server: [
            'HTTP_HOST' => self::HOST,
            'HTTP_ORIGIN' => FakeAgent001Backend::BASE_URL,
        ]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHeaderSame('Access-Control-Allow-Origin', FakeAgent001Backend::BASE_URL);
        $allowedHeaders = (string) $this->client->getResponse()->headers->get('Access-Control-Allow-Headers');
        $this->assertStringContainsString('X-Agent001-Auth', $allowedHeaders);
        $this->assertNoStore($this->client);

        $this->client->request('OPTIONS', '/agent001/v1/orders/' . self::NUMBER, server: [
            'HTTP_HOST' => self::HOST,
            'HTTP_ORIGIN' => 'https://evil.example',
        ]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseNotHasHeader('Access-Control-Allow-Origin');
    }

    private function lookup(string $key, string $email, string $host = self::HOST): void
    {
        $server = ['HTTP_HOST' => $host, 'REMOTE_ADDR' => $this->ip, 'CONTENT_TYPE' => 'application/json'];
        if ('' !== $key) {
            $server['HTTP_X_AGENT001_AUTH'] = $key;
        }

        $this->client->request(
            'POST',
            '/agent001/v1/orders/' . self::NUMBER,
            server: $server,
            content: (string) json_encode(['email' => $email]),
        );
    }

    private function assertError(int $status, string $code): void
    {
        $this->assertResponseStatusCodeSame($status);
        $this->assertNoStore($this->client);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame($code, $body['code'] ?? null);
    }
}
