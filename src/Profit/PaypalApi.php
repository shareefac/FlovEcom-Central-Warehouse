<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * PayPal's Transaction Search, read only (docs/decisions.md PPF10, PPF11). Exactly two calls ever leave CW with the shop's key:
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

    /** How many times a window is read again when a row comes twice in it (PayPal's pages moved while read), before it is refused. */
    public const READS = 3;
    /** The start_date / end_date an answer says it covers may differ from the times asked for by this many seconds (rounding): no shorter. */
    public const TIME_SLACK_SEC = 60;

    /**
     * @param (\Closure(int): void)|null $sleep waits this many seconds (tests: no wait)
     * @param float|null $deadline microtime(true) after which no request is started and none lasts beyond (the job's overall deadline: below the
     *   installer's 6-minute drain, fix round 1)
     */
    public function __construct(private readonly PaypalKey $key, private readonly PaypalHttp $http, ?\Closure $sleep = null, private readonly ?float $deadline = null)
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

    /** PayPal's account number of the merchant the key is of (PaypalFees::run checks it against CW's own setting at every run: PPF39). */
    public function merchant(): string
    {
        return $this->key->merchantId;
    }

    /** Whether the key is for PayPal's live host (not the sandbox). */
    public function live(): bool
    {
        return $this->key->live();
    }

    /**
     * Every row of the window [$from, $to] (UTC instants, at most MAX_WINDOW_DAYS apart): PayPal's transaction_info objects as decoded, the
     * pages read, and what the answer PROVES of the window (final review c1, round 3; PPF41): `refreshed` = the LEAST fresh
     * last_refreshed_datetime of its pages (null when any page gave none: its completeness unknown, so nothing is proven), `returned_to` =
     * the earliest end of the interval its pages say they cover (null when no page said one; a page that says it covers less than was asked
     * proves only that much), `short_start` = a page said it starts later than asked (the window proves nothing). A page that names another
     * page number than the one asked for is refused (the window whole).
     *
     * @return array{rows: list<mixed>, pages: int, refreshed: ?string, returned_to: ?string, short_start: bool}
     */
    public function window(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // a row twice in one window (by its id and event code) means PayPal's pages moved while they were read (a row of one page again on the
        // next, another one missed): the whole window is read again, READS times, then refused (fix round 1)
        for ($read = 1; ; $read++) {
            $w = $this->readWindow($from, $to);
            $keys = [];
            $twice = false;
            foreach ($w['rows'] as $ti) {
                $k = is_array($ti) ? (($ti['transaction_id'] ?? '') . '|' . ($ti['transaction_event_code'] ?? '')) : '';
                if (isset($keys[$k])) {
                    $twice = true;
                    break;
                }
                $keys[$k] = true;
            }
            if (!$twice) {
                return $w;
            }
            if ($read >= self::READS) {
                throw new CwException('paypal_moved', "PayPal gave a row twice in one window {$read} times (its pages moved while read): nothing of it was stored; the next run reads it again", 502);
            }
        }
    }

    /** @return array{rows: list<mixed>, pages: int, refreshed: ?string, returned_to: ?string, short_start: bool} one read of every page of a window */
    private function readWindow(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($to < $from || $to->getTimestamp() - $from->getTimestamp() > self::MAX_WINDOW_DAYS * 86400) {
            throw new CwException('paypal_bad_window', 'a PayPal window is at most ' . self::MAX_WINDOW_DAYS . ' days', 422);
        }
        $utc = new \DateTimeZone('UTC');
        $rows = [];
        $pages = 1;
        $total = null;
        // what the pages prove together (c1, round 3): the least fresh refresh time of them all (one page without one: nothing), the earliest
        // end of the interval they say they cover, and whether one said it starts later than asked
        $refreshed = null;
        $undated = false;
        $returnedTo = null;
        $shortStart = false;
        $askedFrom = $from->setTimezone($utc)->format('Y-m-d H:i:s');
        $askedTo = $to->setTimezone($utc)->format('Y-m-d H:i:s');
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
            // the merchant whose rows these are: the key's own, or nothing of the window is taken (the lead's decision 4, fix round 1)
            if (!isset($j['account_number']) || !is_string($j['account_number']) || $j['account_number'] !== $this->key->merchantId) {
                throw new CwException('paypal_wrong_account', "PayPal's page {$page} is not of the key's merchant (its merchant_id): nothing of this window was stored", 502);
            }
            // the page's identity: an answer for another page than the one asked for is not this window's (refused whole)
            if (array_key_exists('page', $j) && $j['page'] !== $page) {
                throw new CwException('paypal_moved', "PayPal answered page {$page} with another page: nothing of this window was stored; the next run reads it again", 502);
            }
            $r = self::time($j['last_refreshed_datetime'] ?? null);
            if ($r === null) {
                $undated = true;
            } elseif ($refreshed === null || $r < $refreshed) {
                $refreshed = $r;
            }
            // the interval the page says it covers (when it says one): a start later than asked proves nothing of the window, an end earlier than
            // asked proves only up to it; one that is not a time is not PayPal's shape
            foreach (['start_date', 'end_date'] as $f) {
                if (!array_key_exists($f, $j) || $j[$f] === null) {
                    continue;
                }
                $t = self::time($j[$f]);
                if ($t === null) {
                    throw new CwException('paypal_bad_answer', "PayPal's answer to page {$page} has a {$f} that is not a time: nothing of this window was stored", 502);
                }
                // a minute either way is PayPal's own rounding of the times asked for (never a day short)
                $t = substr($t, 0, 19);
                $secs = (new \DateTimeImmutable($t, $utc))->getTimestamp();
                if ($f === 'start_date' && $secs > (new \DateTimeImmutable($askedFrom, $utc))->getTimestamp() + self::TIME_SLACK_SEC) {
                    $shortStart = true;
                }
                if ($f === 'end_date' && $secs < (new \DateTimeImmutable($askedTo, $utc))->getTimestamp() - self::TIME_SLACK_SEC && ($returnedTo === null || $t < $returnedTo)) {
                    $returnedTo = $t;
                }
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
        return ['rows' => $rows, 'pages' => $pages, 'refreshed' => $undated ? null : $refreshed, 'returned_to' => $returnedTo, 'short_start' => $shortStart];
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
                ($this->sleep)(min(2 ** ($try - 1), max(0, (int) floor($this->left()))));
            }
            // the job's deadline: no request started past it, none allowed to last beyond it
            $left = $this->left();
            if ($left < 1) {
                throw new CwException('paypal_deadline', "the run's time ran out before {$what}: nothing of this window was stored; the next run reads it again", 504);
            }
            try {
                $r = $this->http->request($method, $this->key->apiBase . $pathAndQuery, $headers, $body, (int) min(self::TIMEOUT_SEC, floor($left)));
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

    /** Seconds left before the deadline (none: a lot). */
    private function left(): float
    {
        return $this->deadline === null ? 1e9 : $this->deadline - microtime(true);
    }

    /** PayPal's time (last_refreshed_datetime) as CW's UTC database time, or null when it is not one (never passed on as PayPal wrote it). */
    public static function time(mixed $t): ?string
    {
        if (!is_string($t) || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})$/D', $t, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            return null;
        }
        return (new \DateTimeImmutable($t))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.000000');
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
