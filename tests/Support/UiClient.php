<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * A browser for the /ui tests: real HTTP against Apache + php-fpm on the staging box (the
 * name-based vhost cw-ui.staging.invalid on 127.0.0.1:8080), with a cookie jar and no automatic
 * redirects (a test asserts each 303 itself, then calls follow()).
 */
final class UiClient
{
    /** @var array<string, string> name => value */
    public array $cookies = [];

    public function __construct(private readonly string $base, private readonly string $host)
    {
    }

    /** @param array<string, scalar|null> $query */
    public function get(string $path, array $query = [], array $headers = []): UiResponse
    {
        return $this->send('GET', $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)), null, $headers);
    }

    /** @param array<string, string> $form @param array<string, string|null> $headers */
    public function post(string $path, array $form, array $headers = []): UiResponse
    {
        return $this->send('POST', $path, http_build_query($form, '', '&', PHP_QUERY_RFC3986), $headers + ['Content-Type' => 'application/x-www-form-urlencoded']);
    }

    /** GET the Location of a redirect. */
    public function follow(UiResponse $r): UiResponse
    {
        $to = $r->location();
        if ($to === null) {
            throw new \LogicException('not a redirect: ' . $r->describe());
        }
        return $this->get($to);
    }

    /** @param array<string, string|null> $headers null values are not sent */
    public function send(string $method, string $target, ?string $body, array $headers = []): UiResponse
    {
        $ch = curl_init(rtrim($this->base, '/') . $target);
        $list = ['Host: ' . $this->host, 'Expect:'];
        foreach ($headers as $k => $v) {
            if ($v !== null) {
                $list[] = $k . ': ' . $v;
            }
        }
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = $k . '=' . $v;
            }
            $list[] = 'Cookie: ' . implode('; ', $pairs);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $list,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PATH_AS_IS => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        if (!is_string($out)) {
            throw new \RuntimeException('HTTP call failed: ' . curl_error($ch) . ' (is the UI vhost installed? deploy/staging/install_ui.sh)');
        }
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headers = [];
        foreach (preg_split('/\r\n/', substr($out, 0, $size)) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))][] = trim($v);
            }
        }
        $response = new UiResponse($status, $headers, substr($out, $size));
        $this->remember($response);
        return $response;
    }

    private function remember(UiResponse $r): void
    {
        foreach ($r->headerValues('set-cookie') as $line) {
            $parts = array_map('trim', explode(';', $line));
            [$name, $value] = array_pad(explode('=', array_shift($parts), 2), 2, '');
            $gone = $value === '';
            foreach ($parts as $p) {
                if (strcasecmp($p, 'Max-Age=0') === 0) {
                    $gone = true;
                }
            }
            if ($gone) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
    }
}
