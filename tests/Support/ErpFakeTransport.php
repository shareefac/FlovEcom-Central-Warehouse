<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Invoices\ErpTransport;

/**
 * ERPNext as the tests see it (CW-917): the three reads ErpApiReader makes, answered from fixtures (no network in a test). The list of
 * purchase invoices applies the filters the reader sends (company, docstatus in, posting_date >=, modified >=), sorts by modified then name
 * and pages by limit_start / limit_page_length; an invoice is answered by its name (404 otherwise); the items by name. Every call is kept
 * (its address and headers) so a test can see what was asked and how.
 */
final class ErpFakeTransport implements ErpTransport
{
    /** @var array<string, array<string, mixed>> name => the Purchase Invoice as ERPNext gives it */
    public array $invoices = [];
    /** @var array<string, array{is_stock_item: int, custom_variant_id: ?string}> item code => its Item fields */
    public array $items = [];
    /** @var list<array{url: string, headers: list<string>, path: string, query: array<string, mixed>}> */
    public array $calls = [];
    /** A status to answer every call with (an ERPNext refusal), or null. */
    public ?int $status = null;

    public function get(string $url, array $headers, int $timeoutSec): array
    {
        $p = parse_url($url);
        parse_str((string) ($p['query'] ?? ''), $query);
        $path = rawurldecode((string) ($p['path'] ?? ''));
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'path' => $path, 'query' => $query];
        if ($this->status !== null) {
            return ['status' => $this->status, 'body' => json_encode(['exc_type' => 'PermissionError', 'exception' => 'not permitted'], JSON_THROW_ON_ERROR)];
        }
        if ($path === '/api/resource/Purchase Invoice') {
            $filters = json_decode((string) ($query['filters'] ?? '[]'), true);
            $rows = array_values(array_filter($this->invoices, static function (array $d) use ($filters): bool {
                foreach ($filters as [$field, $op, $value]) {
                    $v = $d[$field] ?? null;
                    $ok = match ($op) {
                        '=' => $v === $value,
                        'in' => in_array($v, $value, true),
                        '>=' => (string) $v >= (string) $value,
                        default => throw new \LogicException("filter {$op}"),
                    };
                    if (!$ok) {
                        return false;
                    }
                }
                return true;
            }));
            usort($rows, static fn (array $a, array $b): int => [$a['modified'], $a['name']] <=> [$b['modified'], $b['name']]);
            $page = array_slice($rows, (int) ($query['limit_start'] ?? 0), (int) ($query['limit_page_length'] ?? 20));
            return self::ok(array_map(static fn (array $d): array => ['name' => $d['name'], 'modified' => $d['modified']], $page));
        }
        if (str_starts_with($path, '/api/resource/Purchase Invoice/')) {
            $name = substr($path, strlen('/api/resource/Purchase Invoice/'));
            return isset($this->invoices[$name]) ? self::ok($this->invoices[$name])
                : ['status' => 404, 'body' => json_encode(['exc_type' => 'DoesNotExistError'], JSON_THROW_ON_ERROR)];
        }
        if ($path === '/api/resource/Item') {
            $filters = json_decode((string) ($query['filters'] ?? '[]'), true);
            $names = $filters[0][2] ?? [];
            $out = [];
            foreach ($names as $n) {
                if (isset($this->items[$n])) {
                    $out[] = ['name' => $n] + $this->items[$n];
                }
            }
            return self::ok($out);
        }
        return ['status' => 404, 'body' => '{}'];
    }

    /** @param mixed $data */
    private static function ok(mixed $data): array
    {
        return ['status' => 200, 'body' => json_encode(['data' => $data], JSON_THROW_ON_ERROR)];
    }
}
