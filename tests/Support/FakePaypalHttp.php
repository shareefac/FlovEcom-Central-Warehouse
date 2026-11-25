<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Profit\PaypalHttp;
use CW\Profit\PaypalHttpFailed;

/**
 * PayPal's Transaction Search as the tests see it (docs/decisions.md PPF10): a token for the right Basic credentials, and the rows whose
 * transaction_initiation_date is inside [start_date, end_date] served page by page (page_size, page, total_items, total_pages,
 * last_refreshed_datetime, account_number: the merchant), as PayPal does. Nothing leaves the test: no network. $fail may answer a request instead (a 503, a lost
 * connection, a 401) by its number; every request is recorded (method, path, query) with no header kept.
 */
final class FakePaypalHttp implements PaypalHttp
{
    /** @var list<array{method: string, path: string, query: array<string, string>}> */
    public array $calls = [];
    /** @var list<array<string, mixed>> */
    private array $rows;
    /** @var array<string, int> unix time of each row's initiation */
    private array $at = [];
    /** @var array<string, int> unix time of each row's last update */
    private array $upd = [];
    /**
     * Whether a row is also served in the window of its transaction_updated_date (as PayPal did on 24 Nov 2026: a dispute hold's release of
     * September, T1111, came in October's read, the month it was released; the replay of the real rows needs it).
     */
    public bool $byUpdated = false;

    /**
     * @param list<array<string, mixed>> $rows transaction_info objects
     * @param (\Closure(int, string, array<string, string>): (array{status: int, body: string}|null))|null $fail request number (1 = first),
     *   path, query => an answer instead of PayPal's, or null; may throw PaypalHttpFailed
     */
    public function __construct(array $rows, private readonly string $basic = '', public ?\Closure $fail = null, private readonly ?string $refreshed = '2026-11-25T02:00:00Z',
        private readonly string $accountNumber = 'TESTACCOUNT')
    {
        $this->set($rows);
    }

    /** @param list<array<string, mixed>> $rows */
    public function set(array $rows): void
    {
        $this->rows = array_values($rows);
        $this->at = [];
        foreach ($this->rows as $i => $r) {
            $this->at[$i] = (new \DateTimeImmutable((string) $r['transaction_initiation_date']))->getTimestamp();
            $this->upd[$i] = (new \DateTimeImmutable((string) ($r['transaction_updated_date'] ?? $r['transaction_initiation_date'])))->getTimestamp();
        }
    }

    public function request(string $method, string $url, #[\SensitiveParameter] array $headers, ?string $body, int $timeoutSec): array
    {
        $u = parse_url($url);
        $path = (string) ($u['path'] ?? '');
        parse_str((string) ($u['query'] ?? ''), $q);
        $q = array_map('strval', $q);
        $this->calls[] = ['method' => $method, 'path' => $path, 'query' => $q];
        if ($this->fail !== null && ($a = ($this->fail)(count($this->calls), $path, $q)) !== null) {
            return $a;
        }
        if ($path === '/v1/oauth2/token') {
            if ($this->basic !== '' && !in_array('Authorization: Basic ' . $this->basic, $headers, true)) {
                return ['status' => 401, 'body' => '{"error":"invalid_client"}'];
            }
            return ['status' => 200, 'body' => json_encode(['access_token' => 'A21AAtestTOKEN' . count($this->calls), 'expires_in' => 32400])];
        }
        if ($path !== '/v1/reporting/transactions' || $method !== 'GET') {
            return ['status' => 404, 'body' => '{}'];
        }
        $from = (new \DateTimeImmutable($q['start_date']))->getTimestamp();
        $to = (new \DateTimeImmutable($q['end_date']))->getTimestamp();
        $hits = [];
        foreach ($this->rows as $i => $r) {
            if (($this->at[$i] >= $from && $this->at[$i] <= $to) || ($this->byUpdated && $this->upd[$i] >= $from && $this->upd[$i] <= $to)) {
                $hits[] = $r;
            }
        }
        $size = (int) $q['page_size'];
        $page = (int) $q['page'];
        $pages = (int) ceil(count($hits) / $size);
        $slice = array_slice($hits, ($page - 1) * $size, $size);
        return ['status' => 200, 'body' => json_encode(['transaction_details' => array_map(static fn (array $r): array => ['transaction_info' => $r], $slice),
            'account_number' => $this->accountNumber, 'start_date' => $q['start_date'], 'end_date' => $q['end_date'], 'last_refreshed_datetime' => $this->refreshed,
            'page' => $page, 'total_items' => count($hits), 'total_pages' => $pages], JSON_THROW_ON_ERROR)];
    }

    /** A connection that never answered (for $fail). */
    public static function lost(): never
    {
        throw new PaypalHttpFailed('no answer (curl error 28)');
    }
}
