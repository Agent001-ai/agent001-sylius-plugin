<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Functional\Shop;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Agent001\SyliusAiAssistantPlugin\Functional\FunctionalTestCase;
use Tests\Agent001\SyliusAiAssistantPlugin\Http\FakeAgent001Backend;

final class ChatWidgetTest extends FunctionalTestCase
{
    private const OTHER_CHANNEL = self::CHANNEL_CODE . '_B2B';

    private const LOADER = FakeAgent001Backend::BASE_URL . '/embed/loader.js';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->purge();
        $this->createChannel(self::CHANNEL_CODE, self::HOST);
        $this->createChannel(self::OTHER_CHANNEL, self::OTHER_HOST);
        $this->connectStore();
    }

    public function testTheWidgetOfTheCurrentChannelIsRenderedOnTheStorefront(): void
    {
        $setting = $this->createSetting();

        $script = $this->loaderScripts($this->storefront(self::HOST));

        $this->assertCount(1, $script);
        $this->assertSame('embed-token-' . self::CHANNEL_CODE, $script->attr('data-token'));
        $this->assertSame($setting->getWidgetKey(), $script->attr('data-plugin-key'));
        $this->assertSame('http://' . self::HOST . '/agent001/v1', $script->attr('data-plugin-rest'));
        $this->assertNotNull($script->attr('async'));
    }

    public function testEachChannelRendersOnlyItsOwnWidget(): void
    {
        $this->createSetting();
        $other = $this->createSetting(self::OTHER_CHANNEL, self::OTHER_AGENT_ID, site: 'http://' . self::OTHER_HOST);

        $script = $this->loaderScripts($this->storefront(self::OTHER_HOST));

        $this->assertCount(1, $script);
        $this->assertSame('embed-token-' . self::OTHER_CHANNEL, $script->attr('data-token'));
        $this->assertSame($other->getWidgetKey(), $script->attr('data-plugin-key'));
    }

    public function testTheWidgetIsHiddenWhenTheChannelHasNoAgent(): void
    {
        $this->createSetting();

        $this->assertCount(0, $this->loaderScripts($this->storefront(self::OTHER_HOST)));
    }

    public function testTheWidgetIsHiddenWhileTheAgentIsDisabled(): void
    {
        $this->createSetting(active: false);

        $this->assertCount(0, $this->loaderScripts($this->storefront(self::HOST)));
    }

    public function testTheWidgetIsHiddenUntilTheFirstSynchronizationFinishes(): void
    {
        $this->createSetting(setupCompleted: false);

        $this->assertCount(0, $this->loaderScripts($this->storefront(self::HOST)));
    }

    private function storefront(string $host): Crawler
    {
        $crawler = $this->client->request('GET', 'http://' . $host . '/en_US/');
        $this->assertResponseIsSuccessful();

        return $crawler;
    }

    private function loaderScripts(Crawler $crawler): Crawler
    {
        return $crawler->filter(sprintf('script[src="%s"]', self::LOADER));
    }
}
