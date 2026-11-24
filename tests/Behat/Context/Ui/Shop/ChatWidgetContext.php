<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Behat\Page\Shop\HomePageInterface;
use Tests\Agent001\SyliusAiAssistantPlugin\Http\FakeAgent001Backend;
use Webmozart\Assert\Assert;

final class ChatWidgetContext implements Context
{
    private const LOADER = FakeAgent001Backend::BASE_URL . '/embed/loader.js';

    public function __construct(
        private readonly HomePageInterface $homePage,
        private readonly Session $session,
    ) {
    }

    #[When('I open the storefront')]
    public function iOpenTheStorefront(): void
    {
        $this->homePage->open();
    }

    #[Then('I should see the Agent001 chat widget')]
    public function iShouldSeeTheAgent001ChatWidget(): void
    {
        $loader = $this->loader();
        Assert::notNull($loader);
        Assert::same($loader->getAttribute('data-token'), 'embed-token');
    }

    #[Then('I should not see the Agent001 chat widget')]
    public function iShouldNotSeeTheAgent001ChatWidget(): void
    {
        Assert::null($this->loader());
    }

    private function loader(): ?NodeElement
    {
        return $this->session->getPage()->find('css', sprintf('script[src="%s"]', self::LOADER));
    }
}
