<?php

declare(strict_types=1);

namespace CW\Invoices;

use CW\Config;
use CW\CwException;

/**
 * The read-only ERPNext client of the invoice copy job (CW-917; docs/decisions.md EC1): ERPNext's REST API with CW's OWN read-only API user
 * (never the shop's token), GET only, three reads and nothing else:
 *
 *   changed()   GET /api/resource/Purchase Invoice?filters=[company, docstatus in (1, 2), posting_date >= from, modified >= m]
 *               &or_filters=[modified > m, name > n]&fields=[name, modified]&order_by=modified asc, name asc, page by page by KEYSET: each page
 *               asks for what comes after the last (modified, name) read, so an invoice changed while the pages are read moves to the end and
 *               is read there, and none is skipped (an offset would skip one: the Codex review of 0ccc04c, MEDIUM 1)
 *   invoice()   GET /api/resource/Purchase Invoice/<name>: one invoice with its rows (items) and its taxes
 *   items()     GET /api/resource/Item?filters=[name in (...)]&fields=[name, is_stock_item, custom_variant_id]: which rows are stock (only
 *               those reach the relay) and their Vape and Go variant
 *
 * Never ERPNext's stock feed, never a method call, never a write. The address, the key and the secret come from /etc/cw/app.env
 * (erp_read_url, erp_read_key, erp_read_secret; or CW_ERP_READ_URL ...), parsed by CW\Config, never printed: an error names what failed
 * (the HTTP status, ERPNext's exception type), never the key, the secret or a header. The transport is injected (tests: fixtures).
 */
final class ErpApiReader
{
    public const DOCTYPE = 'Purchase Invoice';
    public const ITEM_DOCTYPE = 'Item';
    /** app.env keys (lower case; CW\Config also reads CW_ERP_READ_URL, ...). */
    public const CONFIG = ['url' => 'erp_read_url', 'key' => 'erp_read_key', 'secret' => 'erp_read_secret'];
    public const PAGE = 100;
    public const TIMEOUT = 30;
    public const ITEM_CHUNK = 100;
    /** The most invoices one listing reads (a run's --limit is lower). */
    public const MAX_LIST = 20_000;

    private readonly string $base;
    private readonly string $auth;

    public function __construct(private readonly ErpTransport $transport, string $baseUrl, string $key, #[\SensitiveParameter] string $secret,
        private readonly int $timeout = self::TIMEOUT, private readonly int $pageSize = self::PAGE)
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $p = parse_url($baseUrl);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || ($p['host'] ?? '') === '' || isset($p['user']) || isset($p['pass'])
            || isset($p['query']) || isset($p['fragment'])) {
            throw new CwException('erp_read_bad_url', 'the ERPNext address must be https://<server> (no user, no query)', 500);
        }
        if (preg_match('/^[A-Za-z0-9]{6,64}$/D', $key) !== 1 || preg_match('/^[A-Za-z0-9]{6,64}$/D', $secret) !== 1) {
            throw new CwException('erp_read_bad_key', 'the ERPNext API key and secret must be letters and digits (as ERPNext makes them)', 500);
        }
        if ($timeout < 1 || $timeout > 300 || $pageSize < 1 || $pageSize > 500) {
            throw new \InvalidArgumentException('timeout 1-300 seconds, page 1-500');
        }
        $this->base = $baseUrl;
        $this->auth = 'Authorization: token ' . $key . ':' . $secret;
    }

    /** Never show the secret (var_dump, print_r, a stack trace's arguments). @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['base' => $this->base, 'auth' => '(hidden)'];
    }

    /**
     * The reader app.env describes. 503 erp_read_unconfigured (naming the missing KEYS, never a value) when one of the three is missing.
     */
    public static function fromConfig(Config $config, ?ErpTransport $transport = null): self
    {
        $v = [];
        $missing = [];
        foreach (self::CONFIG as $k => $name) {
            $v[$k] = trim((string) ($config->get($name) ?? ''));
            if ($v[$k] === '') {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            throw new CwException('erp_read_unconfigured', 'the ERPNext read-only user is not set up on this server (missing in app.env: ' . implode(', ', $missing) . ')', 503,
                ['missing' => $missing]);
        }
        return new self($transport ?? new ErpHttpTransport(), $v['url'], $v['key'], $v['secret']);
    }

    /**
     * The purchase invoices of $company submitted or cancelled, posted on or after $fromDate and (when $after is given) changed after it:
     * after the (modified, name) a run read last (ERPNext's modified time as it gave it, and the name: the order of the list), oldest change
     * first, at most $limit. One invoice changed while the pages are read comes once, at its newest place.
     *
     * @param array{0: string, 1: string}|null $after [modified, name]
     * @return list<array{name: string, modified: string}>
     */
    public function changed(string $company, string $fromDate, ?array $after, ?int $limit = null): array
    {
        $max = min($limit ?? self::MAX_LIST, self::MAX_LIST);
        $out = [];
        while (count($out) < $max) {
            $filters = [['company', '=', $company], ['docstatus', 'in', [1, 2]], ['posting_date', '>=', $fromDate]];
            $query = ['fields' => ['name', 'modified'], 'order_by' => 'modified asc, name asc', 'limit_start' => 0, 'limit_page_length' => $this->pageSize];
            if ($after !== null) {
                $filters[] = ['modified', '>=', $after[0]];
                $query['or_filters'] = [['modified', '>', $after[0]], ['name', '>', $after[1]]];
            }
            $page = $this->get('/api/resource/' . rawurlencode(self::DOCTYPE), ['filters' => $filters] + $query);
            if (!is_array($page) || !array_is_list($page)) {
                throw new CwException('erp_read_failed', 'ERPNext\'s list of purchase invoices is not a list', 502);
            }
            $new = 0;
            foreach ($page as $r) {
                if (!is_array($r) || !is_string($r['name'] ?? null) || $r['name'] === '' || !is_string($r['modified'] ?? null)) {
                    throw new CwException('erp_read_failed', 'ERPNext listed a purchase invoice without its name or time', 502);
                }
                $row = ['name' => $r['name'], 'modified' => self::modified($r['modified'])];
                if ($after !== null && $row['modified'] < self::modified($after[0])) {
                    throw new CwException('erp_read_failed', 'ERPNext listed a purchase invoice out of order', 502);
                }
                // An invoice changed since an earlier page comes again, at its newest place: kept there only.
                $new += in_array($row, $out, true) ? 0 : 1;
                $out = array_values(array_filter($out, static fn (array $o): bool => $o['name'] !== $row['name']));
                $out[] = $row;
                $after = [$row['modified'], $row['name']];
                if (count($out) >= $max) {
                    break;
                }
            }
            if ($page !== [] && $new === 0) {
                // The same page again: this ERPNext ignores what comes after (or_filters). Never loop: stop and say so.
                throw new CwException('erp_read_failed', 'ERPNext gave the same page twice (it did not take the filter of what comes after)', 502);
            }
            if (count($page) < $this->pageSize) {
                break;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> one purchase invoice as ERPNext gives it (its items and taxes inside) */
    public function invoice(string $name): array
    {
        $doc = $this->get('/api/resource/' . rawurlencode(self::DOCTYPE) . '/' . rawurlencode($name), []);
        if (!is_array($doc) || ($doc['name'] ?? null) !== $name || !is_array($doc['items'] ?? null)) {
            throw new CwException('erp_read_failed', "ERPNext's purchase invoice {$name} came back without its rows", 502);
        }
        return $doc;
    }

    /**
     * Whether these ERPNext items are stock items and their Vape and Go variant: code => [is_stock_item, custom_variant_id]. An item
     * ERPNext does not return is left out.
     *
     * @param list<string> $codes
     * @return array<string, array{is_stock_item: bool, custom_variant_id: ?string}>
     */
    public function items(array $codes): array
    {
        // A numeric item code may come as an int (a PHP array key): it is still a code (the Codex review of 0ccc04c, MEDIUM 2).
        $codes = array_values(array_unique(array_filter(array_map(static fn (mixed $c): string => is_scalar($c) ? (string) $c : '', $codes),
            static fn (string $c): bool => $c !== '')));
        $out = [];
        foreach (array_chunk($codes, self::ITEM_CHUNK) as $chunk) {
            $rows = $this->get('/api/resource/' . rawurlencode(self::ITEM_DOCTYPE), ['filters' => [['name', 'in', $chunk]],
                'fields' => ['name', 'is_stock_item', 'custom_variant_id'], 'limit_page_length' => count($chunk)]);
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new CwException('erp_read_failed', 'ERPNext\'s list of items is not a list', 502);
            }
            foreach ($rows as $r) {
                if (!is_array($r) || !is_scalar($r['name'] ?? null)) {
                    continue;
                }
                $variant = $r['custom_variant_id'] ?? null;
                $out[(string) $r['name']] = ['is_stock_item' => (int) ($r['is_stock_item'] ?? 0) === 1,
                    'custom_variant_id' => is_scalar($variant) && trim((string) $variant) !== '' ? mb_substr(trim((string) $variant), 0, 64) : null];
            }
        }
        return $out;
    }

    /** ERPNext's modified time as stored ("2026-11-04 10:15:23.123456"), checked. */
    public static function modified(string $v): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(\.\d{1,6})?$/D', trim($v), $m) !== 1) {
            throw new CwException('erp_read_failed', 'ERPNext gave a time that is not one', 502);
        }
        return $m[1] . str_pad($m[2] ?? '.', 7, '0');
    }

    /**
     * One GET; returns ERPNext's `data`. 502 erp_read_failed naming the status and ERPNext's exception type (never the address's secret
     * parts: there are none in it, the key travels in a header).
     *
     * @param array<string, mixed> $query
     */
    private function get(string $path, array $query): mixed
    {
        $q = [];
        foreach ($query as $k => $v) {
            $q[$k] = is_array($v) ? json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $v;
        }
        $url = $this->base . $path . ($q === [] ? '' : '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986));
        try {
            $r = $this->transport->get($url, [$this->auth, 'Accept: application/json'], $this->timeout);
        } catch (\RuntimeException $e) {
            throw new CwException('erp_read_failed', mb_substr($e->getMessage(), 0, 200), 502);
        }
        $body = json_decode($r['body'], true, 64);
        if ($r['status'] !== 200 || !is_array($body) || !array_key_exists('data', $body)) {
            $type = is_array($body) && is_string($body['exc_type'] ?? null) ? ' (' . mb_substr($body['exc_type'], 0, 60) . ')' : '';
            throw new CwException('erp_read_failed', "ERPNext answered HTTP {$r['status']}{$type}" . ($r['status'] === 403 || $r['status'] === 401
                ? ': check the read-only user and its rights' : ''), 502, ['status' => $r['status']]);
        }
        return $body['data'];
    }
}
