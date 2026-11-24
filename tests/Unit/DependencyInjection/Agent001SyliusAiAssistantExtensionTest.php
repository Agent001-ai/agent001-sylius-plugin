<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\DependencyInjection;

use Agent001\SyliusAiAssistantPlugin\DependencyInjection\Agent001SyliusAiAssistantExtension;
use Doctrine\Bundle\MigrationsBundle\DependencyInjection\DoctrineMigrationsExtension;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\TestCase;
use Sylius\TwigHooks\DependencyInjection\SyliusTwigHooksExtension;
use SyliusLabs\DoctrineMigrationsExtraBundle\DependencyInjection\SyliusLabsDoctrineMigrationsExtraExtension;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class Agent001SyliusAiAssistantExtensionTest extends TestCase
{
    private const MIGRATIONS_NAMESPACE = 'Agent001\SyliusAiAssistantPlugin\Migrations';

    public function testItRegistersRateLimitersTwigHooksAndBusWithoutImportedConfiguration(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FrameworkExtension());
        $container->registerExtension(new SyliusTwigHooksExtension());

        (new Agent001SyliusAiAssistantExtension())->prepend($container);

        $framework = array_merge_recursive(...$container->getExtensionConfig('framework'));
        $this->assertSame([
            'agent001_sylius_ai_assistant_api',
            'agent001_sylius_ai_assistant_lookup_failure',
            'agent001_sylius_ai_assistant_lookup_email_failure',
        ], array_keys($framework['rate_limiter']));
        foreach ($framework['rate_limiter'] as $limiter) {
            $this->assertArrayHasKey('lock_factory', $limiter);
            $this->assertNull($limiter['lock_factory']);
        }
        $this->assertArrayHasKey(Agent001SyliusAiAssistantExtension::COMMAND_BUS, $framework['messenger']['buses']);

        $hooks = array_merge_recursive(...$container->getExtensionConfig('sylius_twig_hooks'))['hooks'];
        $this->assertSame(
            '@Agent001SyliusAiAssistantPlugin/admin/settings/content.html.twig',
            $hooks['agent001_sylius_ai_assistant_admin.settings.content']['settings']['template'],
        );
        $this->assertSame(
            '@Agent001SyliusAiAssistantPlugin/shop/widget.html.twig',
            $hooks['sylius_shop.base#javascripts']['agent001_sylius_ai_assistant_chat_widget']['template'],
        );
    }

    public function testItRegistersMigrationsUnderOwnNamespaceNextToApplicationMigrations(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineMigrationsExtension());
        $container->registerExtension(new SyliusLabsDoctrineMigrationsExtraExtension());
        $container->loadFromExtension('doctrine_migrations', [
            'migrations_paths' => ['DoctrineMigrations' => '%kernel.project_dir%/migrations'],
        ]);

        (new Agent001SyliusAiAssistantExtension())->prepend($container);

        $paths = [];
        foreach ($container->getExtensionConfig('doctrine_migrations') as $config) {
            $paths += $config['migrations_paths'] ?? [];
        }

        $this->assertSame('%kernel.project_dir%/migrations', $paths['DoctrineMigrations']);
        $this->assertSame('@Agent001SyliusAiAssistantPlugin/src/Migrations', $paths[self::MIGRATIONS_NAMESPACE]);
        $this->assertSame(
            [self::MIGRATIONS_NAMESPACE => ['Sylius\Bundle\CoreBundle\Migrations']],
            $container->getExtensionConfig('sylius_labs_doctrine_migrations_extra')[0]['migrations'],
        );
    }

    public function testEveryMigrationIsAutoloadableFromItsNamespace(): void
    {
        $files = glob(__DIR__ . '/../../../src/Migrations/Version*.php');
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $class = self::MIGRATIONS_NAMESPACE . '\\' . basename($file, '.php');
            $this->assertTrue(class_exists($class), $class);
            $this->assertTrue(is_subclass_of($class, AbstractMigration::class), $class);
        }
    }
}
