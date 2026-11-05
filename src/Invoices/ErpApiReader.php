<?php

declare(strict_types=1);

namespace CW\Invoices;

use CW\Admin\ApiCredentials;
use CW\Config;
use CW\ConfigException;
use CW\CwException;
use CW\Db;
use CW\Staff\SecretBox;

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
 * Never ERPNext's stock feed, never a method call, never a write. The address, the key and the secret are the ones saved on Settings ›
 * APIs (Admin\ApiCredentials, sealed in the database; docs/decisions.md AK7) when there are any, else /etc/cw/app.env's (erp_read_url,
 * erp_read_key, erp_read_secret; or CW_ERP_READ_URL ...), parsed by CW\Config; never printed: an error names what failed (the HTTP status,
 * ERPNext's exception type), never the key, the secret or a header. The transport is injected (tests: fixtures). check() is the one read
 * of Settings › APIs' "Check the connection".
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

    /** What a check (check()) may end with; the page's words are Ui\Words::APIS_CHECK. */
    public const CHECKS = ['ok', 'wrong_key', 'not_allowed', 'unreachable', 'not_public', 'other'];
    /**
     * The exception types of ERPNext (Frappe) an error may name: anything else ERPNext's answer says is left out (AK12, the Codex review of
     * 9ddf7a0, MEDIUM: an answer's text never reaches a log or a run's summary, whatever the server put in it).
     */
    public const EXC_TYPES = ['AuthenticationError', 'PermissionError', 'DoesNotExistError', 'ValidationError', 'LinkValidationError', 'MandatoryError',
        'DataError', 'TimestampMismatchError', 'CSRFTokenError', 'SessionExpired', 'SessionStopped', 'TooManyRequestsError', 'RateLimitExceededError',
        'QueryTimeoutError', 'QueryDeadlockError', 'OperationalError', 'ProgrammingError', 'InternalError', 'InvalidSignatureError', 'PageDoesNotExistError'];

    private readonly string $base;
    private readonly string $auth;
    /** Where the key came from: 'saved' (Settings › APIs), 'app_env', or 'given' (made directly: the tests). */
    private string $source = 'given';
    /** The key's last 4 characters (what a screen or an audit row may name). */
    private readonly string $keyLast4;

    public function __construct(private readonly ErpTransport $transport, string $baseUrl, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $secret,
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
        $this->keyLast4 = substr($key, -4);
    }

    /** Never show the secret (var_dump, print_r, a stack trace's arguments). @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['base' => $this->base, 'auth' => '(hidden)'];
    }

    /** Where the key came from: 'saved' (Settings › APIs), 'app_env' or 'given'. */
    public function source(): string
    {
        return $this->source;
    }

    /** The address read (never with the key: it travels in a header). */
    public function base(): string
    {
        return $this->base;
    }

    /** The key's last 4 characters: all a screen or an audit row names of it. */
    public function keyLast4(): string
    {
        return $this->keyLast4;
    }

    /**
     * The reader of CW's read-only user: the key saved on Settings › APIs when there is one ($db given; docs/decisions.md AK7), called
     * through a public-only transport (ErpHttpTransport, AK5) unless one is given; else the one app.env describes, as before. 503
     * erp_read_unconfigured (naming the missing KEYS, never a value) when neither has it; 503 api_cannot_open when the saved key cannot
     * be opened with this server's ui_secret_key (never a silent fall back to app.env: the person typed a key, it must be the one used);
     * 503 api_host_not_allowed when the saved address is not a name ApiCredentials::allowedHosts() trusts.
     * $box: the box that seals it (the screens give theirs; default: app.env ui_secret_key).
     */
    public static function fromConfig(Config $config, ?ErpTransport $transport = null, ?Db $db = null, ?SecretBox $box = null, int $timeout = self::TIMEOUT): self
    {
        if ($db !== null && self::hasSaved($db)) {
            if ($box === null) {
                try {
                    $box = SecretBox::fromBase64((string) ($config->get(SecretBox::KEY_NAME) ?? ''));
                } catch (ConfigException) {
                    throw new CwException('api_cannot_open', 'the key saved on Settings > APIs cannot be opened: this server has no usable ' . SecretBox::KEY_NAME
                        . ' in app.env', 503);
                }
            }
            $saved = ApiCredentials::open($db, ApiCredentials::ERP_READ, $box);
            if ($saved !== null) {
                // Only a trusted ERPNext name is ever called, even for a row written around the page (AK12; 503: the job stops).
                ApiCredentials::trusted(ApiCredentials::ERP_READ, $saved['url'], $config, 'api_host_not_allowed', 503);
                $r = new self($transport ?? new ErpHttpTransport(true), $saved['url'], $saved['key'], $saved['secret'], $timeout);
                $r->source = 'saved';
                return $r;
            }
        }
        $v = [];
        $missing = [];
        foreach (self::CONFIG as $k => $name) {
            $v[$k] = trim((string) ($config->get($name) ?? ''));
            if ($v[$k] === '') {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            throw new CwException('erp_read_unconfigured', 'the ERPNext read-only user is not set up: save its key on Settings > APIs, or put it in app.env (missing in app.env: '
                . implode(', ', $missing) . ')', 503,
                ['missing' => $missing]);
        }
        $r = new self($transport ?? new ErpHttpTransport(), $v['url'], $v['key'], $v['secret'], $timeout);
        $r->source = 'app_env';
        return $r;
    }

    /** Whether a key is saved on Settings › APIs (false before 0063: no table). */
    public static function hasSaved(Db $db): bool
    {
        try {
            return $db->value('SELECT 1 FROM api_credential WHERE name = ? AND key_enc IS NOT NULL', [ApiCredentials::ERP_READ]) !== null;
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 1146) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Settings › APIs' "Check the connection" (docs/decisions.md AK8): ONE GET, the first purchase invoice's name (limit 1), and how it
     * ended, in a word of CHECKS: ok (ERPNext gave a list: this user may read purchase invoices), wrong_key (401, or ERPNext's
     * AuthenticationError), not_allowed (403), unreachable (no answer), not_public (the address was refused, nothing sent), other (any
     * other answer: its HTTP status is given). Never what ERPNext answered, never the key or the secret.
     *
     * @return array{outcome: string, status: ?int}
     */
    public function check(): array
    {
        $url = $this->base . '/api/resource/' . rawurlencode(self::DOCTYPE) . '?' . http_build_query(['fields' => '["name"]', 'limit_page_length' => '1'], '', '&',
            PHP_QUERY_RFC3986);
        try {
            $r = $this->transport->get($url, [$this->auth, 'Accept: application/json'], $this->timeout);
        } catch (ErpAddressRefused) {
            return ['outcome' => 'not_public', 'status' => null];
        } catch (\RuntimeException) {
            return ['outcome' => 'unreachable', 'status' => null];
        }
        $status = (int) $r['status'];
        $body = json_decode((string) $r['body'], true, 64);
        $type = is_array($body) && is_string($body['exc_type'] ?? null) ? $body['exc_type'] : '';
        return match (true) {
            $status === 200 && is_array($body) && is_array($body['data'] ?? null) && array_is_list($body['data']) => ['outcome' => 'ok', 'status' => null],
            $status === 401, $type === 'AuthenticationError' => ['outcome' => 'wrong_key', 'status' => null],
            $status === 403 => ['outcome' => 'not_allowed', 'status' => null],
            default => ['outcome' => 'other', 'status' => $status >= 100 && $status <= 599 ? $status : null],
        };
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
     * One GET; returns ERPNext's `data`. 502 erp_read_failed naming the status and ERPNext's exception type when it is a known one
     * (EXC_TYPES; never other text of the answer, AK12), never the key (it travels in a header).
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
            // ERPNext's exception type when it is one of EXC_TYPES; never other text of the answer (AK12).
            $type = is_array($body) && is_string($body['exc_type'] ?? null) && in_array($body['exc_type'], self::EXC_TYPES, true) ? ' (' . $body['exc_type'] . ')' : '';
            throw new CwException('erp_read_failed', "ERPNext answered HTTP {$r['status']}{$type}" . ($r['status'] === 403 || $r['status'] === 401
                ? ': check the read-only user and its rights' : ''), 502, ['status' => $r['status']]);
        }
        return $body['data'];
    }
}
