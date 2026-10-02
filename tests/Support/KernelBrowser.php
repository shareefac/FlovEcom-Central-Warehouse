<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Ui\Kernel;
use CW\Ui\UiRequest;

/**
 * A browser that drives the real /ui kernel (CW\Ui\Kernel: routing, sessions, CSRF, roles,
 * controllers, templates) in-process, as the app login against this slot's test schema. Same
 * cookie jar rules as tests/Support/UiClient (which needs the slot-ui vhost), no automatic redirects.
 */
final class KernelBrowser
{
    /** @var array<string, string> */
    public array $cookies = [];

    private const HEADERS = ['content-type', 'location', 'set-cookie', 'content-security-policy', 'x-content-type-options',
        'x-frame-options', 'referrer-policy', 'cache-control', 'retry-after', 'strict-transport-security', 'x-request-id', 'content-disposition'];

    public function __construct(private readonly Kernel $kernel, public string $ip = '198.51.100.20', private readonly string $host = 'cw-ui.review.invalid')
    {
    }

    /** @param array<string, scalar|null> $query */
    public function get(string $path, array $query = []): UiResponse
    {
        return $this->send('GET', $path, array_map(static fn (mixed $v): string => (string) $v, array_filter($query, static fn (mixed $v): bool => $v !== null)), []);
    }

    /** @param array<string, string> $form @param array<string, string> $headers */
    public function post(string $path, array $form, array $headers = []): UiResponse
    {
        return $this->send('POST', $path, [], $form, $headers + ['content-type' => 'application/x-www-form-urlencoded']);
    }

    public function follow(UiResponse $r): UiResponse
    {
        $to = $r->location() ?? throw new \LogicException('not a redirect: ' . $r->describe());
        $path = (string) parse_url($to, PHP_URL_PATH);
        parse_str((string) parse_url($to, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        return $this->send('GET', $path, $query, []);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $post
     * @param array<string, string> $headers
     */
    public function send(string $method, string $path, array $query, array $post, array $headers = []): UiResponse
    {
        $h = ['host' => $this->host] + array_change_key_case($headers, CASE_LOWER);
        $res = $this->kernel->handle(new UiRequest($method, $path, $query, $post, $this->cookies, $h, $this->ip, false));
        $out = [];
        foreach (self::HEADERS as $name) {
            $v = $res->headerValues($name);
            if ($v !== []) {
                $out[$name] = $v;
            }
        }
        $r = new UiResponse($res->status, $out, $res->body);
        foreach ($r->headerValues('set-cookie') as $line) {
            $parts = array_map('trim', explode(';', $line));
            [$name, $value] = array_pad(explode('=', (string) array_shift($parts), 2), 2, '');
            $gone = $value === '' || in_array('Max-Age=0', $parts, true);
            if ($gone) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
        return $r;
    }
}
