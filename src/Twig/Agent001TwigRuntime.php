<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Twig;

use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use Agent001\SyliusAiAssistantPlugin\Client\BackendUrl;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\RuntimeExtensionInterface;

final class Agent001TwigRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly Agent001ConnectionRepository $connectionRepository,
        private readonly Agent001SettingRepository $settingRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly Agent001ClientInterface $client,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{src: string, token: string, key: string, rest: string}|null
     */
    public function widget(): ?array
    {
        try {
            $channelCode = (string) $this->channelContext->getChannel()->getCode();
        } catch (ChannelNotFoundException) {
            return null;
        }

        $setting = $this->settingRepository->findOneByChannelCode($channelCode);
        if (null === $setting || !$setting->isWidgetVisible() || empty($this->connectionRepository->apiKey())) {
            return null;
        }

        $loader = $this->client->getBaseUrl() . '/embed/loader.js';
        if (!BackendUrl::isAllowed($loader)) {
            return null;
        }

        return [
            'src' => $loader,
            'token' => (string) $setting->getEmbedToken(),
            'key' => $setting->getWidgetKey(),
            'rest' => $this->urlGenerator->generate(
                'agent001_sylius_ai_assistant_shop_api',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ];
    }
}
