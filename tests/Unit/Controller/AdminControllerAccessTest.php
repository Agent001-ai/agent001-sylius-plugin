<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Controller;

use Agent001\SyliusAiAssistantPlugin\Controller\Admin\ConnectController;
use Agent001\SyliusAiAssistantPlugin\Controller\Admin\SettingsController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AdminControllerAccessTest extends TestCase
{
    /**
     * @param class-string $controller
     */
    #[DataProvider('adminControllers')]
    public function testEveryActionRequiresAdministrationAccess(string $controller): void
    {
        $attributes = (new \ReflectionClass($controller))->getAttributes(IsGranted::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('ROLE_ADMINISTRATION_ACCESS', $attributes[0]->newInstance()->attribute);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function adminControllers(): iterable
    {
        yield 'settings' => [SettingsController::class];
        yield 'connect' => [ConnectController::class];
    }
}
