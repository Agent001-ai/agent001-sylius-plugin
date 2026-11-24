<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Behat\Context\Api;

use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Behat\Behat\Context\Context;
use Behat\Mink\Driver\BrowserKitDriver;
use Behat\Mink\Session;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Behat\Service\SharedStorageInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

final class OrderLookupContext implements Context
{
    private string $orderNumber = '';

    private string $email = '';

    public function __construct(
        private readonly Session $session,
        private readonly SharedStorageInterface $sharedStorage,
    ) {
    }

    #[When('the chat widget asks for the status of the order :orderNumber placed with :email')]
    public function theChatWidgetAsksForTheStatusOfTheOrder(string $orderNumber, string $email): void
    {
        $setting = $this->sharedStorage->get('agent001_setting');
        Assert::isInstanceOf($setting, Agent001Setting::class);
        $this->orderNumber = $orderNumber;
        $this->email = $email;

        $this->client()->request(
            method: 'POST',
            uri: '/agent001/v1/orders/' . rawurlencode($orderNumber),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_AGENT001_AUTH' => $setting->getWidgetKey(),
                'REMOTE_ADDR' => sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254)),
            ],
            content: (string) json_encode(['email' => $email]),
        );
    }

    #[Then('the order status should be returned')]
    public function theOrderStatusShouldBeReturned(): void
    {
        $response = $this->response();
        Assert::same($response->getStatusCode(), Response::HTTP_OK);
        Assert::contains((string) $response->headers->get('Cache-Control'), 'no-store');

        $order = $this->body();
        Assert::same($order['order_number'] ?? null, $this->orderNumber);
        Assert::stringNotEmpty($order['status'] ?? null);
    }

    #[Then('the response should not reveal the customer')]
    public function theResponseShouldNotRevealTheCustomer(): void
    {
        Assert::notContains((string) $this->response()->getContent(), $this->email);
    }

    #[Then('the order should not be found')]
    public function theOrderShouldNotBeFound(): void
    {
        Assert::same($this->response()->getStatusCode(), Response::HTTP_NOT_FOUND);
        Assert::same($this->body()['code'] ?? null, 'agent001_order_not_found');
    }

    private function response(): Response
    {
        $response = $this->client()->getResponse();
        Assert::isInstanceOf($response, Response::class);

        return $response;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function body(): array
    {
        $body = json_decode((string) $this->response()->getContent(), true);
        Assert::isArray($body);

        return $body;
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
