<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * PayPal's Transaction Search, read only (docs/decisions.md PF10, PF11). Exactly two calls ever leave CW with the shop's key:
 * POST {api}/v1/oauth2/token (client credentials; the token is kept in this object only, for this run) and GET {api}/v1/reporting/transactions
 * (fields=transaction_info, page_size 500, page by page). Every other path is refused before a request is made (PATHS).
 *
 * window() reads every page of one window of at most 31 days (PayPal's limit; CW asks for WINDOW_DAYS at most) and gives all of its rows,
 * or nothing: a page that fails after its tries (a timeout, a refused connection, 429, 5xx: TRIES tries, waiting 2, 4, 8 s between), an answer
 * that is not PayPal's shape, a page count above MAX_PAGES, or rows that do not add up to PayPal's total_items is a refusal of the whole window
 * (nothing of it is stored by the caller). A 401 asks for a new token once. A refusal says the call, the page and the HTTP status, never
 * PayPal's text (it may quote a request) and never the key or the token.
 */
final class PaypalApi
{
    public const TOKEN_PATH = '/v1/oauth2/token';
    public const SEARCH_PATH = '/v1/reporting/transactions';
    /** The only paths CW calls (the owner's key has every right: CW uses two read calls with it). */
    public const PATHS = [self::TOKEN_PATH, self::SEARCH_PATH];
    public const PAGE_SIZE = 500;
    /** 100 pages = 50,000 rows a window: a week of Vape and Go is about 2,000. */
    public const MAX_PAGES = 100;
    /** PayPal's longest window. */
    public const MAX_WINDOW_DAYS = 31;
    public const TRIES = 4;
    public const TIMEOUT_SEC = 60;
    /** An answer larger than this is not PayPal's (500 rows are about 1 MB). */
    public const MAX_BYTES = 16_000_000;

    private ?string $token = null;
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /** @param (\Closure(int): void)|null $sleep waits this many seconds (tests: no wait) */
    public function __construct(private readonly PaypalKey $key, private readonly PaypalHttp $http, ?\Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $s): void {
            sleep($s);
        };
    }

    public function account(): string
    {
        return $this->key->account;
    }

    public function shop(): string
    {
        return $this->key->shop;
    }

    /**
     * Every row of the window [$from, $to] (UTC instants, at most MAX_WINDOW_DAYS apart): PayPal's transaction_info objects as decoded, the
     * pages read, and PayPal's last_refreshed_datetime (its data is complete up to it).
     *
     * @return array{rows: list<mixed>, pages: int, refreshed: ?string}
     */
    public function window(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($to < $from || $to->getTimestamp() - $from->getTimestamp() > self::MAX_WINDOW_DAYS * 86400) {
            throw new CwException('paypal_bad_window', 'a PayPal window is at most ' . self::MAX_WINDOW_DAYS . ' days', 422);
        }
        $utc = new \DateTimeZone('UTC');
        $rows = [];
        $pages = 1;
        $total = null;
        $refreshed = null;
        for ($page = 1; $page <= $pages; $page++) {
            $q = http_build_query(['start_date' => $from->setTimezone($utc)->format('Y-m-d\TH:i:sP'), 'end_date' => $to->setTimezone($utc)->format('Y-m-d\TH:i:sP'),
                'fields' => 'transaction_info', 'page_size' => self::PAGE_SIZE, 'page' => $page]);
            $j = $this->search($q, $page);
            if (!array_key_exists('transaction_details', $j) && ($j['total_items'] ?? null) === 0) {
                $j['transaction_details'] = []; // a window with no row: PayPal may leave the list out
            }
            if (!isset($j['total_pages'], $j['total_items']) || !is_int($j['total_pages']) || !is_int($j['total_items']) || $j['total_pages'] < 0
                || $j['total_items'] < 0 || !array_key_exists('transaction_details', $j) || !is_array($j['transaction_details'])
                || !array_is_list($j['transaction_details'])) {
                throw new CwException('paypal_bad_answer', "PayPal's answer to page {$page} is not in its usual shape: nothing of this window was stored", 502);
            }
            if ($page === 1) {
                $pages = max(1, $j['total_pages']);
                $total = $j['total_items'];
                if ($pages > self::MAX_PAGES) {
                    throw new CwException('paypal_too_many', "PayPal has {$pages} pages for this window (CW reads at most " . self::MAX_PAGES . '): nothing was stored', 502);
                }
            } elseif ($j['total_pages'] !== $pages || $j['total_items'] !== $total) {
                throw new CwException('paypal_moved', "PayPal's count of this window changed while it was read (page {$page}): nothing of it was stored; the next run reads it again", 502);
            }
            if (isset($j['last_refreshed_datetime']) && is_string($j['last_refreshed_datetime'])) {
                $refreshed = $j['last_refreshed_datetime'];
            }
            foreach ($j['transaction_details'] as $d) {
                if (!is_array($d) || !array_key_exists('transaction_info', $d)) {
                    throw new CwException('paypal_bad_answer', "PayPal's answer to page {$page} has a row without transaction_info: nothing of this window was stored", 502);
                }
                $rows[] = $d['transaction_info'];
            }
        }
        if (count($rows) !== $total) {
            throw new CwException('paypal_moved', sprintf("PayPal said %d rows for this window and gave %d: nothing of it was stored; the next run reads it again",
                (int) $total, count($rows)), 502);
        }
        return ['rows' => $rows, 'pages' => $pages, 'refreshed' => $refreshed];
    }

    /** @return array<string, mixed> one page of the search, decoded */
    private function search(string $query, int $page): array
    {
        for ($fresh = 0; ; $fresh++) {
            $token = $this->token();
            $r = $this->call('GET', self::SEARCH_PATH . '?' . $query, ['Authorization: Bearer ' . $token, 'Accept: application/json'], null, "page {$page}");
            if ($r['status'] === 401 && $fresh === 0) {
                $this->token = null; // the token ran out during the run: a new one, once
                continue;
            }
            if ($r['status'] !== 200) {
                throw new CwException('paypal_http', "PayPal refused page {$page} (HTTP {$r['status']}): nothing of this window was stored", 502, ['status' => $r['status']]);
            }
            return self::json($r['body'], "page {$page}");
        }
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $r = $this->call('POST', self::TOKEN_PATH, ['Authorization: Basic ' . $this->key->basic(), 'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded'], 'grant_type=client_credentials', 'the token');
        if ($r['status'] !== 200) {
            throw new CwException('paypal_token', "PayPal refused the key (HTTP {$r['status']}): nothing was read", 502, ['status' => $r['status']]);
        }
        $j = self::json($r['body'], 'the token');
        if (!isset($j['access_token']) || !is_string($j['access_token']) || preg_match('/^[A-Za-z0-9._~+\/=-]{10,4096}$/D', $j['access_token']) !== 1) {
            throw new CwException('paypal_token', "PayPal's token answer is not in its usual shape: nothing was read", 502);
        }
        return $this->token = $j['access_token'];
    }

    /**
     * One request, tried TRIES times while the connection fails, PayPal says 429 or 5xx (waiting 2, 4, 8 s).
     *
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private function call(string $method, string $pathAndQuery, #[\SensitiveParameter] array $headers, ?string $body, string $what): array
    {
        $path = (string) strtok($pathAndQuery, '?');
        if (!in_array($path, self::PATHS, true) || !in_array([$method, $path], [['POST', self::TOKEN_PATH], ['GET', self::SEARCH_PATH]], true)) {
            throw new \LogicException('CW calls only POST ' . self::TOKEN_PATH . ' and GET ' . self::SEARCH_PATH . ' at PayPal');
        }
        $last = 'no answer';
        for ($try = 1; $try <= self::TRIES; $try++) {
            if ($try > 1) {
                ($this->sleep)(2 ** ($try - 1));
            }
            try {
                $r = $this->http->request($method, $this->key->apiBase . $pathAndQuery, $headers, $body, self::TIMEOUT_SEC);
            } catch (PaypalHttpFailed $e) {
                $last = $e->getMessage();
                continue;
            }
            if ($r['status'] === 429 || $r['status'] >= 500) {
                $last = "HTTP {$r['status']}";
                continue;
            }
            if (strlen($r['body']) > self::MAX_BYTES) {
                throw new CwException('paypal_bad_answer', "PayPal's answer to {$what} is too large: nothing of this window was stored", 502);
            }
            return $r;
        }
        throw new CwException('paypal_unreachable', "PayPal did not answer {$what} after " . self::TRIES . " tries ({$last}): nothing of this window was stored", 502);
    }

    /** @return array<string, mixed> */
    private static function json(string $body, string $what): array
    {
        try {
            $j = json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new CwException('paypal_bad_answer', "PayPal's answer to {$what} is not JSON: nothing of this window was stored", 502);
        }
        if (!is_array($j) || array_is_list($j) && $j !== []) {
            throw new CwException('paypal_bad_answer', "PayPal's answer to {$what} is not an object: nothing of this window was stored", 502);
        }
        return $j;
    }
}
