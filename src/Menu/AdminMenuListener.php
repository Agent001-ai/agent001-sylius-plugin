<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: 'sylius.menu.admin.main')]
final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $configuration = $menu->getChild('configuration');
        if (null !== $configuration) {
            $configuration
                ->addChild('agent001_sylius_ai_assistant_settings', [
                    'route' => 'agent001_sylius_ai_assistant_admin_settings',
                ])
                ->setLabel('agent001_sylius_ai_assistant.ui.menu')
                ->setLabelAttribute('icon', 'tabler:bubble-text');
        } else {
            $menu
                ->addChild('agent001_sylius_ai_assistant', [
                    'route' => 'agent001_sylius_ai_assistant_admin_settings',
                ])
                ->setLabel('agent001_sylius_ai_assistant.ui.menu')
                ->setLabelAttribute('icon', 'tabler:bubble-text');
        }
    }
}
