<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Reports\ReportRefresh;

/**
 * Royal Mail's bills and labels in CW (delivery cost, phase 1 of the shipping plan v3; docs/decisions.md DL43-DL70): ONE service for every
 * pooled carrier account (0706703000 and Vape Big's: only the settings differ; the owner, 23 Nov 2026, Q5).
 *
 *  check()         the upload screen's first step (DL79): a bill read and refused as upload() / replace() would refuse it, where its money would
 *                  go, and the bill AS READ kept for its Store (never the file), bound to who checked it and the file's sha256, once, CHECK_MINUTES.
 *  storeChecked()  the screen's Store: the bill its check read, stored as upload() / replace() store it.
 *  upload()        a bill read from its saved page or pasted text (RoyalMailBillPage) and stored whole: its rows, and every penny of every row
 *                  as allocations (PooledPlanner): the days it touches planned again across every live bill of the account.
 *  replace()       a corrected file of a live bill: the old one superseded, its allocations ended, the days planned again; one write.
 *  undo()          "Undo this upload": the bill undone, its allocations ended, the days it touched planned again on the bills left.
 *  importReport()  the labels of Click & Drop's "Manifested Orders" report (ClickAndDropReport): new labels added, empty fields filled.
 *  pull()          the labels read from Click & Drop's API (ClickAndDropClient, GET only) from the account's checkpoint less 3 days.
 *  replan()        the nightly: every key (day x product, manifest, sales order) of the live rows of the last REPLAN_DAYS planned again (a label or
 *                  a CW parcel that came since takes its price; a held amount settled).
 *  drift()         the live allocations against a plan from scratch: [] when they are what the rows and labels say.
 *
 * Every write is under the delivery bills' lock and through DeliveryBills::guardedWrite (DL13, DL37, DL41): the plan drafted first, then the
 * snapshot guards of every order an allocation is on, the day rows, the plan's label destinations read again (verify), and the allocations
 * that change ended / added with the profit days moved by the difference. A row's live allocations always add up to its net; an allocation
 * is never changed or deleted (ended, and a new row).
 */
final class RoyalMailBills
{
    /** The nightly plans again the keys of the live rows of this many days back. */
    public const REPLAN_DAYS = 120;
    /** A pull starts this many days before the last day read whole (an outage of any length is caught up; nothing is skipped). */
    public const PULL_OVERLAP_DAYS = 3;
    /** A first pull (no checkpoint yet) reads this many days back. */
    public const PULL_FIRST_DAYS = 14;
    /** Labels with no manifest yet, created this many days back, are asked for again by their Click & Drop number. */
    public const PULL_OPEN_DAYS = 14;
    /** Keys planned in one guarded write (a day x product key alone holds a day's labels: FLAT_PER_WRITE of them a write). */
    public const KEYS_PER_WRITE = 50;
    public const FLAT_PER_WRITE = 1;
    /** A service code's shape: letters then a digit (TPL48HV, TPM24HV, TRN24, SD1): a word there is never one (the Codex check r2, new MEDIUM 4). */
    public const SERVICE_CODE = '/^[A-Z]{1,6}[0-9][A-Z0-9]{0,9}$/D';
    /** A label's tracking number in its carrier's shape (a pooled carrier's: Royal Mail's two letters, nine digits, GB). */
    public const TRACKING = ['royal_mail' => '/^[A-Z]{2}[0-9]{9}GB$/D'];
    /** Rounds a plan's keys are closed over (Special Delivery groups) before the plan is tried again. */
    public const CLOSURE_ROUNDS = 10;
    /** Tries of a chunk of a loading bill's allocations whose row wait ran out. */
    public const LOAD_TRIES = 5;
    /** Who "ended" a bill whose write never finished (it never counted). */
    public const NOT_STORED = 'system:not_stored';
    /** A bill checked on the upload screen can be stored for this many minutes, by who checked it, once (DL79). */
    public const CHECK_MINUTES = 20;
    /** The label fields a second source may fill (when empty): the first source's value stays. */
    public const FILL = ['source_id', 'reference', 'channel_code', 'service_code', 'package', 'weight_g', 'created_on', 'manifested_at', 'manifest_no', 'manifest_day',
        'sales_order'];
    private const RETRY = 'delivery_retry';

    private readonly DeliveryBills $bills;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null, int $lockWaitSec = DeliveryBills::LOCK_WAIT_SEC)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->bills = new DeliveryBills($db, $this->clock, null, $lockWaitSec);
    }

    // ---- bills -----------------------------------------------------------------------------------------------------------------------

    /**
     * A bill read and stored (DL52-DL55, DL64). The same bytes as the live bill: "same", nothing changes; another file of a live bill: refused.
     *
     * @return array{result: string, invoice_id: int, invoice_no: string, uploaded_at: string, rematched: int, rematch_error: ?string}
     */
    public function upload(Caller $caller, int $accountId, string $bytes): array
    {
        $this->bills->authoriseWrite($caller);
        $account = self::active($this->db, $accountId);
        return $this->uploadBill($caller, $accountId, self::read($this->db, $account, $bytes), ['file_sha256' => hash('sha256', $bytes)]);
    }

    /**
     * The upload screen's check (DL79; the plan, section 6 step 3; the coordinator, 24 Nov 2026, D3): the bill read and refused as upload() (or,
     * with $replaceId, replace()) would refuse it; where its money would go (preview()); and, unless it is the very bill in the profit already
     * ("same"), the bill AS READ kept in carrier_bill_check for its Store: bound to who checked it and the sha256 of the file or text, single
     * use, CHECK_MINUTES long. Never the file (the page may hold names; the bill as read holds what CW stores of it anyway). A person only.
     *
     * @return array{token: ?string, same: bool, file_sha256: string, bill_sha256: string, replace: ?array{id: int, invoice_no: string, uploaded_at: string},
     *   preview: array<string, mixed>}
     */
    public function check(Caller $caller, ?int $accountId, string $bytes, ?int $replaceId = null): array
    {
        $this->bills->authoriseWrite($caller);
        if ($caller->staffUserId === null) {
            throw new CwException('forbidden', 'a bill is checked on the screen by a person (bin/rm_bill.php checks and stores in two runs)', 403);
        }
        $old = null;
        if ($replaceId !== null) {
            $old = $this->db->one('SELECT * FROM carrier_invoice WHERE id = ?', [$replaceId]) ?? throw new CwException('not_found', 'there is no such bill', 404);
            $accountId = self::accountOf($this->db, $old);
        }
        $account = self::active($this->db, (int) $accountId);
        $bill = self::read($this->db, $account, $bytes);
        $live = null;
        if ($old !== null) {
            self::replaceable($old, $bill);
            $same = $bill['sha256'] === $old['file_sha256'];
        } else {
            $live = $this->db->one('SELECT id, file_sha256, uploaded_at FROM carrier_invoice WHERE live_key = ?', [self::key($bill)]);
            if ($live !== null && $live['file_sha256'] !== $bill['sha256']) {
                throw self::alreadyUploaded($bill, $live);
            }
            $same = $live !== null;
        }
        // planned as the store would plan it: a corrected file with the bill it replaces gone; the very bill live with itself gone, so its split
        // is its own (never a tail of itself: the Codex check r4, D3 LOW 3)
        $out = ['token' => null, 'same' => $same, 'file_sha256' => hash('sha256', $bytes), 'bill_sha256' => (string) $bill['sha256'],
            'replace' => $old === null ? null : ['id' => (int) $old['id'], 'invoice_no' => (string) $old['invoice_no'], 'uploaded_at' => (string) $old['uploaded_at']],
            'preview' => $this->previewOf($account, $bill, $old !== null ? (int) $old['id'] : ($live === null ? null : (int) $live['id']))];
        if ($same) {
            // the very bill in the profit: the split it HAS (its live allocations), whatever came since (the Codex check r5, LOW 9)
            $out['preview']['where'] = self::liveSplit($this->db, $old !== null ? (int) $old['id'] : (int) $live['id']);
            $out['preview']['others'] = [];
            return $out;
        }
        $token = bin2hex(random_bytes(32));
        $now = ($this->clock)();
        $this->db->transaction(function (Db $db) use ($caller, $account, $bill, $old, $token, $now, $out): void {
            // the checks of more than a day ago gone (none was ever the file; a stored one's bill is in carrier_bill_row)
            $db->exec('DELETE FROM carrier_bill_check WHERE expires_at < ?', [Clock::db($now->modify('-1 day'))]);
            $id = $db->insert('INSERT INTO carrier_bill_check (token_sha256, staff_user_id, account_id, replace_id, file_sha256, bill_sha256, plan_sha256, bill, created_at, '
                . 'expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [hash('sha256', $token), $caller->staffUserId, $account['id'], $old === null ? null : (int) $old['id'],
                    $out['file_sha256'], $out['bill_sha256'], (string) $out['preview']['plan_sha256'], json_encode($bill, JSON_THROW_ON_ERROR), Clock::db($now),
                    Clock::db($now->modify('+' . self::CHECK_MINUTES . ' minutes'))]);
            Audit::write($db, $caller, 'delivery_bill.check', 'carrier_account', (string) $account['id'], null, ['check' => $id, 'carrier' => $account['carrier'],
                'account' => $bill['account'], 'invoice' => $bill['invoice_no'], 'rows' => count($bill['rows']), 'net_pence' => $bill['net'],
                'file_sha256' => $out['file_sha256'], 'sha256' => $bill['sha256']] + ($old === null ? [] : ['replaces' => (int) $old['id']]));
        });
        return ['token' => $token] + $out;
    }

    /**
     * The screen's Store (DL79): the bill its check READ, by who checked it, once, while the check is open; stored as upload() / replace() store
     * it (its audit names the check and the file's sha256). A check that is not open (stored already, out of time, another person's, another
     * reading) stores nothing; a store refused as busy leaves the check open to press again.
     *
     * @return array{result: string, invoice_id: int, invoice_no: string, uploaded_at: string, rematched: int, rematch_error: ?string}
     */
    public function storeChecked(Caller $caller, string $token, string $billSha256): array
    {
        $this->bills->authoriseWrite($caller);
        $gone = new CwException('check_gone', sprintf('This check is no longer open: it was stored already, it is more than %d minutes old, or someone else made it. '
            . 'Nothing was saved. Upload the bill again to check it.', self::CHECK_MINUTES), 409);
        if ($caller->staffUserId === null || preg_match('/^[0-9a-f]{64}$/D', $token) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $billSha256) !== 1) {
            throw $gone;
        }
        $now = Clock::db(($this->clock)());
        $row = $this->db->transaction(function (Db $db) use ($caller, $token, $billSha256, $now, $gone): array {
            $r = $db->one('SELECT * FROM carrier_bill_check WHERE token_sha256 = ? AND staff_user_id = ? AND bill_sha256 = ? AND used_at IS NULL AND expires_at > ? FOR UPDATE',
                [hash('sha256', $token), $caller->staffUserId, $billSha256, $now]) ?? throw $gone;
            $db->exec('UPDATE carrier_bill_check SET used_at = ? WHERE id = ? AND used_at IS NULL', [$now, (int) $r['id']]);
            return $r;
        });
        $bill = json_decode((string) $row['bill'], true, 64, JSON_THROW_ON_ERROR);
        $kept = is_array($bill) ? (string) ($bill['sha256'] ?? '') : '';
        unset($bill['sha256']);
        if ($kept !== (string) $row['bill_sha256'] || hash('sha256', json_encode($bill, JSON_THROW_ON_ERROR)) !== $kept) {
            throw new CwException('bad_check', 'the checked bill CW kept is not the one it read: nothing was saved. Tell the CW team.', 500);
        }
        $bill['sha256'] = $kept;
        // the store compares its own plan with the split the check showed (plan_sha256: the Codex check r4, D3 MEDIUM 1; DL80)
        $audit = ['check' => (int) $row['id'], 'file_sha256' => (string) $row['file_sha256'], 'plan_sha256' => (string) $row['plan_sha256']];
        try {
            // the mode said with the result: the screen never reads the bill again to tell (the Codex check r4, r3 8 PARTLY)
            return $row['replace_id'] === null ? $this->uploadBill($caller, (int) $row['account_id'], $bill, $audit) + ['mode' => 'upload']
                : $this->replaceBill($caller, (int) $row['replace_id'], $bill, $audit) + ['mode' => 'replace'];
        } catch (CwException $e) {
            if ($e->errorCode === 'busy') {
                $this->db->exec('UPDATE carrier_bill_check SET used_at = NULL WHERE id = ?', [(int) $row['id']]);
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $bill RoyalMailBillPage::parse()
     * @param array<string, mixed> $audit what the audit adds (the file's sha256, the check)
     * @return array{result: string, invoice_id: int, invoice_no: string, uploaded_at: string, rematched: int, rematch_error: ?string}
     */
    private function uploadBill(Caller $caller, int $accountId, array $bill, array $audit): array
    {
        $this->bills->authoriseWrite($caller);
        return $this->bills->inLock(function () use ($caller, $accountId, $bill, $audit): array {
            // the account read again now the lock is held: its number changed meanwhile, the bill is not its (the Codex check r2, r1 HIGH 4)
            $account = self::active($this->db, $accountId);
            if ($bill['account'] !== $account['account_no']) {
                throw new CwException('other_account', sprintf('this is a bill of account %s, not of account %s: choose its account and upload it again. Nothing was saved.',
                    $bill['account'], $account['account_no']), 422, ['field' => 'account']);
            }
            $live = $this->db->one('SELECT id, file_sha256, uploaded_at FROM carrier_invoice WHERE live_key = ?', [self::key($bill)]);
            if ($live !== null) {
                if ($live['file_sha256'] === $bill['sha256']) {
                    return ['result' => 'same', 'invoice_id' => (int) $live['id'], 'invoice_no' => $bill['invoice_no'], 'uploaded_at' => (string) $live['uploaded_at'],
                        'rematched' => 0, 'rematch_error' => null];
                }
                throw self::alreadyUploaded($bill, $live);
            }
            [$id, $at] = $this->store($caller, $account, $bill, null, $audit);
            return ['result' => 'stored', 'invoice_id' => $id, 'invoice_no' => $bill['invoice_no'], 'uploaded_at' => $at, 'rematched' => 0, 'rematch_error' => null];
        });
    }

    /**
     * A corrected file of a live bill (DL65): the old one superseded, its allocations ended, the new one live; the days planned again.
     *
     * @return array{result: string, invoice_id: int, invoice_no: string, uploaded_at: string, rematched: int, rematch_error: ?string}
     */
    public function replace(Caller $caller, int $invoiceId, string $bytes): array
    {
        $this->bills->authoriseWrite($caller);
        $old = $this->db->one('SELECT * FROM carrier_invoice WHERE id = ?', [$invoiceId]) ?? throw new CwException('not_found', 'there is no such bill', 404);
        $account = self::active($this->db, self::accountOf($this->db, $old));
        return $this->replaceBill($caller, $invoiceId, self::read($this->db, $account, $bytes), ['file_sha256' => hash('sha256', $bytes)]);
    }

    /**
     * @param array<string, mixed> $bill RoyalMailBillPage::parse()
     * @param array<string, mixed> $audit
     * @return array{result: string, invoice_id: int, invoice_no: string, uploaded_at: string, rematched: int, rematch_error: ?string}
     */
    private function replaceBill(Caller $caller, int $invoiceId, array $bill, array $audit): array
    {
        $this->bills->authoriseWrite($caller);
        return $this->bills->inLock(function () use ($caller, $invoiceId, $bill, $audit): array {
            $old = $this->db->one('SELECT * FROM carrier_invoice WHERE id = ?', [$invoiceId]) ?? throw new CwException('not_found', 'there is no such bill', 404);
            // the account read again now the lock is held (the Codex check r2, r1 HIGH 4)
            $account = self::active($this->db, self::accountOf($this->db, $old));
            self::replaceable($old, $bill);
            if ($bill['sha256'] === $old['file_sha256']) {
                return ['result' => 'same', 'invoice_id' => $invoiceId, 'invoice_no' => $bill['invoice_no'], 'uploaded_at' => (string) $old['uploaded_at'],
                    'rematched' => 0, 'rematch_error' => null];
            }
            [$id, $at] = $this->store($caller, $account, $bill, $old, $audit);
            return ['result' => 'stored', 'invoice_id' => $id, 'invoice_no' => $bill['invoice_no'], 'uploaded_at' => $at, 'rematched' => 0, 'rematch_error' => null];
        });
    }

    /**
     * "Undo this upload" (DL65): the bill undone (kept with its rows) in one guarded write with the days it touched planned again on the bills
     * left; its allocations, which count nowhere once it is undone, ended right after (sweep()).
     */
    public function undo(Caller $caller, int $invoiceId): void
    {
        $this->bills->authoriseWrite($caller);
        $this->bills->inLock(function () use ($caller, $invoiceId): void {
            $this->drainPins(); // before the days are planned again (the Codex check r5, MEDIUM 6)
            $this->bills->guardedWrite(function (Db $db) use ($caller, $invoiceId): array {
                $inv = $db->one('SELECT * FROM carrier_invoice WHERE id = ?', [$invoiceId]) ?? throw new CwException('not_found', 'there is no such bill', 404);
                if ($inv['status'] !== 'live') {
                    throw new CwException('not_live', 'this bill is no longer in the profit: nothing to undo.', 409);
                }
                $account = CarrierSettings::account($db, self::accountOf($db, $inv));
                $ctx = self::ctx($db, $account);
                $s = self::scope($db, $account, $ctx, self::keysOf($db, $account, $ctx, self::billRows($db, [(int) $inv['id']])), [], [(int) $inv['id']], null);
                return self::guardedPlan($s, function (Db $db) use ($caller, $inv, $s): void {
                    $this->bills->authoriseWrite($caller);
                    $now = Clock::db(($this->clock)());
                    $db->exec("UPDATE carrier_invoice SET status = 'undone', ended_by = ?, ended_actor = ?, ended_at = ? WHERE id = ? AND status = 'live'",
                        [$caller->staffUserId, $caller->actor, $now, (int) $inv['id']]);
                    self::apply($db, $s, $now);
                    Audit::write($db, $caller, 'delivery_bill.undo', 'carrier_invoice', (string) $inv['id'], null, ['carrier' => (string) $inv['carrier'],
                        'account' => (string) $inv['account'], 'invoice' => (string) $inv['invoice_no'], 'net_pence' => (int) $inv['net_pence'],
                        'allocations_ended' => count($s['end']), 'allocations_added' => count($s['add'])]);
                });
            });
            $this->sweepLater();
        });
    }

    /**
     * What an upload of the file would store, nothing written (bin/rm_bill.php without --upload; the tests): the bill's top, the live bill of
     * the same key, and where its money would go (per destination and shop / party / reason).
     *
     * @return array<string, mixed>
     */
    public function preview(int $accountId, string $bytes): array
    {
        $account = CarrierSettings::account($this->db, $accountId);
        $bill = self::read($this->db, $account, $bytes);
        // another file of a live bill: planned as its replace would plan it (the live one gone), as the screen's check of a replacement (DL79)
        $live = $this->db->one('SELECT id, file_sha256 FROM carrier_invoice WHERE live_key = ?', [self::key($bill)]);
        $p = $this->previewOf($account, $bill, $live !== null ? (int) $live['id'] : null);
        if ($live !== null && $live['file_sha256'] === $bill['sha256']) {
            // the very bill live: the split it has (the Codex check r5, LOW 9)
            $p['where'] = self::liveSplit($this->db, (int) $live['id']);
            $p['others'] = [];
        }
        return $p;
    }

    /** @return array<string, int> a stored bill's live allocations in the split's form ("dest|shop|party|reason|day_line" => pence) */
    private static function liveSplit(Db $db, int $invoiceId): array
    {
        $out = [];
        foreach ($db->all("SELECT CONCAT(dest, '|', COALESCE(channel_id, ''), '|', COALESCE(party_id, ''), '|', COALESCE(held_reason, ''), '|', "
            . "IF(kind = 'day_line', 'day_line', '')) AS k, SUM(pence) AS p FROM carrier_allocation WHERE invoice_id = ? AND ended_at IS NULL GROUP BY k", [$invoiceId]) as $r) {
            $out[(string) $r['k']] = (int) $r['p'];
        }
        ksort($out);
        return $out;
    }

    /** How many seconds a check of this person is still open for its Store (0: no longer; a busy Store's retry offers only an open one). */
    public function checkSecondsLeft(Caller $caller, string $token): int
    {
        if ($caller->staffUserId === null || preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            return 0;
        }
        $at = $this->db->value('SELECT expires_at FROM carrier_bill_check WHERE token_sha256 = ? AND staff_user_id = ? AND used_at IS NULL', [hash('sha256', $token),
            $caller->staffUserId]);
        return $at === null ? 0 : max(0, Clock::fromDb((string) $at)->getTimestamp() - ($this->clock)()->getTimestamp());
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $bill
     * @return array<string, mixed>
     */
    private function previewOf(array $account, array $bill, ?int $replaces = null): array
    {
        $ctx = self::ctx($this->db, $account);
        $rows = self::newRows($bill);
        // a corrected file is planned as its replace() would plan it: the bill it replaces gone, the keys of both planned (DL79)
        $gone = $replaces === null ? [] : [$replaces];
        $keys = array_values(array_unique([...self::keysOf($this->db, $account, $ctx, $rows), ...($gone === [] ? [] : self::keysOf($this->db, $account, $ctx,
            self::billRows($this->db, $gone)))]));
        sort($keys, SORT_STRING);
        $s = self::scope($this->db, $account, $ctx, $keys, $rows, $gone, null);
        $split = self::split($s, 0, $replaces);
        // the bill's flat days as the plan sees them (the Codex check r4, D3 MEDIUM 2): its labels, the units billed by every live bill with this
        // one (the bill it replaces left out), its labels priced, its day's line, and whether the day waits for its labels
        $byRow = [];
        foreach ($s['rows'] as $r) {
            $byRow[(int) $r['invoice_id'] . '|' . (int) $r['row_no']] = $r;
        }
        $days = [];
        foreach ($rows as $r) {
            $p = $ctx['products'][(string) $r['product_code']] ?? null;
            if (($p['kind'] ?? null) !== 'flat') {
                continue;
            }
            $k = $r['service_date'] . '|' . $r['product_code'];
            $days[$k] ??= ['day' => (string) $r['service_date'], 'product' => (string) $r['product_code'], 'labels' => (int) $this->db->value('SELECT COUNT(*) FROM carrier_label '
                . 'WHERE account_id = ? AND manifest_day = ? AND service_code = ?', [$account['id'], $r['service_date'], (string) $p['service_code']]),
                'billed' => array_sum(array_map(static fn (array $x): int => (int) $x['qty'], array_filter($s['rows'], static fn (array $x): bool
                    => (string) $x['service_date'] === (string) $r['service_date'] && (string) $x['product_code'] === (string) $r['product_code']))),
                'priced' => 0, 'line' => 0, 'waiting' => false, 'status' => 'open'];
        }
        // across the whole plan (every bill of the day with this one: the Codex check r5, MEDIUM 5): labels priced, the day's line, waiting
        foreach ($s['desired'] as $a) {
            $r = $byRow[(int) $a['invoice_id'] . '|' . (int) $a['row_no']] ?? null;
            $k = $r === null ? null : $r['service_date'] . '|' . $r['product_code'];
            if ($k === null || !isset($days[$k])) {
                continue;
            }
            $days[$k]['priced'] += $a['kind'] === 'label' ? 1 : 0;
            $days[$k]['line'] += $a['kind'] === 'day_line' ? (int) $a['pence'] : 0;
            $days[$k]['waiting'] = $days[$k]['waiting'] || ($a['held_reason'] ?? null) === 'no_report';
        }
        // closed as the bill's page says it (RoyalMailViews::days): a bill of the account after the day's first one, or the day older than OPEN_DAYS;
        // "needs a look" when closed and its labels and units billed far apart
        $today = ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
        foreach ($days as $k => $d) {
            $ranks = array_map(static fn (array $x): string => (string) $x['rank'], array_filter($s['rows'], static fn (array $x): bool
                => (string) $x['service_date'] === $d['day'] && (string) $x['product_code'] === $d['product']));
            $first = $ranks === [] ? '' : substr(min($ranks), 0, 10);
            $later = $first !== '' && ((string) $bill['invoice_date'] > $first || $this->db->value("SELECT 1 FROM carrier_invoice WHERE status = 'live' AND carrier = ? "
                . 'AND account = ? AND invoice_date > ?' . ($replaces === null ? '' : ' AND id <> ' . (int) $replaces) . ' LIMIT 1', [$account['carrier'],
                    $account['account_no'], $first]) !== null);
            $closed = $later || DeliveryBills::daysBetween($d['day'], $today) > RoyalMailViews::OPEN_DAYS;
            $diff = abs($d['labels'] - $d['billed']);
            $days[$k]['status'] = !$closed ? 'open' : ($diff > RoyalMailViews::LOOK_LABELS && $diff * 100 > RoyalMailViews::LOOK_PCT * max($d['labels'], $d['billed'])
                ? 'look' : 'closed');
        }
        ksort($days);
        $live = $this->db->one('SELECT id, file_sha256 FROM carrier_invoice WHERE live_key = ?', [self::key($bill)]);
        return ['carrier' => $bill['carrier'], 'account' => $bill['account'], 'invoice_no' => $bill['invoice_no'], 'invoice_date' => $bill['invoice_date'],
            'period_from' => $bill['period_from'], 'period_to' => $bill['period_to'], 'net' => $bill['net'], 'vat' => $bill['vat'], 'gross' => $bill['gross'],
            'rows' => count($bill['rows']), 'sha256' => $bill['sha256'], 'live_id' => $live === null ? null : (int) $live['id'],
            'same_file' => $live !== null && $live['file_sha256'] === $bill['sha256'], 'where' => $split['where'], 'others' => $split['others'],
            'plan_sha256' => self::planSha($split), 'days' => array_values($days), 'state' => $account['state'],
            'bill_rows' => array_map(static fn (array $r): array => ['row_no' => (int) $r['row_no'], 'service_date' => (string) $r['service_date'],
                'product_code' => (string) $r['product_code'], 'qty' => (int) $r['qty'], 'net' => (int) $r['net'], 'ref_kind' => (string) $r['ref_kind'],
                'ref_no' => $r['ref_no'] === null ? null : (string) $r['ref_no'], 'sales_order' => $r['sales_order'] === null ? null : (string) $r['sales_order']], $rows)];
    }

    /**
     * A plan's split (DL79, DL80): where the bill's money goes (its allocations by destination, shop, party, reason and the day's line) and what
     * moves on the bills already live (their allocations ended and added, by the same groups; the bill it replaces left out: it goes whole).
     *
     * @param array<string, mixed> $s scope()
     * @return array{where: array<string, int>, others: array<string, int>}
     */
    private static function split(array $s, int $invoiceId, ?int $replaces): array
    {
        $key = static fn (array $a): string => $a['dest'] . '|' . ($a['channel_id'] ?? '') . '|' . ($a['party_id'] ?? '') . '|' . ($a['held_reason'] ?? '') . '|'
            . ($a['kind'] === 'day_line' ? 'day_line' : '');
        $where = [];
        foreach ($s['desired'] as $a) {
            if ((int) $a['invoice_id'] === $invoiceId) {
                $where[$key($a)] = ($where[$key($a)] ?? 0) + (int) $a['pence'];
            }
        }
        // what moves on the other live bills, by bill and service day (two bills' moves never cancel out: the Codex check r5, MEDIUM 4)
        $others = [];
        $exact = [];
        foreach ([[$s['add'], 1], [$s['end'], -1]] as [$set, $sign]) {
            foreach ($set as $a) {
                $inv = (int) $a['invoice_id'];
                if ($inv !== $invoiceId && $inv !== $replaces) {
                    $k = $inv . '|' . $a['day'] . '|' . $key($a);
                    $others[$k] = ($others[$k] ?? 0) + $sign * (int) $a['pence'];
                    $exact[] = ($sign > 0 ? '+' : '-') . PooledPlanner::identity($a);
                }
            }
        }
        $others = array_filter($others, static fn (int $p): bool => $p !== 0);
        // the fingerprint of the plan itself: every allocation of the bill (its own number left out: 0 when checked, its id when stored) and
        // every allocation of another bill that ends or is added (DL81)
        $mine = [];
        foreach ($s['desired'] as $a) {
            if ((int) $a['invoice_id'] === $invoiceId) {
                $mine[] = PooledPlanner::identity(['invoice_id' => 0] + $a);
            }
        }
        sort($mine, SORT_STRING);
        sort($exact, SORT_STRING);
        ksort($where);
        ksort($others);
        return ['where' => $where, 'others' => $others, 'exact' => [$mine, $exact]];
    }

    /** @param array{where: array<string, int>, others: array<string, int>, exact: array{0: list<string>, 1: list<string>}} $split */
    private static function planSha(array $split): string
    {
        return hash('sha256', json_encode($split['exact'], JSON_THROW_ON_ERROR));
    }

    // ---- labels ----------------------------------------------------------------------------------------------------------------------

    /**
     * Click & Drop's "Manifested Orders" report (DL48): its labels added or their empty fields filled, the file never kept (its sha256 in the
     * audit), then the keys they touch planned again.
     *
     * @return array<string, mixed>
     */
    public function importReport(Caller $caller, int $accountId, string $bytes): array
    {
        $this->bills->authoriseWrite($caller);
        $account = CarrierSettings::account($this->db, $accountId);
        $r = ClickAndDropReport::read($bytes, CarrierSettings::channels($this->db, $accountId));
        // the report is the account's: every label of it in one of the account's own Click & Drop channels (another account's report would put
        // its labels on this account's bills: the Codex check r1, HIGH 2). A channel CW does not know is never named (it is free text).
        $others = count(array_filter($r['labels'], static fn (array $l): bool => $l['channel_code'] === 'other' || $l['channel_code'] === null));
        if ($others > 0) {
            throw new CwException('other_account', sprintf('this report has %d labels of no Click & Drop channel or of one account %s does not have: is it this account\'s report? '
                . 'Nothing was saved.', $others, $account['account_no']), 422, ['field' => 'file']);
        }
        return ['rows' => $r['rows'], 'skipped' => $r['skipped'], 'sha256' => $r['sha256']] + $this->importLabels($caller, $account['id'], $r['labels'], 'report',
            ['sha256' => $r['sha256']]);
    }

    /**
     * Labels from a source stored (one row per account and tracking number: a new one added; one there already only has its EMPTY fields
     * filled, the first source's value stays), then the keys they touch planned again (a failure there leaves the labels stored: the nightly
     * plans them; said in replan_error).
     *
     * @param list<array<string, mixed>> $labels ClickAndDropReport / ClickAndDropClient::labels() shapes
     * @param array<string, mixed> $audit more for the audit row (never a label)
     * @return array{labels: int, added: int, filled: int, differs: int, replanned: ?int, replan_error: ?string}
     */
    public function importLabels(Caller $caller, int $accountId, array $labels, string $source, array $audit = [], ?array $read = null): array
    {
        $this->bills->authoriseWrite($caller);
        $account = CarrierSettings::account($this->db, $accountId);
        return $this->bills->inLock(function () use ($caller, $account, $labels, $source, $audit, $read): array {
            $this->bills->authoriseWrite($caller); // again once the lock is held (the Codex check r3, new MEDIUM 2)
            // the account read again now the lock is held: switched off, its labels are still read (a new account is filled so) but its bills are
            // not planned (no money moves while it is off: the Codex check r4, MEDIUM; DL80)
            $account = CarrierSettings::account($this->db, $account['id']);
            // the pins earlier writes left due done first: no plan here moves a label off the parcel its first price was on (the Codex check r5,
            // MEDIUM 6; DL81)
            $this->drainPins();
            $u = $this->upsert($caller, $account, $labels, $source, $audit);
            if ($read !== null) {
                // the days a successful pull read and its checkpoint, in one transaction after its labels (DL80, DL81): a day of an account with a
                // key waits until it is here; the checkpoint moves only over days read with it
                $days = [];
                for ($d = $read[0]; $d <= $read[1]; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
                    $days[] = $d;
                }
                $at = Clock::db(($this->clock)());
                $this->db->transaction(function (Db $db) use ($account, $days, $read, $at): void {
                    foreach (array_chunk($days, 200) as $chunk) {
                        $db->exec('INSERT IGNORE INTO carrier_label_pull_day (account_id, day, read_at) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?)')),
                            array_merge(...array_map(static fn (string $d): array => [$account['id'], $d, $at], $chunk)));
                    }
                    $db->exec('INSERT INTO carrier_label_pull (account_id, read_to, last_try_at, last_ok_at) VALUES (?, ?, ?, ?) AS n ON DUPLICATE KEY UPDATE '
                        . 'read_to = IF(carrier_label_pull.read_to IS NULL OR ? <= DATE_ADD(carrier_label_pull.read_to, INTERVAL 1 DAY), '
                        . 'GREATEST(COALESCE(carrier_label_pull.read_to, n.read_to), n.read_to), carrier_label_pull.read_to), last_ok_at = n.last_ok_at, last_error = NULL',
                        [$account['id'], $read[1], $at, $at, $read[0]]);
                });
            }
            $out = ['labels' => count($labels), 'added' => $u['added'], 'filled' => $u['filled'], 'differs' => $u['differs'], 'replanned' => null, 'replan_error' => null];
            if (($u['all'] === [] && $read === null) || $account['state'] !== 'active') {
                return ['replanned' => 0] + $out;
            }
            try {
                // every label read (not only the ones it changed: a read whose plan died is planned by the next read; the Codex check r1, MEDIUM
                // 5), and every row of their manifest days (a day waiting for its labels, a row of no batch: DL71); a pull's days too, labels of
                // them or not (a day a pull read is planned by it: the Codex check r5, MEDIUM 3)
                $keys = $u['all'] === [] ? [] : self::keysOfLabels($this->db, $account, $u['all']);
                if ($read !== null) {
                    $ctx = self::ctx($this->db, $account);
                    $keys = array_values(array_unique([...$keys, ...self::keysOf($this->db, $account, $ctx, self::rowsWhere($this->db, $account,
                        'r.service_date BETWEEN ? AND ?', [$read[0], $read[1]]))]));
                    sort($keys, SORT_STRING);
                }
                $out['replanned'] = $this->replanKeys($caller, $account, $keys);
            } catch (CwException | \PDOException $e) {
                $code = $e instanceof \PDOException ? Db::driverCode($e) : null;
                $out['replan_error'] = $e instanceof CwException ? $e->getMessage() : 'the bills could not be planned again just now (database error ' . ($code ?? 'unknown')
                    . '): the nightly run does it';
            }
            return $out;
        });
    }

    /**
     * The account's labels read from Click & Drop (DL49): every order created from the checkpoint less PULL_OVERLAP_DAYS (a first pull:
     * PULL_FIRST_DAYS back) to yesterday (UK), each asked for by its number (tracking number, manifest time), plus the labels with no
     * manifest yet of the last PULL_OPEN_DAYS; then stored as importLabels() does. The network is read OUTSIDE the lock; the checkpoint
     * moves only when everything was read and stored.
     *
     * @return array<string, mixed>
     */
    public function pull(Caller $caller, int $accountId, ClickAndDropClient $client, ?string $from = null, ?string $to = null): array
    {
        $this->bills->authoriseWrite($caller);
        $account = CarrierSettings::account($this->db, $accountId);
        $today = ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'));
        $to ??= $today->modify('-1 day')->format('Y-m-d');
        $read = $this->db->value('SELECT read_to FROM carrier_label_pull WHERE account_id = ?', [$account['id']]);
        $from ??= $read === null ? $today->modify('-' . self::PULL_FIRST_DAYS . ' days')->format('Y-m-d')
            // the checkpoint less PULL_OVERLAP_DAYS, and the last PULL_OPEN_DAYS at least: an order that got its label days after it was made is
            // read when it does (the Codex check r1, MEDIUM 10)
            : min((new \DateTimeImmutable((string) $read))->modify('-' . self::PULL_OVERLAP_DAYS . ' days')->format('Y-m-d'),
                $today->modify('-' . self::PULL_OPEN_DAYS . ' days')->format('Y-m-d'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) !== 1 || $from > $to) {
            throw new CwException('bad_days', 'the days to read are not days, or the first is after the last', 422);
        }
        $now = Clock::db(($this->clock)());
        $this->db->exec('INSERT INTO carrier_label_pull (account_id, last_try_at) VALUES (?, ?) AS n ON DUPLICATE KEY UPDATE last_try_at = n.last_try_at',
            [$account['id'], $now]);
        try {
            $ids = [];
            $listed = [];
            for ($d = $from; $d <= $to; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
                foreach ($client->orders($d . 'T00:00:00', $d . 'T23:59:59') as $o) {
                    if (isset($o['orderIdentifier']) && is_int($o['orderIdentifier'])) {
                        $ids[$o['orderIdentifier']] = true;
                        $listed[$o['orderIdentifier']] = true;
                    }
                }
            }
            foreach ($this->db->column('SELECT source_id FROM carrier_label WHERE account_id = ? AND manifest_day IS NULL AND source_id IS NOT NULL AND created_on >= ?',
                [$account['id'], $today->modify('-' . self::PULL_OPEN_DAYS . ' days')->format('Y-m-d')]) as $sid) {
                $ids[(int) $sid] = true;
            }
            ksort($ids);
            $orders = $client->ordersByIds(array_keys($ids));
            // every order the days' lists named must come with its details, or no day is read (a part of a day's labels never ends its wait:
            // the Codex check r5, HIGH 1; DL81). An older label asked for again may be gone from Click & Drop: left as it is.
            $came = [];
            foreach ($orders as $o) {
                $came[(int) $o['orderIdentifier']] = true;
            }
            $missing = count(array_diff_key($listed, $came));
            if ($missing > 0) {
                throw new CwException('cnd_partial', "Click & Drop listed {$missing} order(s) whose details did not come: no day was read (the next pull reads them again)", 502);
            }
            $labels = ClickAndDropClient::labels($orders);
        } catch (CwException $e) {
            // CW's own words only (a code and an HTTP status): never an answer of the API, never the key.
            $this->db->exec('UPDATE carrier_label_pull SET last_error = ? WHERE account_id = ?', [mb_substr($e->errorCode . ': ' . $e->getMessage(), 0, 160), $account['id']]);
            throw $e;
        }
        // the labels, the days read and the checkpoint stored together, then those days planned again (importLabels; DL80, DL81)
        $r = $this->importLabels($caller, $account['id'], $labels, 'api', ['from' => $from, 'to' => $to, 'orders' => count($ids)], [$from, $to]);
        return $r + ['from' => $from, 'to' => $to, 'orders' => count($ids)];
    }

    /**
     * The nightly (DL66): every key of the live rows of the last $days days, of every active pooled account (or one), planned again.
     *
     * @return int the allocations ended or added
     */
    public function replan(Caller $caller, ?int $accountId = null, int $days = self::REPLAN_DAYS): int
    {
        $this->bills->authoriseWrite($caller);
        // the allocations of bills that no longer count ended, whatever the accounts' state (the Codex check r1, MEDIUM 8), and every label whose
        // committed price is on an order pinned (a pin a write left undone: DL75)
        $this->bills->inLock(function (): void {
            $this->sweep();
            self::pinCommitted($this->db);
            // the checks of the upload screen a day past their time gone (the Codex check r4, D3 LOW 6)
            $this->db->exec('DELETE FROM carrier_bill_check WHERE expires_at < ?', [Clock::db(($this->clock)()->modify('-1 day'))]);
        });
        $n = 0;
        foreach (CarrierSettings::pooledAccounts($this->db) as $account) {
            if (($accountId !== null && $account['id'] !== $accountId) || $account['state'] !== 'active') {
                continue;
            }
            $n += $this->bills->inLock(function () use ($caller, $account, $days): int {
                // read again once the lock is held: switched off meanwhile, not planned (the Codex check r3, new MEDIUM 3)
                $account = CarrierSettings::account($this->db, $account['id']);
                if ($account['state'] !== 'active') {
                    return 0;
                }
                $since = ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->modify("-{$days} days")->format('Y-m-d');
                $ctx = self::ctx($this->db, $account);
                $rows = self::rowsWhere($this->db, $account, 'r.service_date >= ?', [$since]);
                return $this->replanKeys($caller, $account, self::keysOf($this->db, $account, $ctx, $rows));
            });
        }
        return $n;
    }

    /**
     * The live allocations of an account against a plan from scratch of every key of its live rows (the rebuild check of the allocations, DL63):
     * each allocation identity stored and not planned ("- ..."), planned and not stored ("+ ..."). [] when they agree.
     *
     * @return list<string>
     */
    public static function drift(Db $db, int $accountId): array
    {
        $account = CarrierSettings::account($db, $accountId);
        $ctx = self::ctx($db, $account);
        $s = self::scope($db, $account, $ctx, self::keysOf($db, $account, $ctx, self::rowsWhere($db, $account, '1 = 1', [])), [], [], null);
        $out = [];
        foreach ($s['end'] as $a) {
            $out[] = '- ' . PooledPlanner::identity($a);
        }
        foreach ($s['add'] as $a) {
            $out[] = '+ ' . PooledPlanner::identity($a);
        }
        // a live allocation of a live bill's row that no key reached
        $rows = (int) $db->value("SELECT COUNT(*) FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' AND i.carrier = ? AND i.account = ? "
            . 'WHERE a.ended_at IS NULL', [$account['carrier'], $account['account_no']]);
        $seen = count($s['existing']);
        if ($rows !== $seen) {
            $out[] = "live allocations of the account: {$rows}, reached by its keys: {$seen}";
        }
        return $out;
    }

    // ---- the work ----------------------------------------------------------------------------------------------------------------------

    /**
     * A bill stored in three steps (DL68), all under the delivery lock: (A) the bill as "loading" (counting nowhere), its rows and its allocations
     * as planned now, in short transactions of their own (no order guard: nothing of it counts yet); (B) ONE guarded write: the plan taken again
     * with the loading bill's rows, the differences written (normally none for it; other bills' days planned again), the bill made live and the
     * bill it replaces superseded, the profit days moved by what starts and stops counting; (C) the replaced bill's allocations ended (they count
     * nowhere once it is superseded). When (B) fails, the loading bill is undone as "not stored" (its allocations ended): nothing counted.
     *
     * @param array<string, mixed> $bill
     * @return array{0: int, 1: string} the bill and when it was uploaded
     */
    private function store(Caller $caller, array $account, array $bill, ?array $old, array $audit = []): array
    {
        $this->sweep();
        [$id, $at] = $this->load($caller, $account, $bill, $old);
        try {
            $this->bills->guardedWrite(function (Db $db) use ($caller, $account, $bill, $old, $id, $audit): array {
                $ctx = self::ctx($db, $account);
                $gone = $old === null ? [] : [(int) $old['id']];
                $keys = self::keysOf($db, $account, $ctx, self::billRows($db, [$id, ...$gone]));
                $s = self::scope($db, $account, $ctx, $keys, [], $gone, $id);
                if (isset($audit['plan_sha256']) && self::planSha(self::split($s, $id, $old === null ? null : (int) $old['id'])) !== $audit['plan_sha256']) {
                    // the screen's Store stores only the split its check showed: a parcel or a label that came meanwhile changed it (the Codex check
                    // r4, D3 MEDIUM 1; DL80)
                    throw new CwException('check_changed', 'Where the bill\'s money would go changed since you checked it (a parcel or a label came in meanwhile). '
                        . 'Nothing was saved. Upload the bill again to check it and see the new split.', 409);
                }
                return self::guardedPlan($s, function (Db $db) use ($caller, $account, $bill, $old, $id, $s, $audit): int {
                    $this->bills->authoriseWrite($caller); // again once everything is held (DL13)
                    $now = Clock::db(($this->clock)());
                    if ($old !== null) {
                        $db->exec("UPDATE carrier_invoice SET status = 'superseded', ended_by = ?, ended_actor = ?, ended_at = ? WHERE id = ? AND status = 'live'",
                            [$caller->staffUserId, $caller->actor, $now, (int) $old['id']]);
                    }
                    try {
                        $live = $db->exec("UPDATE carrier_invoice SET status = 'live' WHERE id = ? AND status = 'loading'", [$id]);
                    } catch (\PDOException $e) {
                        if (Db::driverCode($e) === 1062) {
                            throw new CwException('already_uploaded', "Royal Mail bill {$bill['invoice_no']} was uploaded meanwhile: nothing was saved.", 409);
                        }
                        throw $e;
                    }
                    if ($live !== 1) {
                        throw new CwException(self::RETRY, 'the bill being stored changed meanwhile', 409);
                    }
                    self::apply($db, $s, $now);
                    // its labels' pins are due from what this write makes live: done right after it, or by the next write or the nightly (DL80)
                    $db->exec('INSERT INTO carrier_pin_due (invoice_id, live_at) VALUES (?, ?)', [$id, $now]);
                    Audit::write($db, $caller, $old === null ? 'delivery_bill.upload' : 'delivery_bill.replace', 'carrier_invoice', (string) $id, null, [
                        'carrier' => $account['carrier'], 'account' => $bill['account'], 'invoice' => $bill['invoice_no'], 'rows' => count($bill['rows']),
                        'net_pence' => $bill['net'], 'sha256' => $bill['sha256'], 'allocations' => count(array_filter($s['desired'],
                            static fn (array $a): bool => $a['invoice_id'] === $id)), 'allocations_ended' => count($s['end']), 'allocations_added' => count($s['add'])]
                        + ($old === null ? [] : ['replaces' => (int) $old['id']]) + array_diff_key($audit, ['plan_sha256' => true]));
                    return $id;
                });
            });
        } catch (\Throwable $e) {
            $this->abandon($id);
            throw $e;
        }
        try {
            self::pinDue($this->db, $id);
        } catch (\Throwable) {
            // left due: the next write or the nightly pins it (sweep())
        }
        $this->sweepLater();
        return [$id, $at]; // read before (the connection may be gone after the write: the Codex check r2, r1 MEDIUM 8)
    }

    /**
     * The labels whose live allocation is on an order pinned to that CW parcel (carrier_label.cw_*), from what was COMMITTED (never from a
     * plan not yet checked: the Codex check r3, new HIGH 1; DL75): right after a bill's write, and by every sweep (a missed pin is caught up).
     * No money moves here (the allocation is on that order already), so no order guard: one statement.
     */
    private static function pinCommitted(Db $db, ?int $invoiceId = null): void
    {
        $db->exec('UPDATE carrier_label l JOIN (SELECT a.label_id, MIN(a.channel_id) AS ch, MIN(a.order_ref) AS oref, MIN(a.parcel_ref) AS pref FROM carrier_allocation a '
            . "JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' WHERE a.ended_at IS NULL AND a.dest = 'order' AND a.label_id IS NOT NULL"
            . ($invoiceId === null ? '' : ' AND a.invoice_id = ' . $invoiceId) . ' GROUP BY a.label_id) v ON v.label_id = l.id '
            . 'SET l.cw_channel_id = v.ch, l.cw_order_ref = v.oref, l.cw_parcel_ref = v.pref WHERE l.cw_parcel_ref IS NULL');
    }

    /**
     * The pins a bill's write left due (carrier_pin_due, written IN that write): every label whose allocation on an order was live when the write
     * committed is pinned to that CW parcel (unless pinned already), then the row goes. Done right after the write; a crash in between leaves
     * it for the next write or the nightly (sweep()), even when the bill was undone meanwhile (the Codex check r4, MEDIUM; DL80). No money moves.
     */
    private static function pinDue(Db $db, int $invoiceId): void
    {
        $at = $db->value('SELECT live_at FROM carrier_pin_due WHERE invoice_id = ?', [$invoiceId]);
        if ($at === null) {
            return;
        }
        $db->exec('UPDATE carrier_label l JOIN (SELECT a.label_id, MIN(a.channel_id) AS ch, MIN(a.order_ref) AS oref, MIN(a.parcel_ref) AS pref FROM carrier_allocation a '
            . "WHERE a.invoice_id = ? AND a.dest = 'order' AND a.label_id IS NOT NULL AND a.made_at <= ? AND (a.ended_at IS NULL OR a.ended_at > ?) GROUP BY a.label_id) v "
            . 'ON v.label_id = l.id SET l.cw_channel_id = v.ch, l.cw_order_ref = v.oref, l.cw_parcel_ref = v.pref WHERE l.cw_parcel_ref IS NULL', [$invoiceId, $at, $at]);
        $db->exec('DELETE FROM carrier_pin_due WHERE invoice_id = ?', [$invoiceId]);
    }

    /**
     * Step A of store(): the bill as "loading" with its rows (one transaction), then its allocations as planned now, 500 a transaction. Nothing
     * of it counts until step B makes it live.
     *
     * @param array<string, mixed> $bill
     * @param array<string, mixed>|null $old
     * @return array{0: int, 1: string} the bill and when it was uploaded
     */
    private function load(Caller $caller, array $account, array $bill, ?array $old): array
    {
        $ctx = self::ctx($this->db, $account);
        $rows = self::newRows($bill);
        $gone = $old === null ? [] : [(int) $old['id']];
        $s = self::scope($this->db, $account, $ctx, self::keysOf($this->db, $account, $ctx, [...$rows, ...self::billRows($this->db, $gone)]), $rows, $gone, null);
        $at = Clock::db(($this->clock)());
        $id = $this->db->transaction(function (Db $db) use ($caller, $account, $bill, $old, $at): int {
            $id = $db->insert('INSERT INTO carrier_invoice (carrier, account, invoice_no, invoice_date, period_from, period_to, channel_id, net_pence, vat_pence, '
                . 'gross_pence, line_count, file_sha256, status, supersedes_id, uploaded_by, uploaded_actor, uploaded_at) '
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'loading', ?, ?, ?, ?)",
                [$account['carrier'], $bill['account'], $bill['invoice_no'], $bill['invoice_date'], $bill['period_from'], $bill['period_to'], $account['main_channel'],
                    $bill['net'], $bill['vat'], $bill['gross'], count($bill['rows']), $bill['sha256'], $old === null ? null : (int) $old['id'], $caller->staffUserId,
                    $caller->actor, $at]);
            $cols = ['invoice_id', 'row_no', 'service_date', 'product_code', 'qty', 'net_pence', 'gross_pence', 'vat_code', 'ref_kind', 'ref_no', 'sales_order'];
            foreach (array_chunk($bill['rows'], 500) as $chunk) {
                $params = [];
                foreach ($chunk as $r) {
                    array_push($params, $id, $r['row_no'], $r['service_date'], $r['product_code'], $r['qty'], $r['net'], $r['gross'], $r['vat_code'], $r['ref_kind'],
                        $r['ref_no'], $r['sales_order']);
                }
                $db->exec('INSERT INTO carrier_bill_row (' . implode(', ', $cols) . ') VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($cols), '?')) . ')')), $params);
            }
            return $id;
        });
        $mine = array_values(array_filter($s['desired'], static fn (array $a): bool => $a['invoice_id'] === 0));
        $now = Clock::db(($this->clock)());
        // Each chunk's row waits bounded (an order row a snapshot holds is waited for HOLD_WAIT_SEC at most, then the chunk tried again a moment
        // later, LOAD_TRIES times, then busy): a snapshot that waits for this chunk's foreign-key read of its order waits that long at most (the
        // Codex check r1, MEDIUM 7).
        $rowWait = $this->db->value('SELECT @@SESSION.innodb_lock_wait_timeout');
        $this->db->exec('SET SESSION innodb_lock_wait_timeout = ' . DeliveryBills::HOLD_WAIT_SEC);
        try {
            foreach (array_chunk($mine, 500) as $chunk) {
                for ($try = 1; ; $try++) {
                    try {
                        $this->db->transaction(static function (Db $db) use ($chunk, $id, $now): void {
                            self::insert($db, array_map(static fn (array $a): array => ['invoice_id' => $id] + $a, $chunk), $now);
                        }, 0);
                        break;
                    } catch (\PDOException $e) {
                        if (!in_array(Db::driverCode($e), [1205, 1213], true) || $try >= self::LOAD_TRIES) {
                            throw in_array(Db::driverCode($e), [1205, 1213], true)
                                ? new CwException('busy', 'an order of this bill is being stored right now: nothing was saved; try again in a minute', 503) : $e;
                        }
                        usleep(random_int(150_000, 300_000));
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->abandon($id);
            throw $e;
        } finally {
            $this->db->exec('SET SESSION innodb_lock_wait_timeout = ' . (int) $rowWait);
        }
        return [$id, $at];
    }

    /** A bill left "loading" (its write failed): undone as "not stored" (it never counted), its allocations ended. */
    private function abandon(int $id): void
    {
        try {
            $this->db->exec("UPDATE carrier_invoice SET status = 'undone', ended_actor = ?, ended_at = ? WHERE id = ? AND status = 'loading'",
                [self::NOT_STORED, Clock::db(($this->clock)()), $id]);
            $this->sweep();
        } catch (\Throwable) {
            // the connection is gone: the next write or the nightly (sweep) does it
        }
    }

    /**
     * sweep() after a write that is done: a failure there never says the write failed (it is committed; the allocations it leaves count nowhere
     * and the next write or the nightly ends them: the Codex check r1, MEDIUM 8).
     */
    private function sweepLater(): void
    {
        try {
            $this->sweep();
        } catch (\Throwable) {
            // the nightly (replan) sweeps
        }
    }

    /**
     * Bills left "loading" by a write that did not end (a crash) undone as "not stored", and the live allocations of every bill that is not
     * live ended, 1,000 a transaction (they count nowhere: no profit figure moves, no order guard needed). Under the delivery lock.
     */
    /** The pins every earlier write left due (a crash right after it), from what each made live, whatever happened to its bill since (DL80). */
    private function drainPins(): void
    {
        foreach (array_map('intval', $this->db->column('SELECT invoice_id FROM carrier_pin_due ORDER BY invoice_id')) as $due) {
            self::pinDue($this->db, $due);
        }
    }

    private function sweep(): void
    {
        $this->drainPins();
        $now = Clock::db(($this->clock)());
        $this->db->exec("UPDATE carrier_invoice SET status = 'undone', ended_actor = ?, ended_at = ? WHERE status = 'loading'", [self::NOT_STORED, $now]);
        do {
            $ids = array_map('intval', $this->db->column("SELECT a.id FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id "
                . "AND i.status IN ('superseded', 'undone') WHERE a.ended_at IS NULL ORDER BY a.id LIMIT 1000"));
            if ($ids !== []) {
                $this->db->exec('UPDATE carrier_allocation SET ended_at = ? WHERE ended_at IS NULL AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
                    [$now, ...$ids]);
            }
        } while (count($ids) === 1000);
    }

    /**
     * The keys planned again, a few a guarded write (KEYS_PER_WRITE; FLAT_PER_WRITE day x product keys), each written only when it changes
     * something.
     *
     * @param list<string> $keys
     * @return int the allocations ended or added
     */
    private function replanKeys(Caller $caller, array $account, array $keys): int
    {
        $flat = array_values(array_filter($keys, static fn (string $k): bool => str_starts_with($k, 'F|')));
        $other = array_values(array_filter($keys, static fn (string $k): bool => !str_starts_with($k, 'F|')));
        $n = 0;
        foreach ([...array_chunk($flat, self::FLAT_PER_WRITE), ...array_chunk($other, self::KEYS_PER_WRITE)] as $batch) {
            $n += $this->bills->guardedWrite(function (Db $db) use ($caller, $account, $batch): array {
                $ctx = self::ctx($db, $account);
                $s = self::scope($db, $account, $ctx, $batch, [], [], null);
                return self::guardedPlan($s, function (Db $db) use ($caller, $account, $s): int {
                    if ($s['end'] === [] && $s['add'] === []) {
                        return 0;
                    }
                    $this->bills->authoriseWrite($caller);
                    self::apply($db, $s, Clock::db(($this->clock)()));
                    Audit::write($db, $caller, 'delivery_bill.replan', 'carrier_account', (string) $account['id'], null, ['keys' => count($s['keys']),
                        'allocations_ended' => count($s['end']), 'allocations_added' => count($s['add'])]);
                    return count($s['end']) + count($s['add']);
                });
            });
        }
        return $n;
    }

    /**
     * A scope's write as DeliveryBills::guardedWrite takes it (DL68): the facts of what stops counting (the allocations ended of live bills, and
     * every allocation of the bills that stop counting) and of what starts (the allocations added to live bills, and every allocation of the bill
     * made live), and the orders the plan put new labels on with the snapshot each was read at (a snapshot of one since: the plan is old).
     *
     * @param array<string, mixed> $s scope()
     * @param \Closure(Db): mixed $write
     * @return array<string, mixed>
     */
    private static function guardedPlan(array $s, \Closure $write): array
    {
        $gone = array_flip($s['gone']);
        $loading = $s['loading'];
        $before = [];
        foreach ($s['end'] as $a) {
            if (!isset($gone[$a['invoice_id']]) && $a['invoice_id'] !== $loading) {
                $before[] = $a;
            }
        }
        foreach ($s['existing'] as $a) {
            if (isset($gone[$a['invoice_id']])) {
                $before[] = $a;
            }
        }
        $after = array_values(array_filter($s['add'], static fn (array $a): bool => $a['invoice_id'] !== $loading));
        foreach ($s['desired'] as $a) {
            if ($loading !== null && $a['invoice_id'] === $loading) {
                $after[] = $a;
            }
        }
        $owners = [];
        foreach ($after as $a) {
            if ($a['dest'] === 'order') {
                $owners[$a['channel_id'] . '|' . strtolower((string) $a['order_ref'])] = true;
            }
        }
        return ['before' => array_values(array_filter(array_map([self::class, 'fact'], $before))), 'after' => array_values(array_filter(array_map([self::class, 'fact'],
            $after))), 'versions' => array_intersect_key($s['versions'], $owners), 'write' => $write];
    }

    /**
     * An allocation as a fact of the profit days (DeliveryBills::contributions): on an order (matched), a shop's "not on an order" on its day
     * (not_matched, the allocation's own shop); anything else counts in no profit (null).
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>|null
     */
    public static function fact(array $a): ?array
    {
        return match ($a['dest']) {
            'order' => ['live' => true, 'state' => 'matched', 'ch' => (int) $a['channel_id'], 'ref' => (string) $a['order_ref'], 'inv_ch' => (int) $a['channel_id'],
                'date' => (string) $a['day'], 'cost' => (int) $a['pence']],
            'shop' => ['live' => true, 'state' => 'not_matched', 'ch' => null, 'ref' => null, 'inv_ch' => (int) $a['channel_id'], 'date' => (string) $a['day'],
                'cost' => (int) $a['pence']],
            default => null,
        };
    }

    /**
     * The allocations that stop counting ended (each exactly once: one ended meanwhile means the plan is old) and the new ones added. The
     * allocations of the bills going (undone, replaced) are left to sweep(): they count nowhere once their bill's status says so.
     *
     * @param array<string, mixed> $s
     */
    private static function apply(Db $db, array $s, string $now): void
    {
        $gone = array_flip($s['gone']);
        $end = array_values(array_filter($s['end'], static fn (array $a): bool => !isset($gone[$a['invoice_id']])));
        foreach (array_chunk(array_map(static fn (array $a): int => (int) $a['id'], $end), 500) as $chunk) {
            $done = $db->exec('UPDATE carrier_allocation SET ended_at = ? WHERE ended_at IS NULL AND id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [$now, ...$chunk]);
            if ($done !== count($chunk)) {
                throw new CwException(self::RETRY, 'an allocation changed while the bill was saved', 409);
            }
        }
        self::insert($db, $s['add'], $now);
        self::pin($db, $s['add']);
    }

    /**
     * Each label a plan put on an order (not yet pinned) keeps that CW parcel (carrier_label.cw_*): it stays on it whatever comes later, its
     * price counting or not at a moment (a day waiting again for a late label, its bill undone; the Codex check r2, new HIGH 1; DL74). Written
     * only by a guarded write, after its snapshot check (the Codex check r3, new HIGH 1; DL75).
     *
     * @param list<array<string, mixed>> $allocations
     */
    private static function pin(Db $db, array $allocations): void
    {
        $rows = [];
        foreach ($allocations as $a) {
            if ($a['dest'] === 'order' && $a['label_id'] !== null) {
                $rows[(int) $a['label_id']] = [(int) $a['label_id'], (int) $a['channel_id'], (string) $a['order_ref'], (string) $a['parcel_ref']];
            }
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            $db->exec('UPDATE carrier_label l JOIN (' . implode(' UNION ALL ', array_fill(0, count($chunk), 'SELECT ? AS id, ? AS ch, ? AS oref, ? AS pref')) . ') v ON v.id = l.id '
                . 'SET l.cw_channel_id = v.ch, l.cw_order_ref = v.oref, l.cw_parcel_ref = v.pref WHERE l.cw_parcel_ref IS NULL', array_merge(...$chunk));
        }
    }

    /** @param list<array<string, mixed>> $allocations */
    private static function insert(Db $db, array $allocations, string $now): void
    {
        $cols = ['invoice_id', 'row_no', 'kind', 'how', 'label_id', 'dest', 'channel_id', 'order_ref', 'parcel_ref', 'party_id', 'held_reason', 'day', 'pence', 'made_at'];
        foreach (array_chunk($allocations, 500) as $chunk) {
            $params = [];
            foreach ($chunk as $a) {
                array_push($params, (int) $a['invoice_id'], $a['row_no'], $a['kind'], $a['how'], $a['label_id'], $a['dest'], $a['channel_id'], $a['order_ref'],
                    $a['parcel_ref'], $a['party_id'], $a['held_reason'], $a['day'], $a['pence'], $now);
            }
            $db->exec('INSERT INTO carrier_allocation (' . implode(', ', $cols) . ') VALUES '
                . implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($cols), '?')) . ')')), $params);
        }
    }

    /**
     * Everything one plan needs, read: the keys' live rows (with $newRows, without the bills in $gone), their labels and where each belongs, the
     * plan, the live allocations it replaces (the rows' and every one of $gone's), and the difference: what ends, what is added (an allocation
     * the same in both stays as it is: no churn).
     *
     * @param list<string> $keys
     * @param list<array<string, mixed>> $newRows rows of the bill being stored (invoice_id 0)
     * @param list<int> $gone bills that stop counting in this write
     * @return array{keys: list<string>, rows: list<array<string, mixed>>, labels: array<int, array<string, mixed>>, sticky: array<int, array<string, mixed>>,
     *   dests: array<int, array<string, mixed>>, desired: list<array<string, mixed>>, existing: list<array<string, mixed>>, end: list<array<string, mixed>>,
     *   add: list<array<string, mixed>>}
     */
    private static function scope(Db $db, array $account, array $ctx, array $keys, array $newRows, array $gone, ?int $loading): array
    {
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        $bandServices = PooledPlanner::bandServices($ctx);
        $kinds = static fn (string $kind): array => array_keys(array_filter($ctx['products'], static fn (array $p): bool => $p['kind'] === $kind));
        $marks = static fn (int $n): string => implode(', ', array_fill(0, $n, '?'));
        $cols = 'id, tracking_no, reference, channel_code, service_code, manifest_day, manifest_no, weight_g, sales_order';
        $kindOf = static fn (array $r): string => $ctx['products'][(string) $r['product_code']]['kind'] ?? 'unknown';
        // The keys' labels and their rows, found broadly; then closed over (the Codex check r1, MEDIUM 6): every label of a sales order a row
        // names (so a row's manifest is read from all of them), and the key each row found has now and the manifests its live allocations' labels
        // are on (the group it leaves) planned with them, until nothing new comes (3 rounds at most).
        for ($round = 0; ; $round++) {
            $flat = [];
            $manifests = [];
            $sales = [];
            $own = [];
            foreach ($keys as $k) {
                $p = explode('|', $k);
                match ($p[0]) {
                    'F' => $flat[] = [$p[1], $p[2]],
                    'M' => $manifests[] = (int) $p[1],
                    'S' => $sales[] = $p[1],
                    default => $own[] = [(int) $p[1], (int) $p[2]],
                };
            }
            $labels = [];
            $take = static function (array $rows) use (&$labels): void {
                foreach ($rows as $l) {
                    $labels[(int) $l['id']] = self::label($l);
                }
            };
            foreach (array_chunk($flat, 200) as $chunk) {
                $pairs = [];
                foreach ($chunk as [$day, $product]) {
                    array_push($pairs, $day, (string) ($ctx['products'][$product]['service_code'] ?? ''));
                }
                $take($db->all("SELECT {$cols} FROM carrier_label WHERE account_id = ? AND (manifest_day, service_code) IN ("
                    . implode(', ', array_fill(0, count($chunk), '(?, ?)')) . ')', [$account['id'], ...$pairs]));
            }
            $bySales = static function (array $so) use ($db, $account, $bandServices, $cols, $marks, $take): void {
                foreach (array_chunk(array_values(array_unique($so)), 500) as $chunk) {
                    $take($db->all("SELECT {$cols} FROM carrier_label WHERE account_id = ? AND sales_order IN (" . $marks(count($chunk)) . ') AND service_code IN ('
                        . $marks(count($bandServices)) . ')', [$account['id'], ...$chunk, ...$bandServices]));
                }
            };
            if ($bandServices !== []) {
                foreach (array_chunk($manifests, 500) as $chunk) {
                    $take($db->all("SELECT {$cols} FROM carrier_label WHERE account_id = ? AND manifest_no IN (" . $marks(count($chunk)) . ') AND service_code IN ('
                        . $marks(count($bandServices)) . ')', [$account['id'], ...$chunk, ...$bandServices]));
                }
                $bySales($sales);
            }
            $soOfManifests = array_values(array_unique(array_filter(array_map(static fn (array $l): ?string => $l['manifest_no'] !== null
                && in_array((int) $l['manifest_no'], $manifests, true) ? $l['sales_order'] : null, $labels))));
            $rows = [];
            $where = [];
            $params = [];
            foreach (array_chunk($flat, 200) as $chunk) {
                $where[] = '(r.product_code, r.service_date) IN (' . implode(', ', array_fill(0, count($chunk), '(?, ?)')) . ')';
                foreach ($chunk as [$day, $product]) {
                    array_push($params, $product, $day);
                }
            }
            $band = $kinds('band');
            if ($band !== [] && ($manifests !== [] || $soOfManifests !== [])) {
                $w = [];
                $p = [];
                if ($manifests !== []) {
                    $w[] = "(r.ref_kind = 'manifest' AND r.ref_no IN (" . $marks(count($manifests)) . '))';
                    array_push($p, ...array_map('strval', $manifests));
                }
                if ($soOfManifests !== []) {
                    $w[] = 'r.sales_order IN (' . $marks(count($soOfManifests)) . ')';
                    array_push($p, ...$soOfManifests);
                }
                $where[] = '(r.product_code IN (' . $marks(count($band)) . ') AND (' . implode(' OR ', $w) . '))';
                array_push($params, ...$band, ...$p);
            }
            $ww = $kinds('wrong_weight');
            if ($ww !== [] && $sales !== []) {
                $m = $marks(count($sales));
                $where[] = '(r.product_code IN (' . $marks(count($ww)) . ") AND ((r.ref_kind = 'sales_order' AND r.ref_no IN ({$m})) OR r.sales_order IN ({$m})))";
                array_push($params, ...$ww, ...$sales, ...$sales);
            }
            foreach (array_chunk($own, 200) as $chunk) {
                $where[] = '(r.invoice_id, r.row_no) IN (' . implode(', ', array_fill(0, count($chunk), '(?, ?)')) . ')';
                foreach ($chunk as [$inv, $no]) {
                    array_push($params, $inv, $no);
                }
            }
            if ($where !== []) {
                $not = $gone === [] ? '' : ' AND i.id NOT IN (' . $marks(count($gone)) . ')';
                $rows = self::rowsWhere($db, $account, '(' . implode(' OR ', $where) . ')' . $not, [...$params, ...$gone], $loading);
            }
            $cand = [...$rows, ...$newRows];
            // every label of the sales orders the band rows name
            $rowSales = [];
            foreach ($cand as $r) {
                if ($kindOf($r) === 'band' && $r['sales_order'] !== null) {
                    $rowSales[] = (string) $r['sales_order'];
                }
            }
            if ($bandServices !== []) {
                $bySales($rowSales);
            }
            ksort($labels);
            $more = [];
            $stored = [];
            foreach ($cand as $r) {
                if ($kindOf($r) === 'band' || $kindOf($r) === 'wrong_weight') {
                    $more[PooledPlanner::key($r, $kindOf($r), $labels, $ctx)] = true;
                    if ((int) $r['invoice_id'] !== 0) {
                        $stored[] = $r;
                    }
                }
            }
            foreach (array_chunk($stored, 300) as $chunk) {
                $pairs = [];
                foreach ($chunk as $r) {
                    array_push($pairs, (int) $r['invoice_id'], (int) $r['row_no']);
                }
                foreach ($db->column('SELECT DISTINCT l.manifest_no FROM carrier_allocation a JOIN carrier_label l ON l.id = a.label_id WHERE a.ended_at IS NULL '
                    . 'AND l.manifest_no IS NOT NULL AND (a.invoice_id, a.row_no) IN (' . implode(', ', array_fill(0, count($chunk), '(?, ?)')) . ')', $pairs) as $m) {
                    $more['M|' . (int) $m] = true;
                }
            }
            // ... and the manifests of the bills going (an undone bill's Special Delivery rows leave their groups too: the Codex check r2, r1 MEDIUM 6)
            if ($gone !== [] && $round === 0) {
                foreach ($db->column('SELECT DISTINCT l.manifest_no FROM carrier_allocation a JOIN carrier_label l ON l.id = a.label_id WHERE a.ended_at IS NULL '
                    . 'AND l.manifest_no IS NOT NULL AND a.invoice_id IN (' . $marks(count($gone)) . ')', $gone) as $m) {
                    $more['M|' . (int) $m] = true;
                }
            }
            $new = array_values(array_diff(array_map('strval', array_keys($more)), $keys));
            if ($new === []) {
                break;
            }
            if ($round >= self::CLOSURE_ROUNDS) {
                throw new CwException(self::RETRY, 'the Special Delivery groups kept growing while they were read', 409); // never discarded: tried again
            }
            $keys = array_values(array_unique([...$keys, ...$new]));
            sort($keys, SORT_STRING);
        }
        $rows = array_values(array_filter($cand, static fn (array $r): bool => in_array(PooledPlanner::key($r, $kindOf($r), $labels, $ctx), $keys, true)));
        // the days waiting for their labels (the Codex checks r1 HIGH 1, r2; DL71, DL74): a day of the rows after the main shop's CW start with a
        // label of no service yet (the API brought it, Click & Drop's report not read), or with no label at all
        // the rows' days and their labels' manifest days (a Special Delivery manifest or its wrong weight waits on its labels' day too: the Codex
        // check r5, HIGH 2; DL81)
        $days = array_values(array_unique([...array_map(static fn (array $r): string => (string) $r['service_date'], $rows),
            ...array_values(array_filter(array_map(static fn (array $l): string => (string) ($l['manifest_day'] ?? ''), $labels), static fn (string $d): bool => $d !== ''))]));
        $ctx['waiting'] = [];
        $known = [];
        foreach (array_chunk($days, 300) as $chunk) {
            foreach ($db->all('SELECT manifest_day, COUNT(*) AS n, SUM(service_code IS NULL) AS open FROM carrier_label WHERE account_id = ? AND manifest_day IN ('
                . $marks(count($chunk)) . ') GROUP BY manifest_day', [$account['id'], ...$chunk]) as $d) {
                $known[(string) $d['manifest_day']] = (int) $d['open'] === 0;
            }
        }
        // an account with a key (its labels come by the nightly pull): a day waits until a SUCCESSFUL pull read THAT day, whatever a report
        // brought (a part of a day's labels, a failed first pull, a pull of later days: the Codex checks r3, r4 HIGH; DL80). An account read
        // by report only has no such record (DL77: Vape Big, off).
        $pulled = [];
        if ($account['key_name'] !== null) {
            foreach (array_chunk($days, 300) as $chunk) {
                foreach ($db->column('SELECT day FROM carrier_label_pull_day WHERE account_id = ? AND day IN (' . $marks(count($chunk)) . ')', [$account['id'], ...$chunk]) as $d) {
                    $pulled[(string) $d] = true;
                }
            }
        }
        foreach ($days as $d) {
            // a day after the main shop's CW start needs its labels, all with their service (on or before it: before CW start anyway); no "first
            // label's day" exception (the Codex check r2, r1 HIGH 1 PARTLY)
            $start = $ctx['main_start'] ?? null;
            if ($start !== null && $d > $start && (!($known[$d] ?? false) || ($account['key_name'] !== null && !isset($pulled[$d])))) {
                $ctx['waiting'][$d] = true;
            }
        }
        // labels' destinations; a label whose price is on an order now stays on it (sticky)
        // the label's own CW parcel, pinned when a plan first put it on an order (DL74); else a live allocation on an order (labels of before)
        $sticky = [];
        foreach (array_chunk(array_keys($labels), 500) as $chunk) {
            foreach ($db->all('SELECT id, cw_channel_id, cw_order_ref, cw_parcel_ref FROM carrier_label WHERE cw_parcel_ref IS NOT NULL AND id IN ('
                . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $l) {
                $sticky[(int) $l['id']] = ['dest' => 'order', 'channel_id' => (int) $l['cw_channel_id'], 'order_ref' => (string) $l['cw_order_ref'],
                    'parcel_ref' => (string) $l['cw_parcel_ref'], 'party_id' => null, 'held_reason' => null];
            }
            foreach ($db->all("SELECT a.label_id, a.channel_id, a.order_ref, a.parcel_ref FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id "
                . "AND i.status = 'live' WHERE a.ended_at IS NULL AND a.dest = 'order' AND a.kind IN ('label', 'wrong_weight') AND a.label_id IN ("
                . implode(', ', array_fill(0, count($chunk), '?')) . ') ORDER BY a.id', $chunk) as $a) {
                $sticky[(int) $a['label_id']] ??= ['dest' => 'order', 'channel_id' => (int) $a['channel_id'], 'order_ref' => (string) $a['order_ref'],
                    'parcel_ref' => (string) $a['parcel_ref'], 'party_id' => null, 'held_reason' => null];
            }
        }
        ksort($sticky);
        $versions = [];
        $dests = LabelDestinations::resolve($db, $account, $labels, $sticky, $versions);
        $withDest = [];
        foreach ($labels as $id => $l) {
            $withDest[$id] = $l + ['dest' => $dests[$id]];
        }
        $desired = PooledPlanner::plan($rows, $withDest, $ctx);
        // the live allocations this plan replaces: those of its rows, and every one of the bills going
        $existing = [];
        $stored = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['invoice_id'] !== 0));
        foreach (array_chunk($stored, 300) as $chunk) {
            $pairs = [];
            foreach ($chunk as $r) {
                array_push($pairs, (int) $r['invoice_id'], (int) $r['row_no']);
            }
            array_push($existing, ...$db->all('SELECT * FROM carrier_allocation WHERE ended_at IS NULL AND (invoice_id, row_no) IN ('
                . implode(', ', array_fill(0, count($chunk), '(?, ?)')) . ') ORDER BY id', $pairs));
        }
        if ($gone !== []) {
            array_push($existing, ...$db->all('SELECT * FROM carrier_allocation WHERE ended_at IS NULL AND invoice_id IN (' . implode(', ', array_fill(0, count($gone), '?'))
                . ') ORDER BY id', $gone));
        }
        $existing = array_map([self::class, 'stored'], $existing);
        $byIdentity = [];
        foreach ($existing as $a) {
            $byIdentity[PooledPlanner::identity($a)][] = $a;
        }
        $add = [];
        foreach ($desired as $a) {
            $k = PooledPlanner::identity($a);
            if (($byIdentity[$k] ?? []) !== []) {
                array_shift($byIdentity[$k]);
                continue;
            }
            $add[] = $a;
        }
        $end = [];
        foreach ($byIdentity as $set) {
            array_push($end, ...$set);
        }
        usort($end, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        return ['keys' => $keys, 'rows' => $rows, 'labels' => $labels, 'sticky' => $sticky, 'dests' => $dests, 'versions' => $versions, 'desired' => $desired,
            'existing' => $existing, 'end' => $end, 'add' => $add, 'gone' => array_map('intval', $gone), 'loading' => $loading];
    }

    /**
     * The keys of rows (PooledPlanner::key), reading the labels of their sales orders (a Special Delivery row naming only its sales order).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function keysOf(Db $db, array $account, array $ctx, array $rows): array
    {
        $sales = [];
        foreach ($rows as $r) {
            if ($r['sales_order'] !== null) {
                $sales[(string) $r['sales_order']] = true;
            }
        }
        $labels = [];
        foreach (array_chunk(array_keys($sales), 500) as $chunk) {
            foreach ($db->all('SELECT id, tracking_no, reference, channel_code, service_code, manifest_day, manifest_no, weight_g, sales_order FROM carrier_label '
                . 'WHERE account_id = ? AND sales_order IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', [$account['id'], ...array_map('strval', $chunk)]) as $l) {
                $labels[(int) $l['id']] = self::label($l);
            }
        }
        $keys = [];
        foreach ($rows as $r) {
            $keys[PooledPlanner::key($r, $ctx['products'][(string) $r['product_code']]['kind'] ?? 'unknown', $labels, $ctx)] = true;
        }
        $keys = array_keys($keys);
        sort($keys, SORT_STRING);
        return array_map('strval', $keys);
    }

    /**
     * The keys labels touch: their day x product (a flat service), their manifest and their sales order (a band service).
     *
     * @param list<int> $ids
     * @return list<string>
     */
    private static function keysOfLabels(Db $db, array $account, array $ids): array
    {
        $ctx = self::ctx($db, $account);
        $flat = [];
        foreach ($ctx['products'] as $code => $p) {
            if ($p['kind'] === 'flat' && $p['service_code'] !== null) {
                $flat[$p['service_code']] = $code;
            }
        }
        $band = PooledPlanner::bandServices($ctx);
        $keys = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($db->all('SELECT service_code, manifest_day, manifest_no, sales_order FROM carrier_label WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                $chunk) as $l) {
                if ($l['manifest_day'] !== null && isset($flat[(string) $l['service_code']])) {
                    $keys['F|' . $l['manifest_day'] . '|' . $flat[(string) $l['service_code']]] = true;
                }
                if (in_array((string) $l['service_code'], $band, true)) {
                    if ($l['manifest_no'] !== null) {
                        $keys['M|' . (int) $l['manifest_no']] = true;
                    }
                    if ($l['sales_order'] !== null) {
                        $keys['S|' . $l['sales_order']] = true;
                    }
                }
            }
        }
        // the rows of the labels' manifest days and the day after (a manifest closed after midnight is billed on the next day): a day that waited
        // for its labels, a Special Delivery row of no batch (DL71)
        $days = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($db->column('SELECT DISTINCT manifest_day FROM carrier_label WHERE manifest_day IS NOT NULL AND id IN (' . implode(', ', array_fill(0, count($chunk), '?'))
                . ')', $chunk) as $d) {
                $days[(string) $d] = true;
                $days[(new \DateTimeImmutable((string) $d))->modify('+1 day')->format('Y-m-d')] = true;
            }
        }
        foreach (array_chunk(array_keys($days), 200) as $chunk) {
            foreach (self::keysOf($db, $account, $ctx, self::rowsWhere($db, $account, 'r.service_date IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                array_map('strval', $chunk))) as $k) {
                $keys[$k] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys, SORT_STRING);
        return array_map('strval', $keys);
    }

    /**
     * Labels stored: new ones added, empty fields filled (the first source's value stays), in one transaction (no money moves here).
     *
     * @param list<array<string, mixed>> $labels
     * @return array{added: int, filled: int, differs: int, changed: list<int>, all: list<int>}
     */
    private function upsert(Caller $caller, array $account, array $labels, string $source, array $audit): array
    {
        if (!in_array($source, ['api', 'report'], true)) {
            throw new CwException('bad_source', 'a label comes from the API or the report', 500);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $account, $labels, $source, $audit): array {
            $now = Clock::db(($this->clock)());
            $byTracking = [];
            foreach ($labels as $l) {
                $t = strtoupper((string) ($l['tracking_no'] ?? ''));
                // a tracking number only in its carrier's shape (a phone number there is never kept: the Codex check r1, MEDIUM 11)
                if (preg_match(self::TRACKING[$account['carrier']] ?? '/^(?!)$/', $t) !== 1 || isset($byTracking[$t])) {
                    continue;
                }
                $byTracking[$t] = self::clean($l);
            }
            $added = 0;
            $filled = 0;
            $differs = 0;
            $write = [];
            foreach (array_chunk(array_keys($byTracking), 500, false) as $chunk) {
                $have = [];
                foreach ($db->all('SELECT * FROM carrier_label WHERE account_id = ? AND tracking_no IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') FOR UPDATE',
                    [$account['id'], ...array_map('strval', $chunk)]) as $r) {
                    $have[strtoupper((string) $r['tracking_no'])] = $r;
                }
                foreach ($chunk as $t) {
                    $t = (string) $t;
                    $l = $byTracking[$t];
                    if (!isset($have[$t])) {
                        $added++;
                        $write[$t] = $l;
                        continue;
                    }
                    $row = $have[$t];
                    $fill = false;
                    foreach (self::FILL as $f) {
                        if ($l[$f] === null) {
                            continue;
                        }
                        if ($row[$f] === null) {
                            // a manifest number only with its day (the table's CHECK): both from the same source
                            if ($f === 'manifest_no' && $row['manifest_day'] === null && $l['manifest_day'] === null) {
                                continue;
                            }
                            $fill = true;
                        } elseif ((string) $row[$f] !== (string) $l[$f] && !($f === 'manifested_at' || $f === 'created_on')) {
                            $differs++;
                        }
                    }
                    if ($fill) {
                        $filled++;
                        $write[$t] = $l;
                    }
                }
            }
            $cols = ['account_id', 'tracking_no', 'source', ...self::FILL, 'first_at', 'updated_at'];
            foreach (array_chunk($write, 500, true) as $chunk) {
                $params = [];
                foreach ($chunk as $t => $l) {
                    array_push($params, $account['id'], (string) $t, $source, ...[...array_map(static fn (string $f): mixed => $l[$f], self::FILL), $now, $now]);
                }
                $db->exec('INSERT INTO carrier_label (' . implode(', ', $cols) . ') VALUES ' . implode(', ', array_fill(0, count($chunk), '(' . implode(', ',
                    array_fill(0, count($cols), '?')) . ')')) . ' AS n ON DUPLICATE KEY UPDATE '
                    // the manifest's day first (the CHECK reads the row as it is being written, left to right)
                    . 'manifest_day = COALESCE(carrier_label.manifest_day, n.manifest_day), '
                    . implode(', ', array_map(static fn (string $f): string => "{$f} = COALESCE(carrier_label.{$f}, n.{$f})", array_diff(self::FILL, ['manifest_day'])))
                    . ', updated_at = n.updated_at', $params);
            }
            $all = [];
            foreach (array_chunk(array_keys($byTracking), 500) as $chunk) {
                foreach ($db->column('SELECT id FROM carrier_label WHERE account_id = ? AND tracking_no IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                    [$account['id'], ...array_map('strval', $chunk)]) as $id) {
                    $all[] = (int) $id;
                }
            }
            sort($all);
            $changed = [];
            foreach (array_chunk(array_keys($write), 500) as $chunk) {
                foreach ($db->column('SELECT id FROM carrier_label WHERE account_id = ? AND tracking_no IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                    [$account['id'], ...array_map('strval', $chunk)]) as $id) {
                    $changed[] = (int) $id;
                }
            }
            sort($changed);
            Audit::write($db, $caller, 'carrier_label.import', 'carrier_account', (string) $account['id'], null, ['source' => $source, 'labels' => count($byTracking),
                'added' => $added, 'filled' => $filled, 'differs' => $differs] + $audit);
            return ['added' => $added, 'filled' => $filled, 'differs' => $differs, 'changed' => $changed, 'all' => $all];
        });
    }

    /**
     * A label's fields as stored: each only in its shape (anything else: empty).
     *
     * @param array<string, mixed> $l
     * @return array<string, mixed>
     */
    private static function clean(array $l): array
    {
        $s = static fn (string $f, string $re): ?string => is_string($l[$f] ?? null) && preg_match($re, $l[$f]) === 1 ? $l[$f] : null;
        $day = $s('manifest_day', '/^\d{4}-\d{2}-\d{2}$/D');
        $manifest = is_int($l['manifest_no'] ?? null) && $l['manifest_no'] > 0 && $day !== null ? $l['manifest_no'] : null;
        $time = static fn (string $f): ?string => is_string($l[$f] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/D', $l[$f]) === 1 ? $l[$f] : null;
        return ['source_id' => is_int($l['source_id'] ?? null) && $l['source_id'] > 0 ? $l['source_id'] : null, 'reference' => $s('reference', '/^#?[0-9]{1,9}$/D'),
            'channel_code' => $s('channel_code', '/^[a-z][a-z0-9_]{1,15}$/D'), 'service_code' => $s('service_code', self::SERVICE_CODE),
            'package' => in_array($l['package'] ?? null, ['letter', 'large_letter', 'parcel', 'other'], true) ? $l['package'] : null,
            'weight_g' => is_int($l['weight_g'] ?? null) && $l['weight_g'] >= 0 ? $l['weight_g'] : null, 'created_on' => $time('created_on'),
            'manifested_at' => $time('manifested_at'), 'manifest_no' => $manifest, 'manifest_day' => $day, 'sales_order' => $s('sales_order', '/^[0-9]{10}$/D')];
    }

    // ---- reading -------------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> an active pooled account */
    private static function active(Db $db, int $accountId): array
    {
        $a = CarrierSettings::account($db, $accountId);
        if ($a['family'] !== 'pooled') {
            throw new CwException('bad_carrier', 'that account\'s bills are not read here', 422, ['field' => 'account']);
        }
        if ($a['state'] !== 'active') {
            throw new CwException('account_off', sprintf('carrier account %s is not switched on in CW yet (it is switched on once its first bill ties by the day rule: '
                . 'tell the CW team). Nothing was saved.', $a['account_no']), 422, ['field' => 'account']);
        }
        return $a;
    }

    /**
     * A corrected file of this bill can replace it: the bill is live, and the file is the same bill (number and account).
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $bill
     */
    private static function replaceable(array $old, array $bill): void
    {
        if ($old['status'] !== 'live') {
            throw new CwException('not_live', 'this bill is no longer in the profit (' . ($old['status'] === 'undone' ? 'its upload was undone' : 'a corrected file replaced it')
                . '): upload the file as a new bill instead. Nothing was saved.', 409);
        }
        if (self::key($bill) !== (string) $old['live_key']) {
            throw new CwException('other_bill', sprintf('this file is Royal Mail bill %s of account %s, not bill %s of account %s: nothing was saved.', $bill['invoice_no'],
                $bill['account'], (string) $old['invoice_no'], (string) $old['account']), 422);
        }
    }

    /**
     * @param array<string, mixed> $bill
     * @param array<string, mixed> $live
     */
    private static function alreadyUploaded(array $bill, array $live): CwException
    {
        return new CwException('already_uploaded', sprintf('Royal Mail bill %s was uploaded on %s and is in the profit already. To load a corrected file of it, open '
            . 'the bill and use "Replace with a corrected file". Nothing was saved.', $bill['invoice_no'], self::when((string) $live['uploaded_at'])), 409,
            ['invoice_id' => (int) $live['id']]);
    }

    /** @return array<string, mixed> the bill read; its printed account must be the account chosen */
    private static function read(Db $db, array $account, string $bytes): array
    {
        $bill = RoyalMailBillPage::parse($bytes, array_keys(CarrierSettings::products($db, $account['carrier'])));
        if ($bill['account'] !== $account['account_no']) {
            throw new CwException('other_account', sprintf('this is a bill of account %s, not of account %s: choose its account and upload it again. Nothing was saved.',
                preg_match('/^[0-9]{6,12}$/D', $bill['account']) === 1 ? $bill['account'] : 'another', $account['account_no']), 422, ['field' => 'account']);
        }
        return $bill;
    }

    /** The account of a stored bill. @param array<string, mixed> $inv */
    private static function accountOf(Db $db, array $inv): int
    {
        $id = $db->value('SELECT id FROM carrier_account WHERE carrier = ? AND account_no = ?', [(string) $inv['carrier'], (string) $inv['account']]);
        if ($id === null || (string) $inv['carrier'] === 'dpd') {
            throw new CwException('other_carrier', 'this bill is not a Royal Mail bill of an account CW knows: nothing was changed.', 409);
        }
        return (int) $id;
    }

    /** @param array<string, mixed> $bill */
    private static function key(array $bill): string
    {
        return $bill['carrier'] . ':' . $bill['account'] . ':' . $bill['invoice_no'];
    }

    /** A bill's order among the account's bills (the first bill of a day is the one earliest in it): its date, then its number. */
    private static function rank(string $date, string $no): string
    {
        return $date . '|' . str_pad($no, 20, '0', STR_PAD_LEFT);
    }

    /** @return list<array<string, mixed>> the rows of a bill being stored (invoice_id 0) */
    private static function newRows(array $bill): array
    {
        return array_map(static fn (array $r): array => ['invoice_id' => 0, 'row_no' => (int) $r['row_no'], 'rank' => self::rank($bill['invoice_date'], $bill['invoice_no']),
            'service_date' => (string) $r['service_date'], 'product_code' => (string) $r['product_code'], 'qty' => (int) $r['qty'], 'net' => (int) $r['net'],
            'ref_kind' => (string) $r['ref_kind'], 'ref_no' => $r['ref_no'], 'sales_order' => $r['sales_order']], $bill['rows']);
    }

    /**
     * The live rows of the account's bills that match a condition.
     *
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private static function rowsWhere(Db $db, array $account, string $where, array $params, ?int $loading = null): array
    {
        return array_map(static fn (array $r): array => ['invoice_id' => (int) $r['invoice_id'], 'row_no' => (int) $r['row_no'],
            'rank' => self::rank((string) $r['invoice_date'], (string) $r['invoice_no']), 'service_date' => (string) $r['service_date'],
            'product_code' => (string) $r['product_code'], 'qty' => (int) $r['qty'], 'net' => (int) $r['net_pence'], 'ref_kind' => (string) $r['ref_kind'],
            'ref_no' => $r['ref_no'] === null ? null : (string) $r['ref_no'], 'sales_order' => $r['sales_order'] === null ? null : (string) $r['sales_order']],
            $db->all('SELECT r.*, i.invoice_date, i.invoice_no FROM carrier_bill_row r JOIN carrier_invoice i ON i.id = r.invoice_id AND (i.status = \'live\''
                . ($loading === null ? '' : ' OR i.id = ' . $loading) . ") AND i.carrier = ? AND i.account = ? WHERE {$where} ORDER BY r.invoice_id, r.row_no",
                [$account['carrier'], $account['account_no'], ...$params]));
    }

    /** @param list<int> $ids @return list<array<string, mixed>> the rows of these bills (whatever their status) */
    private static function billRows(Db $db, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return array_map(static fn (array $r): array => ['invoice_id' => (int) $r['invoice_id'], 'row_no' => (int) $r['row_no'], 'rank' => '', 'service_date' => (string) $r['service_date'],
            'product_code' => (string) $r['product_code'], 'qty' => (int) $r['qty'], 'net' => (int) $r['net_pence'], 'ref_kind' => (string) $r['ref_kind'],
            'ref_no' => $r['ref_no'] === null ? null : (string) $r['ref_no'], 'sales_order' => $r['sales_order'] === null ? null : (string) $r['sales_order']],
            $db->all('SELECT * FROM carrier_bill_row WHERE invoice_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY invoice_id, row_no', $ids));
    }

    /** @return array{main: int, products: array<string, array<string, mixed>>, bands: array<string, list<int>>} */
    private static function ctx(Db $db, array $account): array
    {
        return ['main' => (int) $account['main_channel'], 'main_start' => DeliveryBills::startDay($db, (int) $account['main_channel']),
            'products' => CarrierSettings::products($db, $account['carrier']), 'bands' => CarrierSettings::bands($db, $account['carrier'])];
    }

    /** @param array<string, mixed> $l @return array<string, mixed> */
    private static function label(array $l): array
    {
        return ['id' => (int) $l['id'], 'tracking_no' => strtoupper((string) $l['tracking_no']), 'reference' => $l['reference'] === null ? null : (string) $l['reference'],
            'channel_code' => $l['channel_code'] === null ? null : (string) $l['channel_code'], 'service_code' => $l['service_code'] === null ? null : (string) $l['service_code'],
            'manifest_day' => $l['manifest_day'] === null ? null : (string) $l['manifest_day'], 'manifest_no' => $l['manifest_no'] === null ? null : (int) $l['manifest_no'],
            'weight_g' => $l['weight_g'] === null ? null : (int) $l['weight_g'], 'sales_order' => $l['sales_order'] === null ? null : (string) $l['sales_order']];
    }

    /** A stored allocation in the planner's shape (with its id). @param array<string, mixed> $a @return array<string, mixed> */
    private static function stored(array $a): array
    {
        return ['id' => (int) $a['id'], 'invoice_id' => (int) $a['invoice_id'], 'row_no' => (int) $a['row_no'], 'kind' => (string) $a['kind'], 'how' => (string) $a['how'],
            'label_id' => $a['label_id'] === null ? null : (int) $a['label_id'], 'dest' => (string) $a['dest'], 'channel_id' => $a['channel_id'] === null ? null : (int) $a['channel_id'],
            'order_ref' => $a['order_ref'] === null ? null : (string) $a['order_ref'], 'parcel_ref' => $a['parcel_ref'] === null ? null : (string) $a['parcel_ref'],
            'party_id' => $a['party_id'] === null ? null : (int) $a['party_id'], 'held_reason' => $a['held_reason'] === null ? null : (string) $a['held_reason'],
            'day' => (string) $a['day'], 'pence' => (int) $a['pence']];
    }

    /** A stored UTC time as the UK sees it. */
    private static function when(string $utc): string
    {
        return Clock::fromDb($utc)->setTimezone(new \DateTimeZone('Europe/London'))->format('j M Y H:i');
    }

    /** The UK day of a stored UTC time. */
    public static function ukDay(string $utc): string
    {
        return ReportRefresh::ukDay($utc);
    }
}
