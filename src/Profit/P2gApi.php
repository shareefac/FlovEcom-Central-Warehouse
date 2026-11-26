<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Clock;
use CW\CwException;

/**
 * Parcel2Go's API, read only (docs/decisions.md PGL6; its public reference https://www.parcel2go.com/api/swagger/docs/v1, read 26 Nov 2026).
 * Exactly these calls ever leave CW with an account's key (CALLS; anything else is refused before a request is made, a test proves it):
 *   POST {base}/auth/connect/token            client credentials, scope `public-api` (a token that cannot pay; never `payment` here: D4)
 *   GET  {base}/api/me/orders/detail          the account's 25 most recent order lines (no price, no tracking number)
 *   GET  {base}/api/tracking/{P2G number}     the courier's tracking number (ParcelNumber), once the courier scanned it
 *   GET  {base}/api/invoices/{n}              ?referenceType=OrderId (n: the basket, TransactionId) or OrderLineId (n: a P2G number)[&hash=]:
 *                                             the basket's invoice, a PDF (one per checkout: every label's price, TX-<transaction id>)
 * Each answer is reduced at once to the fields CW keeps (keep*()): no name, address, postcode, e-mail, phone, contents or text reaches the
 * caller. A request that fails (no answer, 429, 5xx) is tried TRIES times (2, 4, 8 s apart, Retry-After honoured up to 30 s, never past the
 * deadline); a 401 asks for a new token once. A refusal says the call and the HTTP status, never Parcel2Go's text, the key or the token.
 * WAITS FOR THE PROBE (B0, P2G_STATE 1.6): the shapes below are the public reference's; what the live answers really hold (the hash, the
 * dates' zone, whether invoices need the payment scope) is checked by the lead's probe before the accounts are switched on.
 */
final class P2gApi
{
    public const SCOPE = 'public-api';
    public const TOKEN_PATH = '/auth/connect/token';
    /** method => path patterns (the only calls CW makes). */
    public const CALLS = [
        'POST' => ['#^/auth/connect/token$#D'],
        'GET' => ['#^/api/me/orders/detail$#D', '#^/api/tracking/[0-9]{1,12}$#D', '#^/api/invoices/[0-9]{1,12}\?referenceType=(OrderId|OrderLineId)(&hash=[A-Za-z0-9%._~-]{8,400})?$#D'],
    ];
    /** Parcel2Go's Source words (OrderDetailResponse.Source); any other is kept as "other". */
    public const SOURCES = ['OrderProcess', 'BulkUpload', 'Api', 'Reorder', 'Return', 'SoapApi', 'AutoTopup', 'RetailerReturn', 'AutoSend', 'Kiosk', 'QuickSend'];
    public const TRIES = 4;
    public const TIMEOUT_SEC = 60;
    public const MAX_JSON_BYTES = 2_000_000;
    public const MAX_PDF_BYTES = 2_000_000;
    /** The most order lines a recent list may hold (the reference: 25). */
    public const MAX_LINES = 200;

    private ?string $token = null;
    private int $calls = 0;
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep waits this many seconds (tests: no wait)
     * @param float|null $deadline microtime(true) after which no request is started (the job's own deadline)
     */
    public function __construct(private readonly P2gKey $key, private readonly P2gHttp $http, ?\Closure $sleep = null, private readonly ?float $deadline = null)
    {
        $this->sleep = $sleep ?? static function (int $s): void {
            sleep($s);
        };
    }

    public function account(): string
    {
        return $this->key->account;
    }

    /** Requests sent so far. */
    public function calls(): int
    {
        return $this->calls;
    }

    /**
     * The account's most recent order lines, as CW keeps them.
     *
     * @return list<array{order_line_id: int, transaction_id: ?int, paid_at: ?string, service_slug: ?string, courier_slug: ?string, p2g_source: ?string,
     *   kind: string, country_iso3: ?string, parcels: int, doc_hash: ?string}> paid_at: UTC 'Y-m-d H:i:s.u' or null (not paid)
     */
    public function recent(): array
    {
        $j = $this->json('GET', '/api/me/orders/detail');
        if (!is_array($j) || !array_key_exists('Orders', $j) || !is_array($j['Orders']) || !array_is_list($j['Orders'])) {
            throw new CwException('p2g_shape', 'Parcel2Go\'s list of recent orders is not in the form CW knows: nothing was read', 502);
        }
        if (count($j['Orders']) > self::MAX_LINES) {
            throw new CwException('p2g_shape', 'Parcel2Go\'s list of recent orders is longer than CW expects: nothing was read', 502);
        }
        $out = [];
        foreach ($j['Orders'] as $i => $o) {
            $out[] = self::keepLine(is_array($o) ? $o : [], $i + 1);
        }
        return $out;
    }

    /**
     * The courier tracking numbers Parcel2Go's tracking of a line names (its events' ParcelNumber), in CW's form; [] when the line cannot be
     * tracked yet (404: not scanned yet, or too old).
     *
     * @return list<string>
     */
    public function tracking(int $orderLineId): array
    {
        $r = $this->send('GET', '/api/tracking/' . self::id($orderLineId), null, self::MAX_JSON_BYTES, [404]);
        if ($r['status'] === 404) {
            return [];
        }
        $j = self::decode($r['body'], 'its tracking');
        if (!is_array($j) || (array_key_exists('Results', $j) && $j['Results'] !== null && !is_array($j['Results']))) {
            throw new CwException('p2g_shape', 'Parcel2Go\'s tracking of a line is not in the form CW knows', 502);
        }
        $out = [];
        foreach (is_array($j['Results'] ?? null) ? $j['Results'] : [] as $ev) {
            $n = is_array($ev) && is_string($ev['ParcelNumber'] ?? null) ? self::trackingNo($ev['ParcelNumber']) : null;
            if ($n !== null) {
                $out[$n] = true;
            }
        }
        $out = array_keys($out);
        sort($out, SORT_STRING);
        return $out;
    }

    /** An invoice, a PDF's bytes (checked to start as a PDF does): of a basket ($type OrderId, $ref its TransactionId) or of a label's basket (OrderLineId). */
    public function invoice(int $ref, string $type, ?string $hash): string
    {
        if (!in_array($type, ['OrderId', 'OrderLineId'], true)) {
            throw new \InvalidArgumentException('an invoice is asked for by OrderId or OrderLineId');
        }
        $path = '/api/invoices/' . self::id($ref) . '?referenceType=' . $type . ($hash === null ? '' : '&hash=' . rawurlencode($hash));
        $j = $this->json('GET', $path, (int) (self::MAX_PDF_BYTES * 1.4) + 4096);
        $b64 = is_array($j) && is_string($j['Base64EncodedInvoice'] ?? null) ? $j['Base64EncodedInvoice'] : null;
        if ($b64 === null || $b64 === '') {
            throw new CwException('p2g_no_invoice', 'Parcel2Go gave no invoice for this label', 502);
        }
        $pdf = base64_decode($b64, true);
        if (!is_string($pdf) || strlen($pdf) > self::MAX_PDF_BYTES || !str_starts_with($pdf, '%PDF-')) {
            throw new CwException('p2g_no_invoice', 'Parcel2Go\'s invoice for this label is not a PDF CW can read', 502);
        }
        return $pdf;
    }

    /**
     * One line of the recent list as CW keeps it: the rest of the answer (addresses, names, contents, links) is never read.
     *
     * @param array<mixed> $o
     * @return array{order_line_id: int, transaction_id: ?int, paid_at: ?string, service_slug: ?string, courier_slug: ?string, p2g_source: ?string,
     *   kind: string, country_iso3: ?string, parcels: int, doc_hash: ?string}
     */
    public static function keepLine(array $o, int $n): array
    {
        $olid = $o['OrderLineId'] ?? null;
        if (!is_int($olid) || $olid <= 0) {
            throw new CwException('p2g_shape', "line {$n} of Parcel2Go's list has no order line number: nothing was read", 502);
        }
        $tx = $o['TransactionId'] ?? null;
        $paid = $o['PaidDate'] ?? null;
        $source = is_string($o['Source'] ?? null) ? (in_array($o['Source'], self::SOURCES, true) ? $o['Source'] : 'other') : null;
        $parcels = is_array($o['Parcels'] ?? null) ? count($o['Parcels']) : 1;
        $country = is_array($o['DeliveryAddress'] ?? null) && is_string($o['DeliveryAddress']['CountryIsoCode'] ?? null)
            && preg_match('/^[A-Z]{3}$/D', $o['DeliveryAddress']['CountryIsoCode']) === 1 ? $o['DeliveryAddress']['CountryIsoCode'] : null;
        $hash = is_string($o['Hash'] ?? null) && preg_match('/^[A-Za-z0-9+\/=_-]{8,128}$/D', $o['Hash']) === 1 ? $o['Hash'] : null;
        return ['order_line_id' => $olid, 'transaction_id' => is_int($tx) && $tx > 0 ? $tx : null,
            'paid_at' => $paid === null ? null : (is_string($paid) ? self::utc($paid, $n) : throw new CwException('p2g_shape', "line {$n} of Parcel2Go's list has a paid date CW cannot read", 502)),
            'service_slug' => is_string($o['ServiceSlug'] ?? null) ? self::slug($o['ServiceSlug'], 64) : null,
            'courier_slug' => is_string($o['Courier'] ?? null) ? self::slug($o['Courier'], 40) : null, 'p2g_source' => $source,
            'kind' => $source === 'AutoTopup' ? 'topup' : 'parcel', 'country_iso3' => $country, 'parcels' => max(1, min(50, $parcels)), 'doc_hash' => $hash];
    }

    /**
     * A date as Parcel2Go gives it (ISO-8601) in UTC. One with no zone is read as UK time (WAITS FOR THE PROBE, U3: the reference does not say).
     */
    public static function utc(string $s, int $n = 0): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(\.\d{1,7})?(Z|[+-]\d{2}:\d{2})?$/D', $s, $m) !== 1) {
            throw new CwException('p2g_shape', "line {$n} of Parcel2Go's list has a paid date CW cannot read", 502);
        }
        $zone = ($m[4] ?? '') === '' ? new \DateTimeZone('Europe/London') : new \DateTimeZone($m[4] === 'Z' ? '+00:00' : $m[4]);
        $frac = substr(str_pad(ltrim($m[3] ?? '', '.'), 6, '0'), 0, 6);
        $t = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', "{$m[1]} {$m[2]}.{$frac}", $zone);
        if ($t === false || $t->format('Y-m-d H:i:s') !== "{$m[1]} {$m[2]}") {
            throw new CwException('p2g_shape', "line {$n} of Parcel2Go's list has a paid date CW cannot read", 502);
        }
        return $t->setTimezone(Clock::utc())->format('Y-m-d H:i:s.u');
    }

    /** A courier tracking number in the form CW's parcels keep it (upper case, no spaces or hyphens: SnapshotBody::tracking), or null. */
    public static function trackingNo(string $t): ?string
    {
        $n = strtoupper((string) preg_replace('/[\s-]+/', '', $t));
        return preg_match('/^[A-Z0-9]{8,32}$/D', $n) === 1 ? $n : null;
    }

    /** A slug as CW keeps a service or a courier ("Evri Standard" -> evri-standard), or null. */
    public static function slug(string $s, int $max): ?string
    {
        $t = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
        return $t !== '' && strlen($t) <= $max ? $t : null;
    }

    private static function id(int $n): string
    {
        if ($n <= 0 || $n > 999_999_999_999) {
            throw new \InvalidArgumentException('a P2G number is 1 to 12 digits');
        }
        return (string) $n;
    }

    private function json(string $method, string $path, int $maxBytes = self::MAX_JSON_BYTES): mixed
    {
        return self::decode($this->send($method, $path, null, $maxBytes)['body'], 'its answer');
    }

    private static function decode(string $body, string $what): mixed
    {
        try {
            return json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new CwException('p2g_shape', "Parcel2Go's answer ({$what}) is not JSON", 502);
        }
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $r = $this->send('POST', self::TOKEN_PATH, $this->key->tokenForm(self::SCOPE), 65536);
        $j = self::decode($r['body'], 'the token');
        $t = is_array($j) && is_string($j['access_token'] ?? null) ? $j['access_token'] : '';
        if (preg_match('/^[A-Za-z0-9._~+\/=-]{10,8000}$/D', $t) !== 1 || strtolower((string) ($j['token_type'] ?? 'bearer')) !== 'bearer') {
            throw new CwException('p2g_token', 'Parcel2Go gave no token CW can use', 502);
        }
        return $this->token = $t;
    }

    /**
     * One allowed call, with its tries. $ok: statuses given back to the caller instead of refused (404 of the tracking).
     *
     * @param list<int> $ok
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function send(string $method, string $path, #[\SensitiveParameter] ?string $body, int $maxBytes, array $ok = []): array
    {
        self::allowed($method, $path);
        $isToken = $path === self::TOKEN_PATH;
        $renewed = false;
        for ($try = 1; ; $try++) {
            if ($this->deadline !== null && microtime(true) >= $this->deadline) {
                throw new CwException('p2g_deadline', 'this run\'s time was up before Parcel2Go could be asked again: the next run goes on', 503);
            }
            $headers = ['Accept: application/json'];
            if ($isToken) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $headers[] = 'Authorization: Bearer ' . $this->token();
            }
            $this->calls++;
            $wait = null;
            try {
                $r = $this->http->request($method, $this->key->apiBase . $path, $headers, $body, self::TIMEOUT_SEC, $maxBytes);
            } catch (P2gHttpFailed $e) {
                $r = null;
                $why = $e->getMessage();
            }
            if ($r !== null) {
                $s = (int) $r['status'];
                if (($s >= 200 && $s < 300) || in_array($s, $ok, true)) {
                    return $r;
                }
                if ($s === 401 && !$isToken && !$renewed) {
                    $this->token = null;
                    $renewed = true;
                    $try--;
                    continue;
                }
                $why = 'HTTP ' . $s;
                if ($s !== 429 && $s < 500) {
                    throw new CwException('p2g_refused', sprintf('Parcel2Go refused %s %s (%s)', $method, self::named($path), $why), 502, ['status' => $s]);
                }
                $ra = $r['headers']['retry-after'] ?? null;
                $wait = is_string($ra) && ctype_digit($ra) ? min(30, (int) $ra) : null;
            }
            if ($try >= self::TRIES) {
                throw new CwException('p2g_unreachable', sprintf('Parcel2Go could not be read (%s %s: %s, %d tries): the next run tries again', $method, self::named($path),
                    $why, self::TRIES), 503);
            }
            ($this->sleep)($wait ?? 2 ** $try);
        }
    }

    /** Refuses any call CW does not make (before anything is sent). */
    public static function allowed(string $method, string $path): void
    {
        foreach (self::CALLS[$method] ?? [] as $re) {
            if (preg_match($re, $path) === 1) {
                return;
            }
        }
        throw new \LogicException('CW never sends this request to Parcel2Go');
    }

    /** A call's name for a refusal: its path without the line's number or hash. */
    private static function named(string $path): string
    {
        return (string) preg_replace(['/\?.*$/s', '#/[0-9]{1,12}$#'], ['', '/{n}'], $path);
    }
}
