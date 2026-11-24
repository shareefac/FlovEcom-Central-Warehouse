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
 * Royal Mail's bills and labels in CW (delivery cost, phase 1 of the shipping plan v3; docs/decisions.md DL43-DL85): ONE service for every
 * pooled carrier account (0706703000 and Vape Big's: only the settings differ; the owner, 23 Nov 2026, Q5).
 *
 *  check()         the upload screen's first step (DL79): a bill read and refused as upload() / replace() would refuse it, where its money would
 *                  go, and the bill AS READ kept for its Store (never the file), bound to who checked it and the file's sha256, once, CHECK_MINUTES.
 *  storeChecked()  the screen's Store: the bill its check read, stored as upload() / replace() store it.
 *  upload()        a bill read from its saved page or pasted text (RoyalMailBillPage) and stored whole: its rows, and every penny of every row
 *                  as allocations (PooledPlanner): the days it touches planned again across every live bill of the account.
 *  replace()       a corrected file of a live bill: the old one superseded, its allocations ended, the days planned again; one write.
 *  undo()          "Undo this upload": the bill undone, its allocations ended, the days it touched planned again on the bills left.
 *  importReport()  Click & Drop's "Manifested Orders" report (ClickAndDropReport) with the From and To it was run for: its labels added or
 *                  their empty fields filled, and its days covered, in one transaction (phase 1 is report-only: DL84, DL85; the Click & Drop API
 *                  pull is phase 2). The only way labels and cover come in (the writers behind it are private).
 *  health()        the Royal Mail alerts: a week with no report by Tuesday 12:00, days waiting more than 14 days (DL84 8).
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
    /** Keys planned in one guarded write (a day x product key alone holds a day's labels: FLAT_PER_WRITE of them a write). */
    public const KEYS_PER_WRITE = 50;
    public const FLAT_PER_WRITE = 1;
    /** A service code's shape: letters then a digit (TPL48HV, TPM24HV, TRN24, SD1): a word there is never one (the Codex check r2, new MEDIUM 4). */
    public const SERVICE_CODE = '/^[A-Z]{1,6}[0-9][A-Z0-9]{0,9}$/D';
    /** A label's tracking number in its carrier's shape (a pooled carrier's: Royal Mail's two letters, nine digits, GB). */
    public const TRACKING = ['royal_mail' => '/^[A-Z]{2}[0-9]{9}GB$/D'];
    /** The health alert says the days of a bill waiting longer than this (DL84 8). */
    public const WAIT_ALERT_DAYS = 14;
    /** Rounds a plan's keys are closed over (Special Delivery groups) before the plan is tried again. */
    public const CLOSURE_ROUNDS = 40;
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
    /** @var list<string> the keys of this run that could not be planned (busy, a database error): left due, said at the end (DL84 7) */
    private array $failed = [];

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
                $ctx = self::ctx($db, $account) + ['hold_all' => $account['state'] !== 'active'];
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
        // the bill's flat days, and those of the bill it replaces that the correction no longer has (kept, marked removed: the Codex check r6, F8)
        $here = [];
        foreach ($rows as $r) {
            $here[$r['service_date'] . '|' . $r['product_code']] = true;
        }
        foreach ([...$rows, ...($replaces === null ? [] : self::billRows($this->db, [$replaces]))] as $r) {
            $p = $ctx['products'][(string) $r['product_code']] ?? null;
            if (($p['kind'] ?? null) !== 'flat') {
                continue;
            }
            $k = $r['service_date'] . '|' . $r['product_code'];
            $days[$k] ??= ['day' => (string) $r['service_date'], 'product' => (string) $r['product_code'], 'labels' => (int) $this->db->value('SELECT COUNT(*) FROM carrier_label '
                . 'WHERE account_id = ? AND manifest_day = ? AND service_code = ?', [$account['id'], $r['service_date'], (string) $p['service_code']]),
                'billed' => array_sum(array_map(static fn (array $x): int => (int) $x['qty'], array_filter($s['rows'], static fn (array $x): bool
                    => (string) $x['service_date'] === (string) $r['service_date'] && (string) $x['product_code'] === (string) $r['product_code']))),
                'priced' => 0, 'line' => 0, 'waiting' => false, 'reason' => null, 'status' => 'open', 'removed' => !isset($here[$k])];
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
            if ($a['how'] === 'waiting') {
                $days[$k]['waiting'] = true;
                $days[$k]['reason'] ??= (string) $a['held_reason'];
            }
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
            'same_file' => $live !== null && $live['file_sha256'] === $bill['sha256'], 'where' => $split['where'], 'others' => $split['others'], 'gone' => $split['gone'],
            'plan_sha256' => self::planSha($split), 'days' => array_values($days), 'waiting' => self::waitingOf($s['desired'], 0), 'state' => $account['state'],
            'bill_rows' => array_map(static fn (array $r): array => ['row_no' => (int) $r['row_no'], 'service_date' => (string) $r['service_date'],
                'product_code' => (string) $r['product_code'], 'qty' => (int) $r['qty'], 'net' => (int) $r['net'], 'ref_kind' => (string) $r['ref_kind'],
                'ref_no' => $r['ref_no'] === null ? null : (string) $r['ref_no'], 'sales_order' => $r['sales_order'] === null ? null : (string) $r['sales_order']], $rows)];
    }

    /**
     * A plan's split (DL79, DL80): where the bill's money goes (its allocations by destination, shop, party, reason and the day's line) and what
     * moves on the bills already live (their allocations ended and added, by the same groups; the bill it replaces left out: it goes whole).
     *
     * @param array<string, mixed> $s scope()
     * @return array{where: array<string, int>, others: array<string, array{in: int, out: int}>, exact: list<list<string>>}
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
        // what moves on the other live bills, by bill and service day (two bills' moves never cancel out: the Codex check r5, MEDIUM 4), IN and
        // OUT apart (gross: two equal moves of one group never hide each other; the Codex check r6, F7; DL82)
        $others = [];
        $exact = [];
        foreach ([[$s['add'], 'in'], [$s['end'], 'out']] as [$set, $way]) {
            foreach ($set as $a) {
                $inv = (int) $a['invoice_id'];
                if ($inv !== $invoiceId && $inv !== $replaces) {
                    // charges and credits apart too (a charge and a credit of one group never cancel in a column: the Codex check r7, M2)
                    $k = $inv . '|' . $a['day'] . '|' . $key($a) . '|' . ((int) $a['pence'] < 0 ? '-' : '+');
                    $others[$k] ??= ['in' => 0, 'out' => 0];
                    $others[$k][$way] += (int) $a['pence'];
                    $exact[] = ($way === 'in' ? '+' : '-') . PooledPlanner::identity($a);
                }
            }
        }
        $others = array_filter($others, static fn (array $m): bool => $m['in'] !== 0 || $m['out'] !== 0);
        // the bill a correction replaces: its live allocations as they are now (Store refuses when they moved since the check: the Codex check r6,
        // F6), and what it takes out of the profit by day and destination (shown: the Codex check r7, M1)
        $gone = [];
        $takesOut = [];
        foreach ($replaces === null ? [] : ($s['existing'] ?? []) as $a) {
            if ((int) $a['invoice_id'] === $replaces) {
                $gone[] = PooledPlanner::identity($a);
                // charges and credits apart (the Codex check r8: a charge and a credit of one day and destination never cancel in the table)
                $k = $a['day'] . '|' . $key($a) . '|' . ((int) $a['pence'] < 0 ? '-' : '+');
                $takesOut[$k] = ($takesOut[$k] ?? 0) + (int) $a['pence'];
            }
        }
        sort($gone, SORT_STRING);
        ksort($takesOut);
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
        return ['where' => $where, 'others' => $others, 'exact' => [$mine, $exact, $gone], 'gone' => $takesOut];
    }

    /**
     * The days of a bill whose money waits in a plan, each with its reason and pence (every product: the Codex check r9 M18).
     *
     * @param list<array<string, mixed>> $desired
     * @return list<array{day: string, reason: string, pence: int}>
     */
    private static function waitingOf(array $desired, int $invoiceId): array
    {
        $out = [];
        foreach ($desired as $a) {
            if ((int) $a['invoice_id'] === $invoiceId && $a['how'] === 'waiting') {
                $k = $a['day'] . '|' . $a['held_reason'];
                $out[$k] ??= ['day' => (string) $a['day'], 'reason' => (string) $a['held_reason'], 'pence' => 0];
                $out[$k]['pence'] += (int) $a['pence'];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** @param array{where: array<string, int>, others: array<string, array{in: int, out: int}>, exact: list<list<string>>} $split */
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
    public function importReport(Caller $caller, int $accountId, string $bytes, string $from, string $to): array
    {
        $this->bills->authoriseWrite($caller);
        $account = CarrierSettings::account($this->db, $accountId);
        self::reportDays($from, $to, ($this->clock)());
        $r = ClickAndDropReport::read($bytes, CarrierSettings::channels($this->db, $accountId));
        if ($r['kind'] !== 'manifested') {
            // only the Manifested Orders report covers days (the Codex check r9 H1; DL84): its Despatched Orders report is refused whole
            throw new CwException('not_manifested', 'this is not Click & Drop\'s "Manifested Orders" report (it has no Company and Department columns: a Despatched Orders '
                . 'report?). Run Reports > Manifested Orders for the days and upload that. Nothing was saved.', 422, ['field' => 'file']);
        }
        // the report is the account's: every label of it in one of the account's own Click & Drop channels (another account's report would put
        // its labels on this account's bills: the Codex check r1, HIGH 2). A channel CW does not know is never named (it is free text).
        $others = count(array_filter($r['labels'], static fn (array $l): bool => $l['channel_code'] === 'other' || $l['channel_code'] === null));
        if ($others > 0) {
            throw new CwException('other_account', sprintf('this report has %d labels of no Click & Drop channel or of one account %s does not have: is it this account\'s report? '
                . 'Nothing was saved.', $others, $account['account_no']), 422, ['field' => 'file']);
        }
        return ['rows' => $r['rows'], 'skipped' => $r['skipped'], 'sha256' => $r['sha256']] + $this->importReportLabels($caller, $account['id'], $r['labels'], $from, $to,
            $r['sha256'], (int) $r['rows'], $r['row_days']);
    }

    /**
     * A report's labels AND the days it covers (DL84): its From..To as it was run in Click & Drop, every label's manifest day inside them, its
     * To before today (a day not over is never covered); the labels, the import and its days covered written in ONE transaction, then every
     * group touching those days planned again (kept due until a plan takes them).
     *
     * Private (the Codex check r9 H2): labels and cover come only from a report read whole (importReport()).
     *
     * @param list<array<string, mixed>> $labels
     * @param list<?string> $rowDays the manifest day of EVERY row of the report with a tracking number or a manifest date (a row skipped too:
     *   the Codex check r9 H3); null: no manifest date
     * @return array<string, mixed> importLabels()'s, with the import's id and its days
     */
    private function importReportLabels(Caller $caller, int $accountId, array $labels, string $from, string $to, string $sha256, int $rows, array $rowDays): array
    {
        $this->bills->authoriseWrite($caller);
        $days = self::reportDays($from, $to, ($this->clock)());
        foreach ($rowDays as $i => $d) {
            if ($d === null || $d < $from || $d > $to) {
                throw new CwException('bad_report_days', sprintf('a row of the report (%d) is %s, outside the days it was run for (%s to %s): run the report for the days '
                    . 'it holds, then upload it again. Nothing was saved.', $i + 1, $d === null ? 'not manifested' : 'of ' . $d, $from, $to), 422, ['field' => 'file']);
            }
        }
        foreach ($labels as $i => $l) {
            $d = $l['manifest_day'] ?? null;
            if (!is_string($d) || $d < $from || $d > $to) {
                throw new CwException('bad_report_days', sprintf('row %d of the report is %s, outside the days it was run for (%s to %s): run the report for the days it '
                    . 'holds, then upload it again. Nothing was saved.', $i + 1, is_string($d) ? 'of ' . $d : 'not manifested', $from, $to), 422, ['field' => 'file']);
            }
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            throw new CwException('bad_report', 'the report\'s fingerprint is not one', 500);
        }
        return $this->importLabels($caller, $accountId, $labels, 'report', ['sha256' => $sha256, 'from' => $from, 'to' => $to], ['from' => $from, 'to' => $to,
            'days' => $days, 'sha256' => $sha256, 'rows' => $rows]);
    }

    /**
     * The days a report covers: its From..To, From not after To, To before today (UK), at most 93 days.
     *
     * @return list<string>
     */
    private static function reportDays(string $from, string $to, \DateTimeImmutable $now): array
    {
        $today = $now->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
        $ok = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        if (!$ok($from) || !$ok($to) || $from > $to) {
            throw new CwException('bad_report_days', 'give the From and To days the report was run for in Click & Drop (From not after To). Nothing was saved.', 422,
                ['field' => 'from']);
        }
        if ($to >= $today) {
            throw new CwException('bad_report_days', "the report's To day ({$to}) is not over yet: run it up to yesterday at the latest. Nothing was saved.", 422, ['field' => 'to']);
        }
        $days = [];
        for ($d = $from; $d <= $to; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
            $days[] = $d;
            if (count($days) > 93) {
                throw new CwException('bad_report_days', 'a report of more than 93 days: run it for a shorter time. Nothing was saved.', 422, ['field' => 'from']);
            }
        }
        return $days;
    }

    /**
     * Labels from a source stored (one row per account and tracking number: a new one added; one there already only has its EMPTY fields
     * filled, the first source's value stays), then the keys they touch planned again (a failure there leaves the labels stored: the nightly
     * plans them; said in replan_error).
     *
     * @param list<array<string, mixed>> $labels ClickAndDropReport shapes
     * @param array<string, mixed> $audit more for the audit row (never a label)
     * @param array{from: string, to: string, days: list<string>, sha256: string, rows: int}|null $read a report import's From..To and days covered (DL84),
     *   stored with its labels
     * @return array{labels: int, added: int, filled: int, differs: int, replanned: ?int, replan_error: ?string}
     */
    private function importLabels(Caller $caller, int $accountId, array $labels, string $source, array $audit = [], ?array $read = null): array
    {
        if ($source !== 'report') {
            // phase 1 is report-only: the Click & Drop API pull is phase 2, nothing of it moves money (DL84)
            throw new CwException('phase_2', 'labels come from Click & Drop\'s "Manifested Orders" report only (the API pull is phase 2). Nothing was saved.', 422);
        }
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
            // the labels, the import and the days it covers in ONE transaction (a day is never covered without its labels: DL84), and the days
            // made due with them
            $u = $this->db->transaction(function (Db $db) use ($caller, $account, $labels, $source, $audit, $read): array {
                $u = $this->upsert($caller, $account, $labels, $source, $audit);
                $at = Clock::db(($this->clock)());
                // the days whose groups this changes: its labels' manifest days (in chunks: a long catch-up has many labels) and a report's days,
                // kept due with the labels until a plan takes them, however old the day (DL83, DL84)
                $due = [];
                foreach (array_chunk($u['all'], 500) as $chunk) {
                    foreach ($db->column('SELECT DISTINCT manifest_day FROM carrier_label WHERE manifest_day IS NOT NULL AND id IN (' . implode(', ', array_fill(0, count($chunk), '?'))
                        . ')', $chunk) as $d) {
                        $due[(string) $d] = true;
                    }
                }
                foreach ($read['days'] ?? [] as $d) {
                    $due[$d] = true;
                }
                ksort($due);
                $due = array_keys($due);
                foreach (array_chunk($due, 200) as $chunk) {
                    $db->exec('INSERT INTO carrier_replan_due (account_id, day, since) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'))
                        . ' AS n ON DUPLICATE KEY UPDATE since = carrier_replan_due.since', array_merge(...array_map(static fn (string $d): array => [$account['id'], $d, $at], $chunk)));
                }
                $u['due'] = $due;
                $u['import_id'] = null;
                if ($read !== null) {
                    // the report import and the days it covers, with its labels (a day is never covered without them: DL84)
                    $u['import_id'] = $db->insert('INSERT INTO carrier_report_import (account_id, from_day, to_day, file_sha256, rows_read, labels, staff_user_id, actor, '
                        . 'imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$account['id'], $read['from'], $read['to'], $read['sha256'], $read['rows'], count($labels),
                            $caller->staffUserId, $caller->actor, $at]);
                    foreach (array_chunk($read['days'], 200) as $chunk) {
                        $db->exec('INSERT INTO carrier_day_cover (account_id, day, import_id) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?)')),
                            array_merge(...array_map(static fn (string $d): array => [$account['id'], $d, $u['import_id']], $chunk)));
                    }
                }
                return $u;
            });
            $out = ['labels' => count($labels), 'added' => $u['added'], 'filled' => $u['filled'], 'differs' => $u['differs'], 'replanned' => null, 'replan_error' => null,
                'import_id' => $u['import_id'], 'days' => $read['days'] ?? []];
            if ($u['due'] === [] || $account['state'] !== 'active') {
                return ['replanned' => 0] + $out;
            }
            try {
                // every group touching a day whose labels or reading changed: its rows of that date, and the groups (day, manifest, sales order) of
                // every label of the account manifested that day, billed under another date or not (the Codex checks r1 MEDIUM 5, r5 MEDIUM 3, r6 F5,
                // r7 H5; DL82, DL83); then those days are due no more
                $this->failed = [];
                $out['replanned'] = $this->replanDays($caller, $account, $u['due']);
                if ($this->failed !== []) {
                    $out['replan_error'] = count(array_unique($this->failed)) . ' group(s) of the bills could not be planned just now (an order busy): their days stay due and '
                        . 'the nightly run plans them';
                    $this->failed = [];
                }
            } catch (CwException | \PDOException $e) {
                $code = $e instanceof \PDOException ? Db::driverCode($e) : null;
                $out['replan_error'] = $e instanceof CwException ? $e->getMessage() : 'the bills could not be planned again just now (database error ' . ($code ?? 'unknown')
                    . '): the nightly run does it';
            }
            return $out;
        });
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
        // the days whose labels changed and whose groups no plan took yet, however old (DL83)
        $this->failed = [];
        $n = $this->drainReplans($caller);
        foreach (CarrierSettings::pooledAccountIds($this->db) as $id) {
            if ($accountId !== null && $id !== $accountId) {
                continue;
            }
            try {
                // each account read on its own: one that cannot be read never stops the others (the Codex check r9 M16)
                $account = CarrierSettings::account($this->db, $id);
                if ($account['state'] !== 'active') {
                    continue;
                }
                $n += $this->replanAccount($caller, $account, $days);
            } catch (CwException | \PDOException) {
                // one account's error never stops the others (DL84 7): said at the end
                $this->failed[] = 'account:' . $id;
            }
        }
        if ($this->failed !== []) {
            // said once every other group was planned (DL84 7): the days of these stay due, the next run tries them again
            $k = count(array_unique($this->failed));
            $this->failed = [];
            throw new CwException('partial', "{$k} group(s) of the bills could not be planned just now (an order busy, or a database error): their days stay due "
                . "and the next run plans them. Every other group was planned ({$n} allocations changed).", 409);
        }
        return $n;
    }

    /** replan()'s one account, under the lock. @param array<string, mixed> $account */
    private function replanAccount(Caller $caller, array $account, int $days): int
    {
        return $this->bills->inLock(function () use ($caller, $account, $days): int {
            // read again once the lock is held: switched off meanwhile, not planned (the Codex check r3, new MEDIUM 3)
            $account = CarrierSettings::account($this->db, $account['id']);
            if ($account['state'] !== 'active') {
                return 0;
            }
            $since = ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->modify("-{$days} days")->format('Y-m-d');
            $ctx = self::ctx($this->db, $account);
            $rows = self::rowsWhere($this->db, $account, 'r.service_date >= ?', [$since]);
            $failed = count($this->failed);
            $n = $this->replanKeys($caller, $account, self::keysOf($this->db, $account, $ctx, $rows));
            // a group that could not be planned now is kept DUE (its days), so the next run plans it whatever its age: a withdrawal never ages
            // out of the nightly's days (the Codex check r9 H15)
            $this->keepDue($account, array_slice($this->failed, $failed));
            return $n;
        });
    }

    /**
     * The Royal Mail health alert (DL84 8), for every active pooled account: rm_report_missing when a day of the last whole week (Monday to
     * Sunday) is covered by no report import by the Tuesday after it, 12:00 UK; rm_days_waiting when a day of a live bill has waited more
     * than WAIT_ALERT_DAYS days (counted from the day itself). A schema before 0078 has none.
     *
     * @return list<array{key: string, kind: string, detail: string, data: array<string, int|string|null>}>
     */
    public static function health(Db $db, \DateTimeImmutable $now): array
    {
        if ($db->value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'carrier_day_cover'") === null) {
            return [];
        }
        $uk = $now->setTimezone(new \DateTimeZone('Europe/London'));
        // the newest Tuesday 12:00 at or before now: the week before it (its Monday to Sunday) is due
        $tue = $uk->modify('tuesday this week')->setTime(12, 0);
        if ($tue > $uk) {
            $tue = $tue->modify('-7 days');
        }
        $sunday = $tue->modify('-2 days')->format('Y-m-d');
        $monday = $tue->modify('-8 days')->format('Y-m-d');
        $old = $uk->modify('-' . self::WAIT_ALERT_DAYS . ' days')->format('Y-m-d');
        $out = [];
        foreach (CarrierSettings::pooledAccountIds($db) as $id) {
            try {
                $a = CarrierSettings::account($db, $id);
            } catch (CwException | \PDOException) {
                continue; // one account that cannot be read never hides the others' alerts
            }
            if ($a['state'] !== 'active') {
                continue;
            }
            $covered = (int) $db->value('SELECT COUNT(DISTINCT day) FROM carrier_day_cover WHERE account_id = ? AND day BETWEEN ? AND ?', [$a['id'], $monday, $sunday]);
            if ($covered < 7) {
                $out[] = ['key' => 'rm_report_missing:' . $a['account_no'], 'kind' => 'rm_report_missing', 'detail' => sprintf('Royal Mail %s: %d day(s) of %s to %s covered by '
                    . 'no Click & Drop report yet', $a['account_no'], 7 - $covered, $monday, $sunday), 'data' => ['account' => $a['account_no'], 'from' => $monday,
                    'to' => $sunday, 'days' => 7 - $covered]];
            }
            $long = array_filter(self::waitingDays($db, $a['id']), static fn (array $w): bool => $w['day'] < $old);
            if ($long !== []) {
                $out[] = ['key' => 'rm_days_waiting:' . $a['account_no'], 'kind' => 'rm_days_waiting', 'detail' => sprintf('Royal Mail %s: %d day(s) of its bills waiting more '
                    . 'than %d days (the oldest %s)', $a['account_no'], count($long), self::WAIT_ALERT_DAYS, min(array_column($long, 'day'))),
                    'data' => ['account' => $a['account_no'], 'days' => count($long), 'oldest' => min(array_column($long, 'day')), 'limit' => self::WAIT_ALERT_DAYS]];
            }
        }
        return $out;
    }

    /**
     * The days of an account's live bills whose money waits (DL84: held whole until its day or group is covered and checked), each with its
     * reasons and pence, oldest first.
     *
     * @return list<array{day: string, reasons: list<string>, pence: int}>
     */
    public static function waitingDays(Db $db, int $accountId): array
    {
        $account = CarrierSettings::account($db, $accountId);
        $out = [];
        foreach ($db->all("SELECT a.day, a.held_reason, SUM(a.pence) AS p FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' "
            . "AND i.carrier = ? AND i.account = ? WHERE a.ended_at IS NULL AND a.how = 'waiting' GROUP BY a.day, a.held_reason ORDER BY a.day, a.held_reason",
            [$account['carrier'], $account['account_no']]) as $r) {
            $d = (string) $r['day'];
            $out[$d] ??= ['day' => $d, 'reasons' => [], 'pence' => 0];
            $out[$d]['reasons'][] = (string) $r['held_reason'];
            $out[$d]['pence'] += (int) $r['p'];
        }
        return array_values($out);
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
    /**
     * Every group touching these manifest days planned again (DL83): the rows of those dates and the groups of every label of the account
     * manifested on them; then the days are due no more (carrier_replan_due). A plan that fails leaves them due for the next.
     *
     * @param list<string> $days
     */
    private function replanDays(Caller $caller, array $account, array $days): int
    {
        if ($days === []) {
            return 0;
        }
        sort($days, SORT_STRING);
        $ctx = self::ctx($this->db, $account);
        $n = 0;
        // day by day (DL84 7): a group that cannot be planned now (an order busy) leaves its day due and never stops the others
        foreach ($days as $day) {
            $failed = count($this->failed);
            try {
                $onDay = array_map('intval', $this->db->column('SELECT id FROM carrier_label WHERE account_id = ? AND manifest_day = ?', [$account['id'], $day]));
                $keys = array_values(array_unique([...($onDay === [] ? [] : self::keysOfLabels($this->db, $account, $onDay)),
                    ...self::keysOf($this->db, $account, $ctx, self::rowsWhere($this->db, $account, 'r.service_date = ?', [$day]))]));
            } catch (CwException | \PDOException) {
                // a day whose groups cannot be found now stays due; the next days are planned all the same (the Codex check r9 M16)
                $this->failed[] = 'day:' . $day;
                continue;
            }
            sort($keys, SORT_STRING);
            $n += $this->replanKeys($caller, $account, $keys);
            if (count($this->failed) === $failed) {
                $this->db->exec('DELETE FROM carrier_replan_due WHERE account_id = ? AND day = ?', [$account['id'], $day]);
            }
        }
        return $n;
    }

    /**
     * The days of these keys written due (carrier_replan_due): a day x product's day, a manifest's or a sales order's rows' and labels' days, a
     * row's own day.
     *
     * @param array<string, mixed> $account
     * @param list<string> $keys
     */
    private function keepDue(array $account, array $keys): void
    {
        $days = [];
        foreach (array_unique($keys) as $k) {
            $p = explode('|', $k);
            $found = match ($p[0]) {
                'F' => [$p[1]],
                'M' => [...$this->db->column("SELECT DISTINCT service_date FROM carrier_bill_row WHERE ref_kind = 'manifest' AND ref_no = ?", [$p[1]]),
                    ...$this->db->column('SELECT DISTINCT manifest_day FROM carrier_label WHERE account_id = ? AND manifest_no = ? AND manifest_day IS NOT NULL',
                        [$account['id'], (int) $p[1]])],
                'S' => [...$this->db->column("SELECT DISTINCT service_date FROM carrier_bill_row WHERE (ref_kind = 'sales_order' AND ref_no = ?) OR sales_order = ?", [$p[1], $p[1]]),
                    ...$this->db->column('SELECT DISTINCT manifest_day FROM carrier_label WHERE account_id = ? AND sales_order = ? AND manifest_day IS NOT NULL',
                        [$account['id'], $p[1]])],
                'R' => $this->db->column('SELECT service_date FROM carrier_bill_row WHERE invoice_id = ? AND row_no = ?', [(int) ($p[1] ?? 0), (int) ($p[2] ?? 0)]),
                default => [],
            };
            foreach ($found as $d) {
                $days[(string) $d] = true;
            }
        }
        $at = Clock::db(($this->clock)());
        foreach (array_chunk(array_keys($days), 200) as $chunk) {
            $this->db->exec('INSERT INTO carrier_replan_due (account_id, day, since) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'))
                . ' AS n ON DUPLICATE KEY UPDATE since = carrier_replan_due.since', array_merge(...array_map(static fn (string $d): array => [$account['id'], $d, $at], $chunk)));
        }
    }

    /** The days still due of every active account planned (the nightly, DL83). */
    private function drainReplans(Caller $caller): int
    {
        $n = 0;
        foreach (CarrierSettings::pooledAccountIds($this->db) as $id) {
            try {
                $account = CarrierSettings::account($this->db, $id);
                if ($account['state'] !== 'active') {
                    continue;
                }
                $days = array_map('strval', $this->db->column('SELECT day FROM carrier_replan_due WHERE account_id = ? ORDER BY day', [$account['id']]));
                foreach (array_chunk($days, 31) as $chunk) {
                    // read again once the lock is held: switched off meanwhile, its days stay due (DL84 7)
                    $n += $this->bills->inLock(function () use ($caller, $account, $chunk): int {
                        $now = CarrierSettings::account($this->db, $account['id']);
                        return $now['state'] === 'active' ? $this->replanDays($caller, $now, $chunk) : 0;
                    });
                }
            } catch (CwException | \PDOException) {
                // one account's error never stops the others (DL84 7): said at the end, its days stay due
                $this->failed[] = 'account:' . $id;
            }
        }
        return $n;
    }

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
            try {
                $n += $this->replanBatch($caller, $account, $batch);
            } catch (CwException | \PDOException $e) {
                // one group that cannot be planned now never blocks the others (DL84 7): its batch key by key, a key that fails left for the next
                // run (its day stays due) and said at the end
                foreach (count($batch) > 1 ? $batch : [] as $one) {
                    try {
                        $n += $this->replanBatch($caller, $account, [$one]);
                    } catch (CwException | \PDOException) {
                        $this->failed[] = $one;
                    }
                }
                if (count($batch) === 1) {
                    $this->failed[] = $batch[0];
                }
            }
        }
        return $n;
    }

    /** @param list<string> $batch keys planned in one guarded write @return int the allocations ended or added */
    private function replanBatch(Caller $caller, array $account, array $batch): int
    {
        return $this->bills->guardedWrite(function (Db $db) use ($caller, $account, $batch): array {
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
            $after))), 'versions' => array_intersect_key($s['versions'], $owners), 'write' => $write, 'verify' => $s['verify']];
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
        // DL84 3 (the Codex check r9 H5): a day that waits withdraws ALL its money in this write: every key of a row of a waiting day is planned
        // here too, closed over again (a few passes at most)
        for ($pass = 0; ; $pass++) {
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
                // a group's labels: its Special Delivery ones, and one of no service (not known what it is: a member; the Codex check r9 H8)
                $bandOrNone = '(service_code IN (' . $marks(count($bandServices)) . ') OR service_code IS NULL)';
                $bySales = static function (array $so) use ($db, $account, $bandServices, $cols, $marks, $take, $bandOrNone): void {
                    foreach (array_chunk(array_values(array_unique($so)), 500) as $chunk) {
                        $take($db->all("SELECT {$cols} FROM carrier_label WHERE account_id = ? AND sales_order IN (" . $marks(count($chunk)) . ") AND {$bandOrNone}",
                            [$account['id'], ...$chunk, ...$bandServices]));
                    }
                };
                if ($bandServices !== []) {
                    foreach (array_chunk($manifests, 500) as $chunk) {
                        $take($db->all("SELECT {$cols} FROM carrier_label WHERE account_id = ? AND manifest_no IN (" . $marks(count($chunk)) . ") AND {$bandOrNone}",
                            [$account['id'], ...$chunk, ...$bandServices]));
                    }
                    $bySales($sales);
                    // the labels on the manifests of a sales order's labels: one of them naming no sales order means the group is not known whole
                    // (the Codex check r7, H4; DL83)
                    $kin = array_values(array_unique(array_filter(array_map(static fn (array $l): ?int => $l['sales_order'] !== null && in_array($l['sales_order'], $sales, true)
                        && $l['manifest_no'] !== null && ($l['service_code'] === null || in_array($l['service_code'], $bandServices, true)) ? (int) $l['manifest_no'] : null,
                        $labels), static fn (?int $m): bool => $m !== null)));
                    foreach (array_chunk($kin, 500) as $chunk) {
                        $take($db->all("SELECT {$cols} FROM carrier_label WHERE account_id = ? AND manifest_no IN (" . $marks(count($chunk)) . ") AND {$bandOrNone}",
                            [$account['id'], ...$chunk, ...$bandServices]));
                    }
                }
                $soOfManifests = array_values(array_unique(array_filter(array_map(static fn (array $l): ?string => $l['manifest_no'] !== null
                    && in_array((int) $l['manifest_no'], $manifests, true) ? $l['sales_order'] : null, $labels))));
                // the keys' rows, each part its own query in chunks (no placeholder limit on a big catch-up or the drift check: the Codex check r9 M17)
                $parts = [];
                foreach (array_chunk($flat, 200) as $chunk) {
                    $pp = [];
                    foreach ($chunk as [$day, $product]) {
                        array_push($pp, $product, $day);
                    }
                    $parts[] = ['(r.product_code, r.service_date) IN (' . implode(', ', array_fill(0, count($chunk), '(?, ?)')) . ')', $pp];
                }
                $band = $kinds('band');
                // the band rows of the keys' manifests, and every band row naming a sales order of the keys or of their manifests' labels (a row of
                // another manifest printing one: one group, the Codex check r9 H6)
                $rowSalesWanted = array_values(array_unique([...$soOfManifests, ...$sales]));
                if ($band !== []) {
                    foreach (array_chunk($manifests, 500) as $chunk) {
                        $parts[] = ['(r.product_code IN (' . $marks(count($band)) . ") AND r.ref_kind = 'manifest' AND r.ref_no IN (" . $marks(count($chunk)) . '))',
                            [...$band, ...array_map('strval', $chunk)]];
                    }
                    foreach (array_chunk($rowSalesWanted, 500) as $chunk) {
                        $parts[] = ['(r.product_code IN (' . $marks(count($band)) . ') AND r.sales_order IN (' . $marks(count($chunk)) . '))', [...$band, ...$chunk]];
                    }
                }
                $ww = $kinds('wrong_weight');
                if ($ww !== []) {
                    foreach (array_chunk($sales, 500) as $chunk) {
                        $m = $marks(count($chunk));
                        $parts[] = ['(r.product_code IN (' . $marks(count($ww)) . ") AND ((r.ref_kind = 'sales_order' AND r.ref_no IN ({$m})) OR r.sales_order IN ({$m})))",
                            [...$ww, ...$chunk, ...$chunk]];
                    }
                }
                foreach (array_chunk($own, 200) as $chunk) {
                    $pp = [];
                    foreach ($chunk as [$inv, $no]) {
                        array_push($pp, $inv, $no);
                    }
                    $parts[] = ['(r.invoice_id, r.row_no) IN (' . implode(', ', array_fill(0, count($chunk), '(?, ?)')) . ')', $pp];
                }
                $byRow = [];
                $not = $gone === [] ? '' : ' AND i.id NOT IN (' . $marks(count($gone)) . ')';
                foreach ($parts as [$sql, $pp]) {
                    foreach (self::rowsWhere($db, $account, $sql . $not, [...$pp, ...$gone], $loading) as $r) {
                        $byRow[$r['invoice_id'] . '|' . $r['row_no']] = $r;
                    }
                }
                uasort($byRow, static fn (array $x, array $y): int => [$x['invoice_id'], $x['row_no']] <=> [$y['invoice_id'], $y['row_no']]);
                $rows = array_values($byRow);
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
                        if ($kindOf($r) === 'band' && $r['sales_order'] !== null) {
                            $more['S|' . $r['sales_order']] = true; // a manifest's row naming a sales order: one group (DL84 4)
                        }
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
                // the band rows of the same day and product as a band row here: a bill taking that day over its labels withdraws them all in the
                // same write (the Codex check r9 H5; DL84 3b)
                $pairs = [];
                foreach ($cand as $r) {
                    if ($kindOf($r) === 'band') {
                        $pairs[$r['service_date'] . '|' . $r['product_code']] = [(string) $r['product_code'], (string) $r['service_date']];
                    }
                }
                foreach (array_chunk(array_values($pairs), 200) as $chunk) {
                    $not = $gone === [] ? '' : ' AND i.id NOT IN (' . $marks(count($gone)) . ')';
                    foreach (self::rowsWhere($db, $account, '(r.product_code, r.service_date) IN (' . implode(', ', array_fill(0, count($chunk), '(?, ?)')) . ')' . $not,
                        [...array_merge(...$chunk), ...$gone], $loading) as $r) {
                        $more[PooledPlanner::key($r, 'band', $labels, $ctx)] = true;
                    }
                }
                // the whole group: every manifest and sales order a Special Delivery label (or one of no service) of it names (DL84 4: closed over
                // all live bills and labels)
                foreach ($labels as $l) {
                    if ($l['service_code'] === null || in_array($l['service_code'], $bandServices, true)) {
                        if ($l['manifest_no'] !== null) {
                            $more['M|' . (int) $l['manifest_no']] = true;
                        }
                        if ($l['sales_order'] !== null) {
                            $more['S|' . $l['sales_order']] = true;
                        }
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
            [$ctx['waiting'], $ctx['incomplete'], $ctx['product_waiting']] = self::dayStates($db, $account, $ctx, $days, $newRows, $gone, $loading);
            $waitingDays = array_keys($ctx['waiting']);
            $extra = [];
            foreach (array_chunk($waitingDays, 200) as $chunk) {
                $not = $gone === [] ? '' : ' AND i.id NOT IN (' . $marks(count($gone)) . ')';
                foreach (self::keysOf($db, $account, $ctx, self::rowsWhere($db, $account, 'r.service_date IN (' . $marks(count($chunk)) . ')' . $not,
                    [...$chunk, ...$gone], $loading)) as $k) {
                    if (!in_array($k, $keys, true)) {
                        $extra[$k] = true;
                    }
                }
            }
            if ($extra === []) {
                break;
            }
            if ($pass >= 3) {
                throw new CwException(self::RETRY, 'the days waiting kept growing while they were read', 409); // never discarded: tried again
            }
            $keys = array_values(array_unique([...$keys, ...array_map('strval', array_keys($extra))]));
            sort($keys, SORT_STRING);
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
        // the day's evidence read again once the write's guards are held (the Codex check r9 H12): a CW parcel's service, a label, a cover or a
        // bill that changed it since this plan read it means the plan is old: made again (DeliveryBills' verify)
        $states = [$ctx['waiting'], $ctx['incomplete'], $ctx['product_waiting']];
        $verify = static fn (Db $db): bool => self::dayStates($db, $account, $ctx, $days, $newRows, $gone, $loading) === $states;
        return ['keys' => $keys, 'rows' => $rows, 'labels' => $labels, 'sticky' => $sticky, 'dests' => $dests, 'versions' => $versions, 'desired' => $desired,
            'existing' => $existing, 'end' => $end, 'add' => $add, 'gone' => array_map('intval', $gone), 'loading' => $loading, 'verify' => $verify];
    }

    /**
     * Which days of a plan may move money (DL84): a day is COMPLETE when a report import covers it (carrier_day_cover), every label of it has a
     * service the plan maps, none differs from its CW parcel's service, and (a) every CW Royal Mail parcel labelled that day (of a shop the
     * account serves, an outbound service or none given) is in the account's labels; and (b) for a product, the units all live bills have for
     * it that day are no more than its labels that day. Each incomplete day with its reason; after the main shop's CW start it waits (on or before
     * the start a day is wholly before CW start: PooledPlanner); an account switched off holds every day of a plan it makes (DL83).
     *
     * @param list<string> $days
     * @param list<array<string, mixed>> $newRows the rows of a bill being stored (invoice 0)
     * @param list<int> $gone the bills going (undone, replaced)
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>} waiting day => reason, incomplete day => reason,
     *   "day|product" => billed_more (every day)
     */
    private static function dayStates(Db $db, array $account, array $ctx, array $days, array $newRows, array $gone, ?int $loading): array
    {
        $waiting = [];
        $incomplete = [];
        $product = [];
        if ($days === []) {
            return [$waiting, $incomplete, $product];
        }
        sort($days, SORT_STRING);
        $marks = static fn (int $n): string => implode(', ', array_fill(0, $n, '?'));
        $flat = [];
        foreach ($ctx['products'] as $code => $p) {
            if ($p['service_code'] !== null && in_array($p['kind'], ['flat', 'band'], true)) {
                $flat[(string) $p['service_code']] = (string) $code;
            }
        }
        $mapped = array_keys($flat);
        $reason = [];
        $covered = [];
        $labelsBy = [];
        foreach (array_chunk($days, 300) as $chunk) {
            foreach ($db->column('SELECT DISTINCT day FROM carrier_day_cover WHERE account_id = ? AND day IN (' . $marks(count($chunk)) . ')', [$account['id'], ...$chunk]) as $d) {
                $covered[(string) $d] = true;
            }
            foreach ($db->all('SELECT manifest_day, service_code, COUNT(*) AS n FROM carrier_label WHERE account_id = ? AND manifest_day IN (' . $marks(count($chunk))
                . ') GROUP BY manifest_day, service_code', [$account['id'], ...$chunk]) as $r) {
                $d = (string) $r['manifest_day'];
                if ($r['service_code'] === null) {
                    $reason[$d] ??= 'no_service';
                } elseif (!in_array((string) $r['service_code'], $mapped, true)) {
                    $reason[$d] ??= 'unknown_service';
                } else {
                    $labelsBy[$d . '|' . $flat[(string) $r['service_code']]] = (int) $r['n'];
                }
            }
            // a report's service other than its CW parcel's, for the same tracking number
            foreach ($db->column("SELECT DISTINCT l.manifest_day FROM carrier_label l JOIN sale_parcel p ON p.tracking_no = l.tracking_no AND p.removed_at IS NULL "
                . "AND (p.carrier = 'royal_mail' OR p.is_manual = 1) AND p.service_code <> '' WHERE l.account_id = ? AND l.manifest_day IN (" . $marks(count($chunk)) . ') AND l.service_code IS NOT NULL '
                . 'AND p.service_code <> l.service_code', [$account['id'], ...$chunk]) as $d) {
                $reason[(string) $d] ??= 'service_differs';
            }
        }
        // (a) the CW Royal Mail parcels labelled on these days (UK) of the shops the account serves, in no label of the account
        $shops = array_values(array_filter(array_map(static fn (array $p): ?int => $p['channel_id'], CarrierSettings::parties($db, (int) $account['id']))));
        if ($shops !== []) {
            $utc = new \DateTimeZone('UTC');
            $uk = new \DateTimeZone('Europe/London');
            $lo = (new \DateTimeImmutable($days[0] . ' 00:00:00', $uk))->setTimezone($utc)->format('Y-m-d H:i:s');
            $hi = (new \DateTimeImmutable(end($days) . ' 00:00:00', $uk))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
            // every outbound parcel, whatever its service (one the plan does not price too: the Codex check r9 H11); a return's service (the
            // carrier's return products, e.g. TSS) is no label of the account
            $returns = array_map('strval', array_keys(array_filter($ctx['products'], static fn (array $p): bool => $p['kind'] === 'return')));
            $notReturn = $returns === [] ? '' : ' AND p.service_code NOT IN (' . $marks(count($returns)) . ')';
            foreach ($db->all('SELECT p.label_at FROM sale_parcel p LEFT JOIN carrier_label l ON l.account_id = ? AND l.tracking_no = p.tracking_no '
                . "WHERE p.carrier = 'royal_mail' AND p.removed_at IS NULL AND p.tracking_no IS NOT NULL AND p.tracking_no <> '' AND p.label_at >= ? AND p.label_at < ? "
                . 'AND p.channel_id IN (' . $marks(count($shops)) . "){$notReturn} AND l.id IS NULL", [$account['id'], $lo, $hi, ...$shops, ...$returns]) as $p) {
                $d = ReportRefresh::ukDay((string) $p['label_at']);
                if (in_array($d, $days, true)) {
                    $reason[$d] ??= 'parcel_missing';
                }
            }
        }
        // (b) the units of every live bill (the bills going left out, the one being stored in) per day and product, against its labels
        $billed = [];
        foreach (array_chunk($days, 300) as $chunk) {
            $not = $gone === [] ? '' : ' AND i.id NOT IN (' . $marks(count($gone)) . ')';
            foreach ($db->all("SELECT r.service_date, r.product_code, SUM(r.qty) AS q FROM carrier_bill_row r JOIN carrier_invoice i ON i.id = r.invoice_id AND (i.status = 'live'"
                . ($loading === null ? '' : ' OR i.id = ' . $loading) . ") AND i.carrier = ? AND i.account = ? WHERE r.service_date IN (" . $marks(count($chunk)) . "){$not} GROUP BY r.service_date, r.product_code",
                [$account['carrier'], $account['account_no'], ...$chunk, ...$gone]) as $r) {
                $billed[$r['service_date'] . '|' . $r['product_code']] = ($billed[$r['service_date'] . '|' . $r['product_code']] ?? 0) + (int) $r['q'];
            }
        }
        foreach ($newRows as $r) {
            $k = $r['service_date'] . '|' . $r['product_code'];
            $billed[$k] = ($billed[$k] ?? 0) + (int) $r['qty'];
        }
        $start = $ctx['main_start'] ?? null;
        $hold = !empty($ctx['hold_all']);
        foreach ($billed as $k => $q) {
            [$d, $code] = explode('|', $k);
            $kind = $ctx['products'][$code]['kind'] ?? null;
            // every day (a group crossing CW start checks its start-side days too: the Codex check r9 H10); a flat day on or before the start
            // never waits (PooledPlanner)
            if (in_array($kind, ['flat', 'band'], true) && $q > ($labelsBy[$k] ?? 0)) {
                $product[$k] = 'billed_more';
            }
        }
        foreach ($days as $d) {
            $why = $hold ? 'account_off' : (!isset($covered[$d]) ? 'not_covered' : ($reason[$d] ?? null));
            if ($why !== null) {
                $incomplete[$d] = $why;
                if ($hold || ($start !== null && $d > $start)) {
                    $waiting[$d] = $why;
                }
            }
        }
        ksort($waiting);
        ksort($incomplete);
        ksort($product);
        return [$waiting, $incomplete, $product];
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
                // a Special Delivery label, or one of no service (a group's member all the same: the Codex check r9 H8)
                if ($l['service_code'] === null || in_array((string) $l['service_code'], $band, true)) {
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
