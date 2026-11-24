<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Functional\Admin;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Message\SyncCatalogMessage;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Tests\Agent001\SyliusAiAssistantPlugin\Functional\FunctionalTestCase;

final class SettingsTest extends FunctionalTestCase
{
    private const INLINE_CODE = '.page-body script:not([src]), .page-body [style], .page-body [onsubmit]';

    private const SETTINGS = '/admin/agent001';

    private const ACTIVE_STEP = '[data-agent001-getting-started] .step-item.active';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->purge();
        $this->createChannel();
        $this->client->loginUser($this->createAdministrator(), 'admin');
    }

    public function testAdministratorReachesTheSettingsFromTheAdminMenu(): void
    {
        $crawler = $this->client->request('GET', '/admin/');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('a[href$="' . self::SETTINGS . '"]')->count());

        $crawler = $this->client->request('GET', self::SETTINGS);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.page-header h1', 'Agent001 Support Assistant');
        $this->assertSelectorTextContains('.page-header .breadcrumb', 'Agent001 Support Assistant');
        $this->assertCount(1, $crawler->filter('form[action$="/agent001/connect"]'));
        $this->assertCount(0, $crawler->filter('form[action$="/agent001/agents"]'));
        $script = $crawler->filter('.page-body script[src$="/admin/settings.js"]');
        $this->assertCount(1, $script);
        $this->assertNull($script->attr('data-progress-url'));
        $this->assertCount(0, $crawler->filter(self::INLINE_CODE));
        $this->assertSelectorTextContains(self::ACTIVE_STEP, 'Connect store');
        $this->assertSelectorTextContains('.empty .empty-title', 'Connect your store to Agent001');
        $this->assertCount(1, $crawler->filter('.empty .empty-icon svg'));
        $this->assertSelectorTextContains('.empty-action button[type="submit"]', 'Connect store');
        $this->assertCount(1, $crawler->filter('.empty-action button[type="submit"] svg'));
    }

    public function testConnectedStoreWithoutAgentsShowsHowToCreateTheFirstOne(): void
    {
        $this->connectStore();

        $crawler = $this->client->request('GET', self::SETTINGS);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-agent001-connected]', 'Connected');
        $this->assertSelectorTextContains(self::ACTIVE_STEP, 'Assign an agent');
        $this->assertCount(0, $crawler->filter('form[action$="/agent001/agents"]'));
        $link = $crawler->filter('[data-agent001-no-agents] a[target="_blank"]');
        $this->assertCount(1, $link);
        $this->assertStringContainsString('noopener', (string) $link->attr('rel'));
        $this->assertStringContainsString('Create your first agent', $link->text());
        $this->assertCount(2, $link->filter('svg'));
        $this->assertCount(0, $crawler->filter(self::INLINE_CODE));
    }

    public function testAFailedSynchronizationIsShownAsARedBadgeWithItsReason(): void
    {
        $this->connectStore();
        $this->backend()->respond('GET', '/api/v1/integrations/me/agents', 200, ['agents' => [[
            'id' => self::AGENT_ID,
            'name' => 'Support',
            'embed_token' => 'embed-token',
            'active' => true,
        ]]]);
        $this->createChannel(self::CHANNEL_CODE . '_B2B', self::OTHER_HOST);
        $this->createSetting(setupCompleted: false)->finishSync(
            false,
            'agent001_sylius_ai_assistant.sync.failed',
            new \DateTimeImmutable(),
            ['%error%' => 'Backend unavailable'],
        );
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', self::SETTINGS);

        $this->assertResponseIsSuccessful();
        $badge = $crawler->filter('[data-agent001-badge="' . self::CHANNEL_CODE . '"]');
        $this->assertStringContainsString('bg-red-lt', (string) $badge->attr('class'));
        $this->assertStringContainsString('Failed', $badge->text());
        $error = $crawler->filter('[data-agent001-error="' . self::CHANNEL_CODE . '"]');
        $this->assertStringContainsString('Sync failed: Backend unavailable', $error->text());
        $this->assertStringNotContainsString('d-none', (string) $error->attr('class'));
        $alert = $crawler->filter('[data-agent001-alert="' . self::CHANNEL_CODE . '"]');
        $this->assertStringContainsString('alert-danger', (string) $alert->attr('class'));
        $this->assertSame(['failed'], $this->visibleAlertIcons($alert));
        $this->assertCount(1, $crawler->filter('#agent001-taken'));
        $otherSelect = 'select#agent001-agent-' . self::CHANNEL_CODE . '_B2B';
        $this->assertCount(1, $crawler->filter($otherSelect . '[aria-describedby="agent001-taken"]'));
        $this->assertSelectorTextContains('.card-subtitle', 'Catalog synchronization');
        $this->assertSelectorTextContains(self::ACTIVE_STEP, 'Synchronize catalog');
        $selectId = 'agent001-agent-' . self::CHANNEL_CODE;
        $this->assertCount(1, $crawler->filter('label.visually-hidden[for="' . $selectId . '"]'));
        $this->assertCount(1, $crawler->filter('select#' . $selectId));
        $this->assertGreaterThan(0, $crawler->filter('[aria-live="polite"]')->count());
        $this->assertCount(0, $crawler->filter(self::INLINE_CODE));
    }

    public function testNestedTaxonsShowTheirParentButNotTheMenuRoot(): void
    {
        $this->connectStore();
        $root = $this->taxon('MENU', 'Category');
        $shirts = $this->taxon('SHIRTS', 'T-shirts', $root);
        $this->taxon('SHIRTS_MEN', 'Men', $shirts);
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)
            ->findOneBy(['code' => self::CHANNEL_CODE]);
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $channel->setMenuTaxon($root);
        $this->entityManager()->flush();
        $this->createSetting();

        $crawler = $this->client->request('GET', self::SETTINGS);

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['T-shirts', 'T-shirts / Men'],
            $crawler->filter('[data-agent001-taxons] .form-check-label')->each(
                static fn (Crawler $label): string => $label->text(),
            ),
        );
        $this->assertSelectorTextContains('[data-agent001-taxon-count]', '2 of 2 selected');
    }

    public function testASynchronizedChannelIsShownAsAGreenBadgeAndCompletesTheGettingStartedGuide(): void
    {
        $this->connectStore();
        $this->createSetting()->finishSync(
            true,
            'agent001_sylius_ai_assistant.sync.synced',
            new \DateTimeImmutable(),
            ['%count%' => 3],
        );
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', self::SETTINGS);

        $this->assertResponseIsSuccessful();
        $badge = $crawler->filter('[data-agent001-badge="' . self::CHANNEL_CODE . '"]');
        $this->assertStringContainsString('bg-green-lt', (string) $badge->attr('class'));
        $this->assertStringContainsString('Synchronized', $badge->text());
        $alert = $crawler->filter('[data-agent001-alert="' . self::CHANNEL_CODE . '"]');
        $this->assertStringContainsString('alert-success', (string) $alert->attr('class'));
        $this->assertSame(['success'], $this->visibleAlertIcons($alert));
        $this->assertSelectorTextContains(self::ACTIVE_STEP, 'Launch chat');
        $this->assertCount(4, $crawler->filter('[data-agent001-getting-started] .step-item'));
        $this->assertSelectorTextContains('[data-agent001-getting-started-hint]', 'The chat is live');
        $this->assertCount(1, $crawler->filter('time[datetime]'));
    }

    public function testASynchronizedChannelWithADisabledAgentAsksToLaunchTheChat(): void
    {
        $this->connectStore();
        $this->createSetting(active: false);

        $this->client->request('GET', self::SETTINGS);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(self::ACTIVE_STEP, 'Launch chat');
        $this->assertSelectorTextContains('[data-agent001-getting-started-hint]', 'Enable the agent');
    }

    public function testConnectedStoreLoadsTheProgressPollingAndConfirmsDisconnecting(): void
    {
        $this->connectStore();
        $this->createSetting();

        $crawler = $this->client->request('GET', self::SETTINGS);

        $this->assertResponseIsSuccessful();
        $script = $crawler->filter('.page-body script[src$="/admin/settings.js"]');
        $this->assertStringEndsWith('/admin/agent001/progress', (string) $script->attr('data-progress-url'));
        $this->assertSame('Queued', json_decode((string) $script->attr('data-labels'), true)['queued'] ?? null);
        $this->assertNotEmpty($crawler->filter('form[action$="/agent001/disconnect"]')->attr('data-agent001-confirm'));
        $this->assertCount(1, $crawler->filter('button[data-agent001-sync="' . self::CHANNEL_CODE . '"]'));
        $this->assertCount(0, $crawler->filter(self::INLINE_CODE));
    }

    public function testAssigningAnAgentStoresTheChannelSettingAndQueuesTheSynchronization(): void
    {
        $this->connectStore();
        $agent = [
            'id' => self::AGENT_ID,
            'name' => 'Support',
            'embed_token' => 'embed-token',
            'active' => true,
            'channels' => [],
        ];
        $agents = '/api/v1/integrations/me/agents';
        $this->backend()->respond('GET', $agents, 200, ['agents' => [$agent], 'selected_ids' => []]);
        $this->backend()->respond('PUT', $agents, 200, ['agents' => [$agent], 'selected_ids' => [self::AGENT_ID]]);

        $crawler = $this->client->request('GET', self::SETTINGS);
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="/agent001/agents"]')->form();
        $form['agent[' . self::CHANNEL_CODE . ']']->select(self::AGENT_ID);
        $this->client->submit($form);

        $this->assertResponseRedirects(self::SETTINGS);
        $setting = $this->setting();
        $this->assertNotNull($setting);
        $this->assertSame(self::AGENT_ID, $setting->getAgentId());
        $this->assertSame('embed-token', $setting->getEmbedToken());
        $this->assertSame('http://localhost', $setting->getSite());
        $this->assertSame(Agent001Setting::STATUS_QUEUED, $setting->getSyncStatus());
        $this->assertEquals([new SyncCatalogMessage(self::CHANNEL_CODE)], $this->queuedMessages($this->client));
        $this->assertSame([
            'GET /api/v1/integrations/me/agents',
            'DELETE /api/v1/integrations/me/catalogs/default',
            'PUT /api/v1/integrations/me/store-hosts',
            'PUT /api/v1/integrations/me/catalogs/' . rawurlencode('channel:' . self::CHANNEL_CODE),
            'PUT /api/v1/integrations/me/agents',
            'POST /api/v1/integrations/me/agents/' . self::AGENT_ID . '/domain',
        ], $this->backend()->calls());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Agents have been saved.');
    }

    public function testAssigningAgentsWithoutAValidCsrfTokenChangesNothing(): void
    {
        $this->connectStore();

        $this->client->request('POST', self::SETTINGS . '/agents', [
            '_csrf_token' => 'forged',
            'agent' => [self::CHANNEL_CODE => self::AGENT_ID],
        ]);

        $this->assertResponseRedirects(self::SETTINGS);
        $this->assertNull($this->setting());
        $this->assertSame([], $this->queuedMessages($this->client));
        $this->assertSame([], $this->backend()->calls());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'The form has expired.');
    }

    public function testDisablingTheAgentIsSavedAfterTheBackendConfirmsIt(): void
    {
        $this->connectStore();
        $this->createSetting();
        $this->backend()->respond('POST', '/api/v1/integrations/me/agents/' . self::AGENT_ID . '/active', 200, [
            'agent_id' => self::AGENT_ID,
            'active' => false,
        ]);

        $this->client->submit($this->settingsForm('/agent001/toggle'));

        $this->assertResponseRedirects(self::SETTINGS);
        $this->assertFalse($this->setting()?->isAgentActive());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'The agent has been disabled.');
    }

    public function testDisablingTheAgentIsNotSavedWhenTheBackendDoesNotConfirmIt(): void
    {
        $this->connectStore();
        $this->createSetting();

        $this->client->submit($this->settingsForm('/agent001/toggle'));

        $this->assertResponseRedirects(self::SETTINGS);
        $this->assertTrue($this->setting()?->isAgentActive());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Could not change the agent status.');
    }

    public function testCatalogSynchronizationIsQueued(): void
    {
        $this->connectStore();
        $this->createSetting();

        $this->client->submit($this->settingsForm('/agent001/catalog'));

        $this->assertResponseRedirects(self::SETTINGS);
        $this->assertSame(Agent001Setting::STATUS_QUEUED, $this->setting()?->getSyncStatus());
        $this->assertEquals([new SyncCatalogMessage(self::CHANNEL_CODE)], $this->queuedMessages($this->client));
    }

    public function testCatalogSynchronizationRejectsUnknownTaxons(): void
    {
        $this->connectStore();
        $this->createSetting();
        $token = $this->settingsForm('/agent001/catalog')->getValues()['_csrf_token'];

        $this->client->request('POST', self::SETTINGS . '/catalog', [
            '_csrf_token' => $token,
            'channel' => self::CHANNEL_CODE,
            'taxons' => ['UNKNOWN_TAXON'],
        ]);

        $this->assertResponseRedirects(self::SETTINGS);
        $this->assertNull($this->setting()?->getSyncStatus());
        $this->assertSame([], $this->queuedMessages($this->client));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Select valid taxons.');
    }

    public function testProgressEndpointReportsEveryChannelWithoutCaching(): void
    {
        $this->connectStore();
        $this->createSetting()->reportSync(
            Agent001Setting::STATUS_READING,
            'agent001_sylius_ai_assistant.sync.progress',
            500,
            ['%sent%' => 500, '%total%' => 501],
        );
        $this->entityManager()->flush();

        $this->client->request('GET', self::SETTINGS . '/progress');

        $this->assertResponseIsSuccessful();
        $this->assertNoStore($this->client);
        $this->assertSame([self::CHANNEL_CODE => [
            'status' => Agent001Setting::STATUS_READING,
            'message' => 'Sent 500 of 501 products...',
            'count' => 500,
            'running' => true,
        ]], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->request('GET', self::SETTINGS);
        $this->assertSelectorTextContains(
            '[data-agent001-message="' . self::CHANNEL_CODE . '"]',
            'Sent 500 of 501 products...',
        );
    }

    private function taxon(string $code, string $name, ?TaxonInterface $parent = null): TaxonInterface
    {
        $taxon = $this->factory('sylius.factory.taxon')->createNew();
        self::assertInstanceOf(TaxonInterface::class, $taxon);
        $taxon->setCurrentLocale(self::LOCALE);
        $taxon->setFallbackLocale(self::LOCALE);
        $taxon->setCode(self::PRODUCT_PREFIX . $code);
        $taxon->setName($name);
        $taxon->setSlug('agent001-ft-' . strtolower($code));
        $taxon->setParent($parent);
        $this->entityManager()->persist($taxon);
        $this->entityManager()->flush();

        return $taxon;
    }

    /**
     * @return list<string>
     */
    private function visibleAlertIcons(Crawler $alert): array
    {
        return $alert->filter('[data-agent001-alert-icon]:not(.d-none)')->each(
            static fn (Crawler $icon): string => (string) $icon->attr('data-agent001-alert-icon'),
        );
    }

    private function settingsForm(string $action): Form
    {
        $crawler = $this->client->request('GET', self::SETTINGS);
        $this->assertResponseIsSuccessful();

        return $this->form($crawler, $action);
    }

    private function form(Crawler $crawler, string $action): Form
    {
        $form = $crawler->filter(sprintf('form[action$="%s"]', $action));
        $this->assertCount(1, $form);

        return $form->form();
    }
}
