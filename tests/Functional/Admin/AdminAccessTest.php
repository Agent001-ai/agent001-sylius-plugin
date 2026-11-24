<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Functional\Admin;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tests\Agent001\SyliusAiAssistantPlugin\Functional\FunctionalTestCase;
use Tests\Agent001\SyliusAiAssistantPlugin\Http\FakeAgent001Backend;

final class AdminAccessTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->purge();
    }

    public function testAnonymousVisitorIsSentToTheAdminLoginInsteadOfTheSettings(): void
    {
        $this->client->request('GET', '/admin/agent001');

        $this->assertRedirectsToAdminLogin();
    }

    public function testAnonymousVisitorCannotDisconnectTheStore(): void
    {
        $this->connectStore('http://localhost');

        $this->client->request('POST', '/admin/agent001/disconnect', ['_csrf_token' => 'forged']);

        $this->assertRedirectsToAdminLogin();
        $this->assertSame(FakeAgent001Backend::API_KEY, $this->storedApiKey());
        $this->assertSame([], $this->backend($this->client)->requests());
    }

    public function testAnonymousVisitorCannotCompleteTheConnection(): void
    {
        $this->client->request('GET', '/admin/agent001/callback', ['state' => str_repeat('a', 64), 'code' => 'code']);

        $this->assertRedirectsToAdminLogin();
        $this->assertNull($this->storedApiKey());
        $this->assertSame([], $this->backend($this->client)->requests());
    }

    private function assertRedirectsToAdminLogin(): void
    {
        $this->assertResponseStatusCodeSame(302);
        $this->assertStringEndsWith('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
