<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Twig;

use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Agent001\SyliusAiAssistantPlugin\Twig\Agent001TwigExtension;
use Agent001\SyliusAiAssistantPlugin\Twig\Agent001TwigRuntime;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Core\Model\Channel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

final class Agent001TwigExtensionTest extends TestCase
{
    private const TEMPLATE = '{{ agent001_sylius_ai_assistant_widget()|json_encode|raw }}';

    public function testTheWidgetIsRenderedForAChannelWithAFinishedSetupAndAnActiveAgent(): void
    {
        $setting = $this->setting();

        $widget = $this->render($setting);

        $this->assertSame([
            'src' => 'https://app.agent001.test/embed/loader.js',
            'token' => 'embed-token',
            'key' => $setting->getWidgetKey(),
            'rest' => 'https://shop.test/agent001/v1',
        ], $widget);
    }

    public function testTheWidgetWaitsForTheFirstSynchronization(): void
    {
        $setting = $this->setting();
        $setting->setSetupCompleted(false);

        $this->assertNull($this->render($setting));
    }

    public function testTheWidgetIsHiddenWhenTheAgentIsDisabled(): void
    {
        $setting = $this->setting();
        $setting->setAgentActive(false);

        $this->assertNull($this->render($setting));
    }

    public function testTheWidgetIsHiddenWithoutAConnection(): void
    {
        $this->assertNull($this->render($this->setting(), apiKey: ''));
    }

    public function testTheLoaderIsOnlyServedFromAnAllowedAddress(): void
    {
        $this->assertNull($this->render($this->setting(), baseUrl: 'http://app.agent001.test'));
        $this->assertSame(
            'http://agent001.localhost/embed/loader.js',
            $this->render($this->setting(), baseUrl: 'http://agent001.localhost')['src'] ?? null,
        );
    }

    public function testTheWidgetIsHiddenForAChannelWithoutSettings(): void
    {
        $this->assertNull($this->render(null));
    }

    public function testTheWidgetIsHiddenOutsideAChannel(): void
    {
        $this->assertNull($this->render($this->setting(), channelFound: false));
    }

    /**
     * @return array<string, string>|null
     */
    private function render(
        ?Agent001Setting $setting,
        string $apiKey = 'api-key',
        string $baseUrl = 'https://app.agent001.test',
        bool $channelFound = true,
    ): ?array {
        $connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $connectionRepository->method('apiKey')->willReturn($apiKey);

        $settingRepository = $this->createMock(Agent001SettingRepository::class);
        $settingRepository->method('findOneByChannelCode')->with('WEB')->willReturn($setting);

        $channel = new Channel();
        $channel->setCode('WEB');
        $channelContext = $this->createMock(ChannelContextInterface::class);
        if ($channelFound) {
            $channelContext->method('getChannel')->willReturn($channel);
        } else {
            $channelContext->method('getChannel')->willThrowException(new ChannelNotFoundException());
        }

        $client = $this->createMock(Agent001ClientInterface::class);
        $client->method('getBaseUrl')->willReturn($baseUrl);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->with('agent001_sylius_ai_assistant_shop_api', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://shop.test/agent001/v1');

        $runtime = new Agent001TwigRuntime(
            $connectionRepository,
            $settingRepository,
            $channelContext,
            $client,
            $urlGenerator,
        );

        $twig = new Environment(new ArrayLoader(['widget' => self::TEMPLATE]));
        $twig->addExtension(new Agent001TwigExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([Agent001TwigRuntime::class => static fn () => $runtime]));

        $widget = json_decode($twig->render('widget'), true);

        return is_array($widget) ? $widget : null;
    }

    private function setting(): Agent001Setting
    {
        $setting = new Agent001Setting('WEB');
        $setting->assignAgent('0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f', 'embed-token', true, 'https://shop.test');
        $setting->setSetupCompleted(true);

        return $setting;
    }
}
