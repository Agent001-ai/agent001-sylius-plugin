<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Order;

use Agent001\SyliusAiAssistantPlugin\Client\Agent001ClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderLookupCors
{
    private const ALLOWED_HEADERS = OrderLookupAuthorizer::AUTH_HEADER . ', Content-Type, Accept';

    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        private readonly Agent001ClientInterface $client,
        private readonly array $allowedOrigins,
    ) {
    }

    public function apply(Response $response, Request $request): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Vary', 'Origin');

        $origin = rtrim((string) $request->headers->get('Origin', ''), '/');
        if (empty($origin) || !in_array($origin, $this->allowedOrigins($request), true)) {
            return $response;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(Request $request): array
    {
        $origins = [rtrim($request->getSchemeAndHttpHost(), '/'), rtrim($this->client->getBaseUrl(), '/')];
        foreach ($this->allowedOrigins as $origin) {
            if (!empty(trim($origin))) {
                $origins[] = rtrim($origin, '/');
            }
        }

        return array_values(array_unique($origins));
    }
}
