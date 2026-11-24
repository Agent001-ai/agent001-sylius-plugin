<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Menu;

use Agent001\SyliusAiAssistantPlugin\Menu\AdminMenuListener;
use Knp\Menu\ItemInterface;
use Knp\Menu\MenuFactory;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListenerTest extends TestCase
{
    private const SYLIUS_TABLER_ICONS = __DIR__
        . '/../../../vendor/sylius/sylius/src/Sylius/Bundle/UiBundle/Resources/assets/icons/tabler';

    public function testItAddsItemUnderConfigurationWithBundledIcon(): void
    {
        $factory = new MenuFactory();
        $menu = $factory->createItem('root');
        $menu->addChild('configuration');

        (new AdminMenuListener())(new MenuBuilderEvent($factory, $menu));

        $configuration = $menu->getChild('configuration');
        $this->assertNotNull($configuration);
        $this->assertBundledIcon($configuration->getChild('agent001_sylius_ai_assistant_settings'));
    }

    public function testItAddsTopLevelItemWithBundledIconWithoutConfiguration(): void
    {
        $factory = new MenuFactory();
        $menu = $factory->createItem('root');

        (new AdminMenuListener())(new MenuBuilderEvent($factory, $menu));

        $this->assertBundledIcon($menu->getChild('agent001_sylius_ai_assistant'));
    }

    private function assertBundledIcon(?ItemInterface $item): void
    {
        $this->assertNotNull($item);
        $icon = $item->getLabelAttribute('icon');
        $this->assertIsString($icon);
        $this->assertStringStartsWith('tabler:', $icon);
        $this->assertFileExists(self::SYLIUS_TABLER_ICONS . '/' . substr($icon, \strlen('tabler:')) . '.svg');
    }
}
