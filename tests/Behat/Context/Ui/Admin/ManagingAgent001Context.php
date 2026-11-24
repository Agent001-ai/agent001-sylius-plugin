<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Behat\Mink\Driver\BrowserKitDriver;
use Behat\Mink\Session;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tests\Agent001\SyliusAiAssistantPlugin\Behat\Page\Admin\SettingsPage;
use Tests\Agent001\SyliusAiAssistantPlugin\Http\FakeAgent001Backend;
use Webmozart\Assert\Assert;

final class ManagingAgent001Context implements Context
{
    /** @var array<string, string> */
    private array $authorization = [];

    public function __construct(
        private readonly SettingsPage $settingsPage,
        private readonly Session $session,
    ) {
    }

    #[When('I open the Agent001 assistant page')]
    public function iOpenTheAgent001AssistantPage(): void
    {
        $this->settingsPage->open();
    }

    #[When('I connect the store')]
    public function iConnectTheStore(): void
    {
        $client = $this->client();
        $client->followRedirects(false);
        $this->settingsPage->connect();
        $location = (string) $client->getResponse()->headers->get('Location');
        $client->followRedirects(true);

        Assert::startsWith($location, FakeAgent001Backend::BASE_URL . '/connect?');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        $this->authorization = array_filter($query, 'is_string');
    }

    #[When('Agent001 confirms the connection')]
    public function agent001ConfirmsTheConnection(): void
    {
        $this->session->visit(sprintf(
            '%s?%s',
            $this->authorization['return_to'] ?? '',
            http_build_query(['state' => $this->authorization['state'] ?? '', 'code' => 'behat-code']),
        ));
    }

    #[When('I assign the agent :agentName to the :channel channel')]
    public function iAssignTheAgentToTheChannel(string $agentName, ChannelInterface $channel): void
    {
        $this->settingsPage->chooseAgent((string) $channel->getCode(), $agentName);
    }

    #[When('I save the agents')]
    public function iSaveTheAgents(): void
    {
        $this->settingsPage->saveAgents();
    }

    #[Then('the getting started guide should point to :step')]
    public function theGettingStartedGuideShouldPointTo(string $step): void
    {
        Assert::same($this->settingsPage->getCurrentStep(), $step);
    }

    #[Then('I should be able to connect the store')]
    public function iShouldBeAbleToConnectTheStore(): void
    {
        Assert::true($this->settingsPage->canConnect());
    }

    #[Then('I should be redirected to Agent001 to authorize the store')]
    public function iShouldBeRedirectedToAgent001ToAuthorizeTheStore(): void
    {
        Assert::notEmpty($this->authorization['state'] ?? '');
        Assert::endsWith($this->authorization['return_to'] ?? '', '/admin/agent001/callback');
    }

    #[Then('I should be notified that the store has been connected')]
    public function iShouldBeNotifiedThatTheStoreHasBeenConnected(): void
    {
        Assert::true($this->settingsPage->hasMessage('The store is connected to Agent001.'));
    }

    #[Then('I should be notified that the agents have been saved')]
    public function iShouldBeNotifiedThatTheAgentsHaveBeenSaved(): void
    {
        Assert::true($this->settingsPage->hasMessage('Agents have been saved.'));
    }

    #[Then('the :channel channel should use the agent :agentName')]
    public function theChannelShouldUseTheAgent(ChannelInterface $channel, string $agentName): void
    {
        Assert::same($this->settingsPage->getChosenAgent((string) $channel->getCode()), $agentName);
    }

    #[Then('the catalog synchronization of the :channel channel should be queued')]
    public function theCatalogSynchronizationOfTheChannelShouldBeQueued(ChannelInterface $channel): void
    {
        Assert::same($this->settingsPage->getSyncStatus((string) $channel->getCode()), 'Queued');
    }

    private function client(): KernelBrowser
    {
        $driver = $this->session->getDriver();
        Assert::isInstanceOf($driver, BrowserKitDriver::class);
        $client = $driver->getClient();
        Assert::isInstanceOf($client, KernelBrowser::class);

        return $client;
    }
}
