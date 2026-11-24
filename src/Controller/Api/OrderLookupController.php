<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Controller\Api;

use Agent001\SyliusAiAssistantPlugin\Order\OrderLookupAuthorizer;
use Agent001\SyliusAiAssistantPlugin\Order\OrderLookupCors;
use Agent001\SyliusAiAssistantPlugin\Order\OrderLookupThrottle;
use Agent001\SyliusAiAssistantPlugin\Order\OrderSerializerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderLookupController
{
    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly OrderLookupAuthorizer $authorizer,
        private readonly OrderLookupThrottle $throttle,
        private readonly OrderLookupCors $cors,
        private readonly OrderSerializerInterface $orderSerializer,
    ) {
    }

    public function __invoke(Request $request, string $number): Response
    {
        if (Request::METHOD_OPTIONS === $request->getMethod()) {
            return $this->cors->apply(new Response('', Response::HTTP_NO_CONTENT), $request);
        }

        $channel = $this->channel();
        $access = $this->authorizer->authorize($request, $channel);
        if (null === $access) {
            return $this->error($request, 'agent001_unauthorized', 'Invalid auth header.', Response::HTTP_UNAUTHORIZED);
        }

        $isWidget = OrderLookupAuthorizer::WIDGET === $access;
        $scope = (string) $channel?->getCode();
        $ip = (string) ($request->getClientIp() ?: '127.0.0.1');
        if ($isWidget && !$this->throttle->acceptRequest($ip)) {
            return $this->rateLimited($request);
        }

        $email = $this->extractEmail($request);
        if (empty($email)) {
            return $this->error(
                $request,
                'agent001_missing_email',
                'Missing email parameter.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($isWidget && $this->throttle->isBlocked($scope, $ip, $email, $number)) {
            return $this->rateLimited($request);
        }

        $order = $this->findOrder($number, $email, $isWidget ? $channel : null);
        if (null === $order) {
            if ($isWidget) {
                $this->throttle->recordFailure($scope, $ip, $email, $number);
            }

            return $this->error($request, 'agent001_order_not_found', 'Order not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->cors->apply(new JsonResponse($this->orderSerializer->serialize($order)), $request);
    }

    private function channel(): ?ChannelInterface
    {
        try {
            return $this->channelContext->getChannel();
        } catch (ChannelNotFoundException) {
            return null;
        }
    }

    private function findOrder(string $number, string $email, ?ChannelInterface $channel): ?OrderInterface
    {
        $criteria = ['number' => $number];
        if (null !== $channel) {
            $criteria['channel'] = $channel;
        }

        $order = $this->orderRepository->findOneBy($criteria);
        $customerEmail = $order?->getCustomer()?->getEmail();
        if (null === $customerEmail || !hash_equals(mb_strtolower($email), mb_strtolower(trim($customerEmail)))) {
            return null;
        }

        return $order;
    }

    private function extractEmail(Request $request): string
    {
        $email = '';
        if (Request::METHOD_POST === $request->getMethod()) {
            $body = json_decode($request->getContent(), true);
            if (is_array($body) && is_string($body['email'] ?? null)) {
                $email = $body['email'];
            } else {
                $email = (string) $request->request->get('email', '');
            }
        }

        if (empty(trim($email))) {
            $email = (string) $request->query->get('email', '');
        }

        return trim($email);
    }

    private function rateLimited(Request $request): Response
    {
        $response = $this->error(
            $request,
            'agent001_rate_limited',
            'Too many requests, slow down.',
            Response::HTTP_TOO_MANY_REQUESTS,
        );
        $response->headers->set('Retry-After', '60');

        return $response;
    }

    private function error(Request $request, string $code, string $message, int $status): Response
    {
        return $this->cors->apply(new JsonResponse(['code' => $code, 'message' => $message], $status), $request);
    }
}
