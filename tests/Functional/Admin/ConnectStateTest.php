<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Functional\Admin;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tests\Agent001\SyliusAiAssistantPlugin\Functional\FunctionalTestCase;
use Tests\Agent001\SyliusAiAssistantPlugin\Http\FakeAgent001Backend;

final class ConnectStateTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->purge();
    }

    public function testConnectionStateIsAcceptedOnlyInTheAdminSessionThatStartedIt(): void
    {
        $this->createChannel();
        $administrator = $this->createAdministrator();

        $owner = $this->client;
        $owner->loginUser($administrator, 'admin');
        $owner->request('POST', '/admin/agent001/connect', [
            '_csrf_token' => $this->csrfToken($owner, 'agent001_sylius_ai_assistant_connect'),
        ]);

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $owner->getResponse()->headers->get('Location');
        $this->assertStringStartsWith(FakeAgent001Backend::BASE_URL . '/connect?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        $this->assertSame('http://localhost/admin/agent001/callback', $query['return_to'] ?? null);
        $state = (string) ($query['state'] ?? '');

        $intruder = static::getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $intruder);
        $intruder->loginUser($administrator, 'admin');
        $intruder->request('GET', '/admin/agent001/callback', ['state' => $state, 'code' => 'intruder-code']);

        $this->assertSame(400, $intruder->getResponse()->getStatusCode());
        $this->assertSame([], $this->backend($intruder)->requests());
        $this->assertNull($this->storedApiKey());

        $owner->request('GET', '/admin/agent001/callback', ['state' => $state, 'code' => 'owner-code']);

        $this->assertSame(302, $owner->getResponse()->getStatusCode());
        $location = (string) $owner->getResponse()->headers->get('Location');
        $this->assertStringEndsWith('/admin/agent001?connect=success', $location);
        $this->assertSame(['POST /connect/exchange'], $this->backend($owner)->calls());
        $this->assertSame(FakeAgent001Backend::API_KEY, $this->storedApiKey());
    }

    public function testUnknownConnectionStateIsRejectedWithoutContactingTheBackend(): void
    {
        $this->client->loginUser($this->createAdministrator(), 'admin');

        $this->client->request('GET', '/admin/agent001/callback', ['state' => str_repeat('a', 64), 'code' => 'code']);

        $this->assertResponseStatusCodeSame(400);
        $this->assertNoStore($this->client);
        $this->assertSame([], $this->backend($this->client)->calls());
        $this->assertNull($this->storedApiKey());
    }
}
