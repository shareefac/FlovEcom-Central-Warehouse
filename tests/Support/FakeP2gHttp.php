<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Profit\P2gHttp;

/** Parcel2Go's API from scripted answers: $route(method, path) gives [status, body array|string]; every request is recorded. */
final class FakeP2gHttp implements P2gHttp
{
    /** @var list<array{method: string, path: string, auth: bool, body: ?string}> */
    public array $requests = [];

    /** @param \Closure(string, string): array{0: int, 1: mixed} $route */
    public function __construct(private readonly \Closure $route)
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSec, int $maxBytes): array
    {
        $path = (string) preg_replace('#^https://www\.parcel2go\.com#', '', $url);
        $this->requests[] = ['method' => $method, 'path' => $path, 'auth' => in_array(true, array_map(static fn (string $h): bool => str_starts_with($h, 'Authorization: Bearer '),
            $headers), true), 'body' => $body];
        if ($method === 'POST' && $path === '/auth/connect/token') {
            return ['status' => 200, 'body' => json_encode(['access_token' => 'test.token.abcdefghij', 'expires_in' => 7200, 'token_type' => 'Bearer']), 'headers' => []];
        }
        [$status, $answer] = ($this->route)($method, $path);
        return ['status' => $status, 'body' => is_string($answer) ? $answer : (string) json_encode($answer), 'headers' => []];
    }
}

