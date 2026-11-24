<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Tests\Agent001\SyliusAiAssistantPlugin\Behat\Context\Api\OrderLookupContext;
use Tests\Agent001\SyliusAiAssistantPlugin\Behat\Context\Setup\Agent001Context;
use Tests\Agent001\SyliusAiAssistantPlugin\Behat\Context\Ui\Admin\ManagingAgent001Context;
use Tests\Agent001\SyliusAiAssistantPlugin\Behat\Context\Ui\Shop\ChatWidgetContext;
use Tests\Agent001\SyliusAiAssistantPlugin\Behat\Page\Admin\SettingsPage;
use Tests\Agent001\SyliusAiAssistantPlugin\Http\FakeAgent001Backend;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->public();

    $services->set('agent001_sylius_ai_assistant.behat.page.admin.settings', SettingsPage::class)
        ->parent('sylius.behat.symfony_page')
        ->private();

    $services->set('agent001_sylius_ai_assistant.behat.context.setup.agent001', Agent001Context::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('sylius.behat.shared_storage'),
            service(FakeAgent001Backend::class),
            service('cache.app'),
        ]);

    $services
        ->set('agent001_sylius_ai_assistant.behat.context.ui.admin.managing_agent001', ManagingAgent001Context::class)
        ->args([
            service('agent001_sylius_ai_assistant.behat.page.admin.settings'),
            service('behat.mink.default_session'),
        ]);

    $services->set('agent001_sylius_ai_assistant.behat.context.ui.shop.chat_widget', ChatWidgetContext::class)
        ->args([
            service('sylius.behat.page.shop.home'),
            service('behat.mink.default_session'),
        ]);

    $services->set('agent001_sylius_ai_assistant.behat.context.api.order_lookup', OrderLookupContext::class)
        ->args([
            service('behat.mink.default_session'),
            service('sylius.behat.shared_storage'),
        ]);
};
