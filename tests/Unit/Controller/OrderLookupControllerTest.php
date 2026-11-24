<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Unit\Controller;

use Agent001\SyliusAiAssistantPlugin\Client\Agent001Client;
use Agent001\SyliusAiAssistantPlugin\Controller\Api\OrderLookupController;
use Agent001\SyliusAiAssistantPlugin\Entity\Agent001Setting;
use Agent001\SyliusAiAssistantPlugin\Order\OrderLookupAuthorizer;
use Agent001\SyliusAiAssistantPlugin\Order\OrderLookupCors;
use Agent001\SyliusAiAssistantPlugin\Order\OrderLookupThrottle;
use Agent001\SyliusAiAssistantPlugin\Order\OrderSerializer;
use Agent001\SyliusAiAssistantPlugin\Order\TrackingResolver;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001ConnectionRepository;
use Agent001\SyliusAiAssistantPlugin\Repository\Agent001SettingRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class OrderLookupControllerTest extends TestCase
{
    private const WIDGET_KEY = 'valid_test_widget_key_32bytes_hex_abc';

    private OrderRepositoryInterface $orderRepository;

    private Agent001ConnectionRepository $connectionRepository;

    private ChannelInterface $channel;

    private ChannelContextInterface $channelContext;

    private OrderLookupAuthorizer $authorizer;

    private OrderLookupController $controller;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->connectionRepository = $this->createMock(Agent001ConnectionRepository::class);
        $this->connectionRepository->method('apiKey')->willReturn('valid_test_backend_api_key');

        $this->channel = $this->createMock(ChannelInterface::class);
        $this->channel->method('getCode')->willReturn('WEB');
        $this->channelContext = $this->createMock(ChannelContextInterface::class);
        $this->channelContext->method('getChannel')->willReturn($this->channel);

        $setting = new Agent001Setting('WEB');
        $setting->assignAgent('0198a6c2-1b3e-7c11-9d2a-3f4b5c6d7e8f', 'token', true, 'https://shop.test');
        (new \ReflectionProperty(Agent001Setting::class, 'widgetKey'))->setValue($setting, self::WIDGET_KEY);

        $settingRepository = $this->createMock(Agent001SettingRepository::class);
        $settingRepository->method('findOneByChannelCode')->with('WEB')->willReturn($setting);

        $this->authorizer = new OrderLookupAuthorizer($this->connectionRepository, $settingRepository);
        $this->controller = $this->controller(apiLimit: 1000);
    }

    public function testItRateLimitsWidget(): void
    {
        $this->orderRepository->method('findOneBy')->willReturn(null);
        $controller = $this->controller(apiLimit: 2);
        $lookup = static function (string $ip) use ($controller): Response {
            $request = Request::create(
                '/agent001/v1/orders/100',
                parameters: ['email' => 'client@example.com'],
                server: ['REMOTE_ADDR' => $ip],
            );
            $request->headers->set('X-Agent001-Auth', self::WIDGET_KEY);

            return $controller($request, '100');
        };

        $this->assertSame(Response::HTTP_NOT_FOUND, $lookup('203.0.113.1')->getStatusCode());
        $this->assertSame(Response::HTTP_NOT_FOUND, $lookup('203.0.113.1')->getStatusCode());
        $limited = $lookup('203.0.113.1');

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $limited->getStatusCode());
        $this->assertSame('60', $limited->headers->get('Retry-After'));
        $this->assertSame('agent001_rate_limited', json_decode((string) $limited->getContent(), true)['code'] ?? null);
        $this->assertSame(Response::HTTP_NOT_FOUND, $lookup('203.0.113.2')->getStatusCode());
    }

    public function testBackendRequestsAreNotRateLimited(): void
    {
        $this->orderRepository->method('findOneBy')->willReturn(null);
        $controller = $this->controller(apiLimit: 1);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $request = Request::create('/agent001/v1/orders/100', parameters: ['email' => 'client@example.com']);
            $request->headers->set('X-Agent001-Auth', 'valid_test_backend_api_key');
            $this->assertSame(Response::HTTP_NOT_FOUND, $controller($request, '100')->getStatusCode());
        }
    }

    public function testItRejectsUnauthorizedRequest(): void
    {
        $request = Request::create('/agent001/v1/orders/100', 'GET', ['email' => 'client@example.com']);
        $response = ($this->controller)($request, '100');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testItRejectsMissingEmail(): void
    {
        $request = Request::create('/agent001/v1/orders/100', 'GET');
        $request->headers->set('X-Agent001-Auth', self::WIDGET_KEY);

        $response = ($this->controller)($request, '100');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testItReturnsNotFoundForNonExistentOrder(): void
    {
        $request = Request::create('/agent001/v1/orders/999', 'GET', ['email' => 'client@example.com']);
        $request->headers->set('X-Agent001-Auth', self::WIDGET_KEY);

        $this->orderRepository->method('findOneBy')
            ->with(['number' => '999', 'channel' => $this->channel])
            ->willReturn(null);

        $response = ($this->controller)($request, '999');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testItRejectsMismatchedCustomerEmail(): void
    {
        $request = Request::create('/agent001/v1/orders/100', 'GET', ['email' => 'hacker@example.com']);
        $request->headers->set('X-Agent001-Auth', self::WIDGET_KEY);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('real_customer@example.com');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);

        $this->orderRepository->method('findOneBy')
            ->with(['number' => '100', 'channel' => $this->channel])
            ->willReturn($order);

        $response = ($this->controller)($request, '100');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testItReturnsCORSHeadersOnOptionsPreflight(): void
    {
        $request = Request::create('http://shop.test/agent001/v1/orders/100', 'OPTIONS');
        $request->headers->set('Origin', 'http://shop.test');

        $response = ($this->controller)($request, '100');

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->assertSame('http://shop.test', $response->headers->get('Access-Control-Allow-Origin'));
        $allowedHeaders = (string) $response->headers->get('Access-Control-Allow-Headers');
        $this->assertStringContainsString('X-Agent001-Auth', $allowedHeaders);
        $this->assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }

    public function testItDoesNotAllowForeignOrigin(): void
    {
        $request = Request::create('http://shop.test/agent001/v1/orders/100', 'OPTIONS');
        $request->headers->set('Origin', 'https://evil.example');

        $response = ($this->controller)($request, '100');

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testItBlocksWidgetAfterRepeatedFailures(): void
    {
        $this->orderRepository->method('findOneBy')->willReturn(null);

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $request = Request::create('/agent001/v1/orders/100', 'GET', ['email' => 'client@example.com']);
            $request->headers->set('X-Agent001-Auth', self::WIDGET_KEY);
            $this->assertSame(Response::HTTP_NOT_FOUND, ($this->controller)($request, '100')->getStatusCode());
        }

        $request = Request::create('/agent001/v1/orders/100', 'GET', ['email' => 'client@example.com']);
        $request->headers->set('X-Agent001-Auth', self::WIDGET_KEY);
        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, ($this->controller)($request, '100')->getStatusCode());
    }

    public function testFailuresFromOneIpDoNotLockTheEmailForOthers(): void
    {
        $this->orderRepository->method('findOneBy')->willReturn(null);
        $lookup = function (string $ip, string $number): int {
            $request = Request::create(
                '/agent001/v1/orders/' . $number,
                parameters: ['email' => 'client@example.com'],
                server: ['REMOTE_ADDR' => $ip],
            );
            $request->headers->set('X-Agent001-Auth', self::WIDGET_KEY);

            return ($this->controller)($request, $number)->getStatusCode();
        };

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->assertSame(Response::HTTP_NOT_FOUND, $lookup('10.0.0.1', (string) $attempt));
        }
        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $lookup('10.0.0.1', '5'));

        $this->assertSame(Response::HTTP_NOT_FOUND, $lookup('10.0.0.2', '6'));
        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $lookup('10.0.0.3', '7'));
    }

    public function testItAcceptsBackendApiKeyAndEmailInJsonBody(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('customer@example.com');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('100');
        $order->method('getState')->willReturn(OrderInterface::STATE_NEW);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getTotal')->willReturn(1000);
        $order->method('getShipments')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $order->method('getPayments')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $order->method('getItems')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $this->orderRepository->method('findOneBy')->with(['number' => '100'])->willReturn($order);

        $request = Request::create(
            '/agent001/v1/orders/100',
            Request::METHOD_POST,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"email":"Customer@Example.com"}',
        );
        $request->headers->set('X-Agent001-Auth', 'valid_test_backend_api_key');

        $response = ($this->controller)($request, '100');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('100', $data['order_number']);
        $this->assertSame([], $data['tracking']);
    }

    public function testItReturnsOrderDetailsOnSuccess(): void
    {
        $request = Request::create('/agent001/v1/orders/100', 'GET', ['email' => 'customer@example.com']);
        $request->headers->set('X-Agent001-Auth', self::WIDGET_KEY);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('customer@example.com');

        $orderItem = $this->createMock(\Sylius\Component\Core\Model\OrderItemInterface::class);
        $orderItem->method('getProductName')->willReturn('Test Product');
        $orderItem->method('getQuantity')->willReturn(2);
        $orderItem->method('getUnitPrice')->willReturn(2500);
        $orderItem->method('getTotal')->willReturn(5000);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('100');
        $order->method('getState')->willReturn(OrderInterface::STATE_FULFILLED);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getCurrencyCode')->willReturn('USD');
        $order->method('getTotal')->willReturn(5000);
        $order->method('getShipments')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $order->method('getPayments')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $order->method('getItems')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$orderItem]));

        $this->orderRepository->method('findOneBy')
            ->with(['number' => '100', 'channel' => $this->channel])
            ->willReturn($order);

        $response = ($this->controller)($request, '100');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        /**
         * @var array{
         *     order_number: string,
         *     total: string,
         *     items: list<array{name: string, quantity: int, subtotal: string, total: string}>,
         * } $data
         */
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame('100', $data['order_number']);
        $this->assertSame('50.00', $data['total']);
        $this->assertCount(1, $data['items']);
        $this->assertSame('50.00', $data['items'][0]['subtotal']);
        $this->assertSame('50.00', $data['items'][0]['total']);
    }

    private function controller(int $apiLimit): OrderLookupController
    {
        return new OrderLookupController(
            orderRepository: $this->orderRepository,
            channelContext: $this->channelContext,
            authorizer: $this->authorizer,
            throttle: new OrderLookupThrottle(
                anonymousApiLimiter: $this->limiter('api', $apiLimit),
                failureLimiter: $this->limiter('failures', 5),
                emailFailureLimiter: $this->limiter('email_failures', 6),
            ),
            cors: new OrderLookupCors(
                new Agent001Client(new MockHttpClient(), 'https://app.agent001.ai', null, new NullLogger()),
                [],
            ),
            orderSerializer: new OrderSerializer(new TrackingResolver()),
        );
    }

    private function limiter(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }
}
