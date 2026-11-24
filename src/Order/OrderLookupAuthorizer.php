<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Order;

use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use Sylius\Component\Channel\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;

final class OrderLookupAuthorizer
{
    public const AUTH_HEADER = 'X-Agent001-Auth';

    public const BACKEND = 'backend';

    public const WIDGET = 'widget';

    public function __construct(
        private readonly Agent001ConnectionRepository $connectionRepository,
        private readonly Agent001SettingRepository $settingRepository,
    ) {
    }

    /**
     * @return self::BACKEND|self::WIDGET|null
     */
    public function authorize(Request $request, ?ChannelInterface $channel): ?string
    {
        $providedAuth = (string) $request->headers->get(self::AUTH_HEADER, '');
        $apiKey = $this->connectionRepository->apiKey();
        if (empty($apiKey) || empty($providedAuth)) {
            return null;
        }

        if (hash_equals($apiKey, $providedAuth)) {
            return self::BACKEND;
        }

        if (null === $channel) {
            return null;
        }

        $setting = $this->settingRepository->findOneByChannelCode((string) $channel->getCode());
        if (null !== $setting && $setting->hasAgent() && hash_equals($setting->getWidgetKey(), $providedAuth)) {
            return self::WIDGET;
        }

        return null;
    }
}
