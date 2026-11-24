<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Behat\Page\Admin;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;

final class SettingsPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'agent001_sylius_ai_assistant_admin_settings';
    }

    public function getCurrentStep(): string
    {
        return trim($this->getElement('current_step')->getText());
    }

    public function canConnect(): bool
    {
        return $this->hasElement('connect_button');
    }

    public function connect(): void
    {
        $this->getElement('connect_button')->press();
    }

    public function chooseAgent(string $channelCode, string $agentName): void
    {
        $this->getElement('agent', ['%channel_code%' => $channelCode])->selectOption($agentName);
    }

    public function saveAgents(): void
    {
        $this->getElement('save_agents_button')->press();
    }

    public function getChosenAgent(string $channelCode): string
    {
        $option = $this->getElement('agent', ['%channel_code%' => $channelCode])->find('css', 'option[selected]');

        return null === $option ? '' : trim($option->getText());
    }

    public function getSyncStatus(string $channelCode): string
    {
        return trim($this->getElement('sync_status', ['%channel_code%' => $channelCode])->getText());
    }

    public function hasMessage(string $message): bool
    {
        return str_contains($this->getDocument()->getText(), $message);
    }

    /**
     * @return array<string, string>
     */
    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'agent' => 'select[name="agent[%channel_code%]"]',
            'connect_button' => 'form[action$="/agent001/connect"] button[type="submit"]',
            'current_step' => '[data-agent001-getting-started] .step-item.active',
            'save_agents_button' => 'form[action$="/agent001/agents"] button[type="submit"]',
            'sync_status' => '[data-agent001-status="%channel_code%"]',
        ]);
    }
}
