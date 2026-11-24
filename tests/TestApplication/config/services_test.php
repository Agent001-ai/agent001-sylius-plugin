<?php

declare(strict_types=1);

use Agent001\SyliusAiAssistantPlugin\Message\CheckSyncSessionMessage;
use Agent001\SyliusAiAssistantPlugin\Message\SyncCatalogMessage;
use Agent001\SyliusAiAssistantPlugin\Message\SyncProductMessage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tests\Agent001\SyliusAiAssistantPlugin\Http\FakeAgent001Backend;

return function (ContainerConfigurator $container): void {
    if (!str_starts_with($container->env(), 'test')) {
        return;
    }

    if (class_exists('FriendsOfBehat\SymfonyExtension\Bundle\FriendsOfBehatSymfonyExtensionBundle')) {
        $container->import('../../../vendor/sylius/sylius/src/Sylius/Behat/Resources/config/services.xml');
        $container->import('../../Behat/Resources/services.php');
    }

    $build = dirname(__DIR__) . '/build';

    $container->services()->set(FakeAgent001Backend::class)->public();
    $container->extension('framework', [
        'http_client' => [
            'mock_response_factory' => FakeAgent001Backend::class,
        ],
        'messenger' => [
            'transports' => [
                'agent001_sylius_ai_assistant_test' => 'in-memory://',
            ],
            'routing' => [
                SyncCatalogMessage::class => 'agent001_sylius_ai_assistant_test',
                CheckSyncSessionMessage::class => 'agent001_sylius_ai_assistant_test',
                SyncProductMessage::class => 'agent001_sylius_ai_assistant_test',
            ],
        ],
        'assets' => [
            'packages' => [
                'shop' => ['json_manifest_path' => $build . '/manifest.json'],
                'admin' => ['json_manifest_path' => $build . '/manifest.json'],
                'app.admin' => ['json_manifest_path' => $build . '/manifest.json'],
                'app.shop' => ['json_manifest_path' => $build . '/manifest.json'],
            ],
        ],
    ]);
    $container->extension('ux_icons', [
        'iconify' => ['enabled' => false],
    ]);
    $container->extension('webpack_encore', [
        'strict_mode' => false,
        'output_path' => $build,
        'builds' => [
            'shop' => $build,
            'admin' => $build,
            'app.admin' => $build,
            'app.shop' => $build,
        ],
    ]);
};
