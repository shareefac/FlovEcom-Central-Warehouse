<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * Reads an account's labels from Click & Drop's API (docs/decisions.md DL49; the owner, 23 Nov 2026, Q4: CW holds the account's key and only
 * READS in phase 1). GET only: this class has no other verb (a test reads its source), and its transport (HttpGet) has none either.
 *
 * What it reads (proven for our accounts on 23 Nov 2026): GET /orders (a page of orders between two times, followed by its continuation
 * token) and GET /orders/{ids} (up to 100 orders at once: their tracking number and manifest time; the list leaves them out). The answers are
 * cut down at once to orderIdentifier, orderReference, createdOn, manifestedOn, trackingNumber and the packages' tracking numbers: nothing
 * else of them (a name, an address) is ever kept, and no answer is ever logged.
 *
 * Its limits: at least 250 ms between two calls (Click & Drop allows 5 a second); a 429 waits Retry-After seconds (else 1, 2, 4, 8, 16) and
 * tries again, 6 tries in all, then "busy"; an answer of 5xx or none waits 1 then 2 seconds, 3 tries in all, then "down"; 401 / 403: the key is
 * refused. The header is "Authorization: <key>" as Click & Drop took it on 23 Nov 2026. A message never holds the key, a header, a URL's query
 * or any part of an answer.
 */
final class ClickAndDropClient
{
    public const BASE = 'https://api.parcel.royalmail.com/api/v1';
    /** The least gap between two calls, in microseconds. */
    public const GAP_MICRO = 250_000;
    /** Orders a call of /orders/{ids} asks for at most. */
    public const IDS_PER_CALL = 100;
    public const TIMEOUT_SEC = 30;
    /** Tries of a call answered 429, in all. */
    public const BUSY_TRIES = 6;
    /** Tries of a call answered 5xx or not at all, in all. */
    public const DOWN_TRIES = 3;
    /** The longest Retry-After waited, in seconds. */
    public const MAX_RETRY_AFTER = 60;
    /** The most pages one list call follows. */
    public const MAX_PAGES = 5_000;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;
    /** @var \Closure(): float */
    private readonly \Closure $clock;
    private ?float $last = null;

    /**
     * @param (\Closure(int): void)|null $sleep waits that many microseconds (the tests: a recorder)
     * @param (\Closure(): float)|null $clock seconds now (the tests: a clock the recorder moves)
     */
    public function __construct(private readonly HttpGet $http, #[\SensitiveParameter] private readonly string $key, ?\Closure $sleep = null,
        private readonly string $base = self::BASE, ?\Closure $clock = null)
    {
        $this->sleep = $sleep ?? static function (int $micro): void {
            usleep($micro);
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
        if ($key === '' || preg_match('/[\r\n]/', $key) === 1) {
            throw new CwException('no_key', 'the Click & Drop key is empty or not one line', 500);
        }
    }

    /**
     * The orders Click & Drop lists between two times (its own clock), every page.
     *
     * @return list<array{orderIdentifier: int, orderReference: ?string, createdOn: ?string, manifestedOn: ?string, trackingNumber: ?string,
     *   packages: list<array{trackingNumber: ?string}>}>
     */
    public function orders(string $fromIso, string $toIso, int $pageSize = 100): array
    {
        $out = [];
        $token = null;
        $tokens = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $q = ['startDateTime' => $fromIso, 'endDateTime' => $toIso, 'pageSize' => max(1, min(100, $pageSize))];
            if ($token !== null) {
                $q['continuationToken'] = $token;
            }
            $a = $this->call('/orders', $q, false);
            if (!is_array($a) || !array_key_exists('orders', $a) && $a !== [] || isset($a['orders']) && !is_array($a['orders'])) {
                throw self::badAnswer();
            }
            foreach ((array) ($a['orders'] ?? []) as $o) {
                $out[] = self::reduce($o);
            }
            $next = $a['continuationToken'] ?? null;
            if (!is_string($next) || $next === '') {
                return $out;
            }
            if (isset($tokens[$next])) {
                throw self::badAnswer(); // the same page again: never followed round
            }
            $tokens[$next] = true;
            $token = $next;
        }
        throw self::badAnswer();
    }

    /**
     * These orders' details (tracking numbers and manifest times), IDS_PER_CALL a call; an order Click & Drop does not find is left out.
     *
     * @param list<int> $ids
     * @return list<array{orderIdentifier: int, orderReference: ?string, createdOn: ?string, manifestedOn: ?string, trackingNumber: ?string,
     *   packages: list<array{trackingNumber: ?string}>}>
     */
    public function ordersByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach ($ids as $id) {
            if ($id < 1) {
                throw new CwException('bad_id', 'a Click & Drop order identifier is a whole number above 0', 500);
            }
        }
        $out = [];
        foreach (array_chunk($ids, self::IDS_PER_CALL) as $chunk) {
            $a = $this->call('/orders/' . implode(';', $chunk), [], true);
            if ($a === null) {
                continue; // none of them found
            }
            if (!array_is_list($a)) {
                throw self::badAnswer();
            }
            foreach ($a as $o) {
                $out[] = self::reduce($o);
            }
        }
        return $out;
    }

    /**
     * Whether an order's details are whole for its day to count as read (DL82, DL83): in their shapes (reduce()'s "whole"); an order not
     * manifested yet then is (it is read again when it is); a manifested one needs a manifest time CW reads and a Royal Mail tracking number on
     * every package (or on the order, with no packages).
     *
     * @param array<string, mixed> $o a reduce()d order
     */
    public static function complete(array $o): bool
    {
        if (!($o['whole'] ?? false)) {
            return false;
        }
        if (($o['manifestedOn'] ?? null) === null || $o['manifestedOn'] === '') {
            return true;
        }
        if (self::time($o['manifestedOn']) === null) {
            return false;
        }
        $rm = static fn (mixed $t): bool => is_string($t) && preg_match('/^[A-Z]{2}[0-9]{9}GB$/D', strtoupper(trim($t))) === 1;
        $packages = (array) ($o['packages'] ?? []);
        if ($packages === []) {
            return $rm($o['trackingNumber'] ?? null);
        }
        foreach ($packages as $p) {
            if (!is_array($p) || !$rm($p['trackingNumber'] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** The UK day of a manifest time as Click & Drop gives it (null: not a time CW reads). */
    public static function manifestDay(string $manifestedOn): ?string
    {
        $at = self::time($manifestedOn);
        return $at === null ? null : substr($at, 0, 10);
    }

    /**
     * The labels in these orders: one per package's tracking number (else the order's own), each field only in its known shape.
     *
     * @param list<array<string, mixed>> $orders reduce()d orders
     * @return list<array{tracking_no: string, source_id: int, reference: ?string, created_on: ?string, manifested_at: ?string, manifest_day: ?string}>
     */
    public static function labels(array $orders): array
    {
        $out = [];
        $seen = [];
        foreach ($orders as $o) {
            $numbers = [];
            foreach ((array) ($o['packages'] ?? []) as $p) {
                if (is_array($p) && is_string($p['trackingNumber'] ?? null) && $p['trackingNumber'] !== '') {
                    $numbers[] = $p['trackingNumber'];
                }
            }
            if ($numbers === [] && is_string($o['trackingNumber'] ?? null) && $o['trackingNumber'] !== '') {
                $numbers[] = $o['trackingNumber'];
            }
            $ref = is_string($o['orderReference'] ?? null) ? trim($o['orderReference']) : '';
            $at = self::time($o['manifestedOn'] ?? null);
            foreach ($numbers as $n) {
                $t = strtoupper(trim($n));
                if (preg_match('/^[A-Z]{2}[0-9]{9}GB$/D', $t) !== 1 || isset($seen[$t])) { // Royal Mail's shape only (a phone number is never kept: DL72)
                    continue;
                }
                $seen[$t] = true;
                $out[] = ['tracking_no' => $t, 'source_id' => (int) $o['orderIdentifier'], 'reference' => preg_match('/^#?[0-9]{1,9}$/D', $ref) === 1 ? $ref : null,
                    'created_on' => self::time($o['createdOn'] ?? null), 'manifested_at' => $at, 'manifest_day' => $at === null ? null : substr($at, 0, 10)];
            }
        }
        return $out;
    }

    /**
     * Click & Drop's time ("2026-11-09T18:00:35.4961013": its own clock, UK time, as given) as 'Y-m-d H:i:s.uuuuuu'; one with a zone (Z, +hh:mm)
     * is turned into UK time; null: not such a time.
     */
    public static function time(mixed $v): ?string
    {
        if (!is_string($v) || preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:?\d{2})?$/D', trim($v), $m) !== 1) {
            return null;
        }
        $frac = substr(str_pad($m[3] ?? '', 6, '0'), 0, 6);
        $zone = $m[4] ?? '';
        try {
            // No zone: the clock as given (read on a zone with no clock change, so 01:30 on the morning the clocks go forward stays 01:30).
            $t = new \DateTimeImmutable($m[1] . ' ' . $m[2] . '.' . $frac, new \DateTimeZone($zone === '' || $zone === 'Z' ? 'UTC' : $zone));
        } catch (\Exception) {
            return null;
        }
        if ($t->format('Y-m-d H:i:s') !== $m[1] . ' ' . $m[2]) {
            return null; // not a real day or time (30 Feb, 25:00)
        }
        return $zone === '' ? $t->format('Y-m-d H:i:s.u') : $t->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d H:i:s.u');
    }

    /**
     * One call, with its waits and tries. null: 404 on /orders/{ids} (none of them found).
     *
     * @param array<string, string|int> $query
     * @return array<mixed>|null
     */
    private function call(string $path, array $query, bool $byIds): ?array
    {
        $url = $this->base . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $busy = 0;
        $down = 0;
        while (true) {
            $this->pace();
            $r = $this->http->get($url, ['Authorization: ' . $this->key, 'Accept: application/json'], self::TIMEOUT_SEC);
            $this->last = ($this->clock)();
            $status = (int) ($r['status'] ?? 0);
            if ($status === 429) {
                if (++$busy >= self::BUSY_TRIES) {
                    throw new CwException('cnd_busy', 'Click & Drop kept answering "too many calls" (HTTP 429): try again later', 503);
                }
                $after = $r['retry_after'] ?? null;
                $this->wait(is_int($after) && $after >= 0 ? min($after, self::MAX_RETRY_AFTER) : 2 ** ($busy - 1));
                continue;
            }
            if ($status === 0 || $status >= 500) {
                if (++$down >= self::DOWN_TRIES) {
                    throw new CwException('cnd_down', 'Click & Drop did not answer (' . ($status === 0 ? 'no answer' : "HTTP {$status}") . '): the next run tries again', 503);
                }
                $this->wait($down);
                continue;
            }
            if ($status === 401 || $status === 403) {
                throw new CwException('cnd_refused', "Click & Drop refused the key of this account (HTTP {$status}): check the key in CW's key file", 502);
            }
            if ($status === 404 && $byIds) {
                return null;
            }
            if ($status !== 200) {
                throw new CwException('cnd_error', "Click & Drop answered a read with an error (HTTP {$status})", 502);
            }
            try {
                $a = json_decode((string) ($r['body'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw self::badAnswer();
            }
            if (!is_array($a)) {
                throw self::badAnswer();
            }
            return $a;
        }
    }

    /** Waits until GAP_MICRO has passed since the last call. */
    private function pace(): void
    {
        if ($this->last === null) {
            return;
        }
        $left = (int) ceil(self::GAP_MICRO - (($this->clock)() - $this->last) * 1_000_000);
        if ($left > 0) {
            ($this->sleep)($left);
        }
    }

    private function wait(int $seconds): void
    {
        ($this->sleep)($seconds * 1_000_000);
    }

    /**
     * An order cut down to what CW keeps.
     *
     * @return array{orderIdentifier: int, orderReference: ?string, createdOn: ?string, manifestedOn: ?string, trackingNumber: ?string,
     *   packages: list<array{trackingNumber: ?string}>}
     */
    private static function reduce(mixed $o): array
    {
        if (!is_array($o) || !is_int($o['orderIdentifier'] ?? null) || $o['orderIdentifier'] < 1) {
            throw self::badAnswer();
        }
        $str = static fn (mixed $v): ?string => is_string($v) ? $v : (is_int($v) ? (string) $v : null);
        $packages = [];
        foreach (is_array($o['packages'] ?? null) ? $o['packages'] : [] as $p) {
            $packages[] = ['trackingNumber' => is_array($p) ? $str($p['trackingNumber'] ?? null) : null];
        }
        // whether the details came in their shapes (DL83: the Codex check r7, H1): the manifest time there (null: not manifested yet), packages a
        // list of objects when there; an id alone, or packages of another shape, never counts as whole
        $shape = array_key_exists('manifestedOn', $o) && ($o['manifestedOn'] === null || is_string($o['manifestedOn']))
            && (!array_key_exists('packages', $o) || (is_array($o['packages']) && array_is_list($o['packages'])
                && array_filter($o['packages'], static fn (mixed $p): bool => !is_array($p)) === []));
        return ['orderIdentifier' => $o['orderIdentifier'], 'orderReference' => $str($o['orderReference'] ?? null), 'createdOn' => $str($o['createdOn'] ?? null),
            'manifestedOn' => $str($o['manifestedOn'] ?? null), 'trackingNumber' => $str($o['trackingNumber'] ?? null), 'packages' => $packages, 'whole' => $shape];
    }

    private static function badAnswer(): CwException
    {
        return new CwException('cnd_bad_answer', 'Click & Drop\'s answer was not what CW reads (its orders as JSON): nothing of it was kept', 502);
    }
}
