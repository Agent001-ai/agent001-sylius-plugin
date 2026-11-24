<?php

declare(strict_types=1);

namespace Tests\Agent001\SyliusAiAssistantPlugin\Http;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class FakeAgent001Backend
{
    public const BASE_URL = 'https://app.agent001.ai';

    public const API_KEY = 'test-api-key';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private static array $requests = [];

    /** @var array<string, array{int, string}> */
    private static array $responses = [];

    /** @var (\Closure(string, string): void)|null */
    private static ?\Closure $listener = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $headers = [];
        foreach ($options['headers'] ?? [] as $header) {
            if (is_string($header) && str_contains($header, ': ')) {
                [$name, $value] = explode(': ', $header, 2);
                $headers[strtolower($name)] = $value;
            }
        }
        $body = $options['body'] ?? '';
        self::$requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => is_string($body) ? $body : '',
        ];

        if (null !== self::$listener) {
            (self::$listener)($method, $url);
        }

        if (!str_starts_with($url, self::BASE_URL . '/')) {
            return new MockResponse('', ['http_code' => 404]);
        }

        [$status, $content] = self::$responses[self::call($method, $url)] ?? $this->defaultResponse($method, $url);

        return new MockResponse($content, [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    public static function reset(): void
    {
        self::$requests = [];
        self::$responses = [];
        self::$listener = null;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function respond(string $method, string $path, int $status, array $body = []): void
    {
        self::$responses[$method . ' ' . $path] = [$status, (string) json_encode($body)];
    }

    /**
     * @param (\Closure(string, string): void)|null $listener
     */
    public function onRequest(?\Closure $listener): void
    {
        self::$listener = $listener;
    }

    /**
     * @return list<array{method: string, url: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        return self::$requests;
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return array_map(
            static fn (array $request): string => self::call($request['method'], $request['url']),
            self::$requests,
        );
    }

    private static function call(string $method, string $url): string
    {
        return $method . ' ' . (string) parse_url($url, \PHP_URL_PATH);
    }

    /**
     * @return array{int, string}
     */
    private function defaultResponse(string $method, string $url): array
    {
        if ('POST' === $method && self::BASE_URL . '/connect/exchange' === $url) {
            return [200, (string) json_encode(['api_key' => self::API_KEY, 'account_name' => 'Test account'])];
        }

        return [200, '{}'];
    }
}
