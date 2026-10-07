<?php

declare(strict_types=1);

namespace CW\Receiving;

use CW\Catalogue\ItemCompliance;
use CW\Catalogue\ItemRules;
use CW\Clock;
use CW\Db;
use CW\PurchaseOrders\PoMath;
use CW\Settings;

/**
 * What posting a goods receipt would do, and what stops it (IM6; docs/decisions.md I129-I139): one computation shared by the
 * receive editor and the bench view (what the person sees before posting), GoodsReceiptHandler::validate() (refuses with the
 * first problem, all of them in the detail) and GoodsReceiptHandler::post() (built again under the posting's locks and booked).
 *
 * Per line: the units on the paperwork (packs x units per pack) and where they go (ReceiptMath::split), whether a duty stamp is
 * required (ItemCompliance::receiving: duty-liable, or not answered unless the card names a dry product, I119), the expected duty
 * for information, the selling mode the receipt gives the item (SellingModes), the PO line it receives; its problems (blocking)
 * and warnings. For the receipt: the header problems (invoice number, the invoice copy, the supplier, the PO, when the goods
 * arrived, the bench check), the over-delivery tolerance per PO line, one selling mode per item, and the totals.
 *
 * Duty (decision 8; I134): an unstamped duty-liable unit received on or after receiving.unstamped_refusal_from (1 Jan 2027; the
 * delivery's received date, UK) is refused or quarantined (UNSTAMPED) with an incident; before that date it may also be accepted
 * into MAIN with the supplier's evidence that it was made or imported before 1 Oct 2026 (anything made or imported later that
 * arrives unstamped is always refused: the evidence is what tells them apart). No date set: the refusal applies (fail closed).
 *
 * Since the reviews of 7 Oct (I167-I174): every line counted at the bench after it was keyed or last changed (line_not_checked,
 * grouped with the paperwork question while the bench has not started: one "waiting for the bench" problem, not one a line); an
 * unstamped delivery's damaged and over units follow its unstamped units (ReceiptMath::split); an item the receipt accepts nothing
 * of into MAIN keeps its selling mode (source 'kept'); the same invoice copy on another live receipt of the supplier refuses the
 * posting; a bench check older than the arrival refuses it; over units beyond the line are a warning (the bench confirmed them).
 *
 * $lock: inside the posting's transaction (validate: the item cards FOR SHARE, I122; post: also the selling-mode rows FOR UPDATE,
 * after the number series, and the PO rows, which post() locks before building). The screens pass false.
 */
final class ReceiptPlan
{
    public const STAMP_TYPES = ['digital' => 'digital stamp', 'transitional' => 'transitional stamp'];
    public const ACTIONS = ['quarantine' => 'quarantine (UNSTAMPED, back to the supplier)', 'refuse' => 'refuse at the door (not booked)',
        'accept_pre_october' => 'accept: made or imported before 1 Oct 2026 (supplier\'s evidence)'];
    /** The roles of an attached file that can be the supplier's invoice copy, and the types it may have (I130). */
    public const INVOICE_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];
    public const EVIDENCE_MIN = 10;
    /** Clock::MAX_AHEAD style slack for "received in the future". */
    public const AHEAD_SEC = 300;

    /**
     * @return array{doc: array<string, mixed>, header: array<string, mixed>, lines: array<int, array<string, mixed>>, problems: list<array{code: string, message: string, line?: int}>,
     *   warnings: list<string>, totals: array<string, int>, modes: array<int, array<string, mixed>>, po: ?array<string, mixed>}
     */
    public static function build(Db $db, int $documentId, \DateTimeImmutable $now, bool $lockCards = false, bool $lockModes = false, ?Settings $settings = null): array
    {
        $settings ??= new Settings($db);
        $doc = $db->one('SELECT * FROM document WHERE id = ?', [$documentId]) ?? throw new \LogicException("no document {$documentId}");
        $gr = $db->one('SELECT * FROM goods_receipt WHERE document_id = ?', [$documentId]) ?? throw new \LogicException("document {$documentId} has no goods_receipt");
        $lines = [];
        foreach ($db->all(
            'SELECT dl.line_no, dl.sku_id, dl.qty, dl.unit_cost, dl.amount, dl.description, g.*, s.code AS sku_code, s.name AS sku_name, s.merged_into_sku_id '
            . 'FROM document_line dl JOIN grn_line g ON g.document_id = dl.document_id AND g.line_no = dl.line_no LEFT JOIN sku s ON s.id = dl.sku_id '
            . 'WHERE dl.document_id = ? ORDER BY dl.line_no',
            [$documentId],
        ) as $r) {
            $lines[(int) $r['line_no']] = $r;
        }
        $skus = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['sku_id'], $lines)));
        $comp = $skus === [] ? [] : (new ItemCompliance($db))->receiving($skus, $lockCards);
        $cards = [];
        foreach (array_chunk($skus, 1000) as $chunk) {
            foreach ($db->all('SELECT sku_id, product_type, liquid_ml, duty_liable FROM item_card WHERE sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $c) {
                $cards[(int) $c['sku_id']] = ['product_type' => $c['product_type'] === null ? null : (string) $c['product_type'],
                    'liquid_ml' => $c['liquid_ml'] === null ? null : (string) $c['liquid_ml'], 'duty_liable' => $c['duty_liable'] === null ? null : (int) $c['duty_liable'] === 1];
            }
        }
        $modes = $skus === [] ? [] : (new SellingModes($db))->current($skus, $lockModes);
        $relay = self::relayRoutes($db, $skus);
        $supplier = $db->one('SELECT id, code, name, status, is_overseas, import_route_approved_at FROM supplier WHERE id = ?', [(int) $gr['supplier_id']]) ?? [];
        $po = null;
        $poLines = [];
        if ($gr['po_document_id'] !== null) {
            $po = $db->one('SELECT d.id, d.number, d.status, d.reverses_id, d.doc_type, p.state, p.supplier_id FROM document d LEFT JOIN purchase_order p ON p.document_id = d.id WHERE d.id = ?',
                [(int) $gr['po_document_id']]);
            foreach ($db->all('SELECT pl.line_no, pl.kind, pl.received_units, pl.units_per_pack, pl.pack_price, dl.sku_id, dl.qty FROM po_line pl '
                . 'JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no WHERE pl.document_id = ? ORDER BY pl.line_no', [(int) $gr['po_document_id']]) as $p) {
                $poLines[(int) $p['line_no']] = $p;
            }
        }
        $files = $db->all('SELECT df.role, f.mime FROM document_file df JOIN stored_file f ON f.id = df.file_id WHERE df.document_id = ?', [$documentId]);

        $cutoff = $settings->has('receiving.unstamped_refusal_from') ? $settings->get('receiving.unstamped_refusal_from') : null;
        $rate = (int) ($settings->has('receiving.duty_pence_per_ml') ? ($settings->get('receiving.duty_pence_per_ml') ?? 22) : 22);
        $backDays = (int) ($settings->has('receiving.backdate_max_days') ? ($settings->get('receiving.backdate_max_days') ?? 30) : 30);
        $fallback = (string) ($settings->has('receiving.mode_after_out_of_stock') ? $settings->get('receiving.mode_after_out_of_stock') : 'From-Warehouse');
        $tolerance = (int) ($settings->has('po.over_delivery_tolerance_pct') ? ($settings->get('po.over_delivery_tolerance_pct') ?? 10) : 10);

        $uk = new \DateTimeZone('Europe/London');
        $received = Clock::fromDb((string) $gr['received_at']);
        $receivedUk = $received->setTimezone($uk)->format('Y-m-d');
        // "Keyed late" is measured from the day the receipt was started (a delivery keyed on its day and posted the next morning
        // is not backdated); a paper receiving sheet keyed after an outage is (I138).
        $keyed = Clock::fromDb((string) $doc['created_at'])->setTimezone($uk);
        $keyedUk = $keyed->format('Y-m-d');
        $refusalRegime = $cutoff === null || $receivedUk >= $cutoff;
        $problems = [];
        $warnings = [];
        $add = static function (string $code, string $message, ?int $line = null) use (&$problems): void {
            $problems[] = ['code' => $code, 'message' => $message] + ($line === null ? [] : ['line' => $line]);
        };
        $poLabel = $po === null ? null : ($po['number'] ?? 'the purchase order');

        // ---- the header -------------------------------------------------------------------------------
        if ($lines === []) {
            $add('no_lines', 'The receipt has no lines yet.');
        }
        if (trim((string) ($doc['external_ref'] ?? '')) === '') {
            $add('invoice_number_required', 'Type the supplier\'s invoice number: it is compulsory, and a supplier\'s invoice is booked once.');
        }
        $hasInvoice = false;
        foreach ($files as $f) {
            if ($f['role'] === 'supplier_invoice' && in_array($f['mime'], self::INVOICE_MIMES, true)) {
                $hasInvoice = true;
            }
        }
        if (!$hasInvoice) {
            $add('invoice_file_required', 'Attach the supplier\'s invoice (a PDF, or a photo of a paper invoice) before posting.');
        }
        // The same invoice copy on another live receipt of this supplier (its number typed differently): one invoice, booked once (I171).
        foreach (self::invoiceCopyElsewhere($db, $documentId, (int) $gr['supplier_id']) as $other) {
            $add('invoice_copy_elsewhere', "The attached supplier invoice ({$other['name']}) is also the invoice of {$other['label']}: a supplier's invoice is received once. "
                . 'Attach the right invoice, or cancel one of the two receipts.');
        }
        if (($supplier['status'] ?? null) !== 'active') {
            $add('supplier_not_active', 'Supplier ' . ($supplier['code'] ?? '?') . ' is ' . str_replace('_', ' ', (string) ($supplier['status'] ?? 'missing'))
                . ': goods are received only from an active supplier (a second person approves the supplier first).');
        } elseif ((int) $supplier['is_overseas'] === 1 && $supplier['import_route_approved_at'] === null) {
            $add('import_route_not_approved', "Supplier {$supplier['code']} is overseas and its import route (where UK duty stamps are applied) is not approved.");
        } elseif ($db->value('SELECT 1 FROM review_task WHERE open_key = ? AND reason = ?', ['supplier:' . (int) $supplier['id'] . ':approval', 'import_route']) !== null) {
            $add('import_route_not_approved', "Supplier {$supplier['code']}: a change of its import route or of its overseas status waits for a second person's approval.");
        }
        if ($po !== null) {
            // Only a receipt that receives against the order needs it receivable: with every line "not against the order" the
            // order is a reference (it was closed or received meanwhile), and the desk can post (I145).
            $receives = false;
            foreach ($lines as $l) {
                $receives = $receives || $l['po_line_no'] !== null;
            }
            if ($po['doc_type'] !== 'PO' || $po['reverses_id'] !== null || (int) ($po['supplier_id'] ?? 0) !== (int) $gr['supplier_id']) {
                $add('po_other_supplier', "{$poLabel} is not a purchase order of supplier " . ($supplier['code'] ?? '?') . '.');
            } elseif ($po['status'] !== 'posted' || !in_array($po['state'], ['approved', 'sent', 'part_received'], true)) {
                $what = "{$poLabel} is " . str_replace('_', ' ', (string) ($po['state'] ?? $po['status']));
                if ($receives) {
                    $add('po_not_receivable', "{$what}: goods are received only against an approved, sent or part-received order (set the lines to "
                        . '"not against the order", or ask the buyer).');
                } else {
                    $warnings[] = "{$what}: no line is received against it.";
                }
            }
        }
        if ($received > $now->modify('+' . self::AHEAD_SEC . ' seconds')) {
            $add('received_in_future', 'The goods cannot have arrived after now: correct "received at".');
        }
        $earliest = $keyed->modify("-{$backDays} days")->format('Y-m-d');
        if ($receivedUk < $earliest) {
            $add('received_too_early', 'The goods arrived on ' . self::day($receivedUk) . ", more than {$backDays} days before the receipt was keyed: a receipt is dated "
                . "at most {$backDays} days back. Ask the purchasing manager.");
        }
        $backdated = $receivedUk < $keyedUk;
        if ($backdated && trim((string) ($gr['backdate_reason'] ?? '')) === '') {
            $add('backdate_reason_required', 'The goods arrived on ' . self::day($receivedUk) . ', before the receipt was keyed (' . self::day($keyedUk) . '): say why it is '
                . 'keyed late (it arrived yesterday, a paper receiving sheet while CW was down, ...).');
        }
        $unchecked = [];
        foreach ($lines as $no => $l) {
            if ($l['checked_at'] === null) {
                $unchecked[] = (int) $no;
            }
        }
        if ($gr['paperwork_ok'] === null) {
            $add('bench_check_required', 'Waiting for the goods-in bench: it has not said yet whether the supplier and the paperwork are credible'
                . ($unchecked === [] ? '.' : ', nor counted ' . self::lineList($unchecked) . '.'));
        } elseif ((int) $gr['paperwork_ok'] === 0) {
            $add('paperwork_not_credible', 'The bench found the supplier or the paperwork not credible: do not post. Refuse the delivery and cancel this receipt, '
                . 'or ask the purchasing manager.');
        }

        // ---- the lines --------------------------------------------------------------------------------
        $out = [];
        $byPoLine = [];
        $modeOf = [];
        $acceptedOf = [];
        $conflicts = [];
        $totals = ['units' => 0, 'accepted' => 0, 'verify' => 0, 'quarantine' => 0, 'refused' => 0, 'short' => 0, 'expected_duty_pence' => 0, 'net_e2' => 0, 'po_units' => 0];
        foreach ($lines as $no => $l) {
            $sku = (int) $l['sku_id'];
            $code = (string) ($l['sku_code'] ?? ('#' . $sku));
            $units = (int) $l['packs'] * (int) $l['units_per_pack'];
            $c = $comp[$sku] ?? ['blocked' => [], 'warnings' => [], 'stamp_required' => true, 'duty_unknown' => true, 'discontinued' => false];
            $card = $cards[$sku] ?? null;
            $lw = [];
            $lp = [];
            $poLine = null;
            $linked = $l['po_line_no'] !== null && $po !== null;
            if ($linked) {
                $poLine = $poLines[(int) $l['po_line_no']] ?? null;
                if ($poLine === null || $poLine['kind'] !== 'item') {
                    $lp[] = ['po_line_unknown', "Line {$no}: {$poLabel} has no item line {$l['po_line_no']}."];
                } elseif ((int) $poLine['sku_id'] !== $sku) {
                    $lp[] = ['po_line_item', "Line {$no}: {$poLabel} line {$l['po_line_no']} is another item than {$code}."];
                }
            } elseif ($po !== null) {
                $lw[] = "Line {$no} ({$code}) is not received against {$poLabel}.";
            }
            $exc = (int) $l['short_units'] + (int) $l['damaged_units'] + (int) $l['wrong_item_units'] + (int) $l['unstamped_units'];
            $stampRequired = (bool) $c['stamp_required'];
            $checked = $l['checked_at'] !== null;
            $split = ReceiptMath::split(['units' => $units, 'short_units' => (int) $l['short_units'], 'over_units' => (int) $l['over_units'],
                'damaged_units' => (int) $l['damaged_units'], 'wrong_item_units' => (int) $l['wrong_item_units'], 'unstamped_units' => (int) $l['unstamped_units'],
                'unstamped_action' => $l['unstamped_action'] === null ? null : (string) $l['unstamped_action'], 'linked_to_po' => $linked && $poLine !== null,
                'stamp_required' => $stampRequired, 'stamp_on_pack' => $l['stamp_on_pack'] === null ? null : (int) $l['stamp_on_pack']]);
            if ($c['blocked'] !== []) {
                $lp[] = ['item_blocked', "Line {$no}: {$code} is blocked by its item card (" . ItemRules::labels($c['blocked']) . '), so it cannot be received. '
                    . 'A person confirmed these fields; if they are wrong, correct the item card and confirm it again. Refuse the goods otherwise.'];
            }
            if ($c['warnings'] !== []) {
                $lw[] = "Line {$no} ({$code}): its item card breaks a rule no confirmation stands behind (" . ItemRules::labels($c['warnings']) . '): a warning until '
                    . 'someone confirms the card.';
            }
            if ($c['discontinued']) {
                $lw[] = "Line {$no} ({$code}) is marked discontinued on its item card.";
            }
            if (isset($relay[$sku])) {
                $lp[] = ['relay_route', "Line {$no}: {$code} gets its goods-in from the ERPNext relay of " . implode(', ', $relay[$sku]) . ' (one route per item, '
                    . 'plan §9): book this delivery in ERPNext, or end the relay for that site first.'];
            }
            if ($stampRequired && $c['duty_unknown'] && $card === null) {
                $lw[] = "Line {$no} ({$code}): no item card says whether it is duty-liable, so it is treated as duty-liable (its stamp is checked).";
            } elseif ($stampRequired && $c['duty_unknown']) {
                $lw[] = "Line {$no} ({$code}): its item card does not say whether it is duty-liable, so it is treated as duty-liable (its stamp is checked).";
            }
            if ($stampRequired && $split['arrived'] > 0 && $checked) {
                if ($l['stamp_on_pack'] === null) {
                    $lp[] = ['stamp_check_required', "Line {$no} ({$code}): the bench has not recorded the duty stamp check (duty-liable liquid must arrive stamped)."];
                } elseif ((int) $l['stamp_on_pack'] === 0 && (int) $l['unstamped_units'] !== $split['arrived']) {
                    $lp[] = ['stamp_not_on_pack', "Line {$no} ({$code}): the stamp is not on the outer retail pack, so the {$split['arrived']} units that arrived are unstamped: "
                        . 'record them as unstamped.'];
                } elseif ((int) $l['stamp_on_pack'] === 1 && $l['stamp_type'] === null && (int) $l['unstamped_units'] < $split['arrived']) {
                    $lp[] = ['stamp_type_required', "Line {$no} ({$code}): say which stamp it carries (digital or transitional)."];
                }
            }
            if ((int) $l['unstamped_units'] > 0) {
                if (!$stampRequired) {
                    $type = $card['product_type'] ?? null;
                    $lp[] = ['unstamped_not_duty', "Line {$no}: {$code} needs no duty stamp (its item card: " . ($type === null ? 'not duty-liable' : (ItemRules::TYPES[$type] ?? $type))
                        . '): record no unstamped units.'];
                } elseif ($l['unstamped_action'] === 'accept_pre_october' && $refusalRegime) {
                    $lp[] = ['unstamped_refused_now', "Line {$no} ({$code}): from " . ($cutoff === null ? 'now' : self::day((string) $cutoff)) . ' an unstamped duty-liable '
                        . 'delivery is refused at the door or quarantined: choose refuse or quarantine.'];
                } elseif ($l['unstamped_action'] === 'accept_pre_october' && mb_strlen(trim((string) $l['pre_october_evidence'])) < self::EVIDENCE_MIN) {
                    $lp[] = ['evidence_required', "Line {$no} ({$code}): accepting unstamped stock needs the supplier's evidence that it was made or imported before "
                        . '1 Oct 2026 (what it is, in at least ' . self::EVIDENCE_MIN . ' characters).'];
                } elseif ($l['unstamped_action'] === 'accept_pre_october') {
                    $lw[] = "Line {$no} ({$code}): {$l['unstamped_units']} unstamped units accepted on the supplier's evidence of manufacture before 1 Oct 2026: they "
                        . 'must be sold, returned or destroyed by 31 Mar 2027.';
                }
            }
            if ($split['extras'] !== null) {
                $lw[] = "Line {$no} ({$code}): its " . self::extrasText((int) $l['damaged_units'], (int) $l['over_units']) . ' arrived unstamped too (the stamp is not on the '
                    . 'pack, or the delivery\'s unstamped units are ' . ($split['extras'] === 'refuse' ? 'refused' : 'quarantined') . '), so '
                    . ($split['extras'] === 'refuse' ? 'they are refused at the door' : 'they are quarantined in UNSTAMPED') . ', not put in VERIFY.';
            }
            if ((int) $l['over_units'] > $units) {
                $lw[] = "Line {$no} ({$code}): {$l['over_units']} units over on a line of {$units} (the bench confirmed the count). They go to VERIFY at the line's cost "
                    . 'until the supplier invoices or collects them.';
            }
            if ($exc > 0 || (int) $l['over_units'] > 0) {
                $parts = [];
                foreach (['short_units' => 'short', 'over_units' => 'over', 'damaged_units' => 'damaged', 'wrong_item_units' => 'wrong item', 'unstamped_units' => 'unstamped'] as $k => $label) {
                    if ((int) $l[$k] > 0) {
                        $parts[] = (int) $l[$k] . ' ' . $label;
                    }
                }
                $lw[] = "Line {$no} ({$code}): " . implode(', ', $parts) . ' (an incident each when posted).';
            }
            if (PoMath::e4((string) $l['pack_price']) === 0) {
                $lw[] = "Line {$no} ({$code}) costs £0: a free item? The cost is provisional until the supplier invoice is matched (IM7).";
            }
            $dutyUnit = $card === null ? null : ReceiptMath::dutyPencePerUnit($card, $rate);
            $mode = SellingModes::resolve($modes[$sku] ?? ['mode' => null, 'previous' => null], (string) $l['mode_choice'], $fallback);
            $acceptedOf[$sku] = ($acceptedOf[$sku] ?? 0) + $split['accepted'];
            if (isset($modeOf[$sku]) && $modeOf[$sku]['mode'] !== $mode['mode']) {
                $conflicts[$sku][] = [$no, "{$code}: lines {$modeOf[$sku]['line_no']} and {$no} give it different selling modes ({$modeOf[$sku]['mode']}, {$mode['mode']}): choose one."];
            } elseif (!isset($modeOf[$sku])) {
                $modeOf[$sku] = $mode + ['line_no' => $no, 'current' => $modes[$sku] ?? ['mode' => null, 'previous' => null, 'from' => null, 'version' => 0]];
            }
            if ($linked && $poLine !== null && $poLine['kind'] === 'item') {
                $byPoLine[(int) $l['po_line_no']][] = ['line' => $no, 'units' => $split['po_units']];
            }
            foreach ($lp as [$pc, $pm]) {
                $add($pc, $pm, $no);
            }
            // The computed fields first: a posted line's own columns (stamp_required, ...) never shadow them.
            $out[$no] = [
                'units' => $units,
                'split' => $split,
                'stamp_required' => $stampRequired,
                'duty_unknown' => (bool) $c['duty_unknown'],
                'blocked' => $c['blocked'],
                'card' => $card,
                'duty_pence_unit' => $dutyUnit,
                'expected_duty_pence' => $dutyUnit === null ? null : $dutyUnit * $units,
                'mode' => $mode,
                'mode_current' => $modes[$sku] ?? ['mode' => null, 'previous' => null, 'from' => null, 'version' => 0],
                'relay' => $relay[$sku] ?? [],
                'po_line' => $poLine,
                'problems' => array_map(static fn (array $p): string => $p[1], $lp),
                'warnings' => $lw,
            ] + $l;
            array_push($warnings, ...$lw);
            $totals['units'] += $units;
            $totals['accepted'] += $split['accepted'];
            $totals['verify'] += $split['verify'];
            $totals['quarantine'] += $split['quarantine'];
            $totals['refused'] += $split['refused'];
            $totals['short'] += $split['short'];
            $totals['po_units'] += $split['po_units'];
            $totals['expected_duty_pence'] += $dutyUnit === null ? 0 : $dutyUnit * $units;
            $totals['net_e2'] += PoMath::e2((string) $l['amount']);
        }
        if ($gr['paperwork_ok'] !== null && $unchecked !== []) {
            $add('line_not_checked', 'Waiting for the goods-in bench: ' . self::lineList($unchecked) . ' not counted yet (each line is counted, and its duty stamp '
                . 'checked, after it was keyed or last changed).', $unchecked[0]);
        }
        // A bench check before the goods arrived (the received time moved later since): the time or the check is wrong (I170).
        $firstCheck = null;
        foreach ([$gr['checked_at'], ...array_column($lines, 'checked_at')] as $t) {
            if ($t !== null && ($firstCheck === null || (string) $t < $firstCheck)) {
                $firstCheck = (string) $t;
            }
        }
        if ($firstCheck !== null && Clock::fromDb($firstCheck) < $received->modify('-' . self::AHEAD_SEC . ' seconds')) {
            $add('checked_before_arrival', 'The bench checked this delivery at ' . self::time($firstCheck) . ', before it arrived (' . self::time((string) $gr['received_at'])
                . '): correct "received at", or have the bench check it again.');
        }
        // The selling mode (I136, I169): only an item the receipt accepts something of into MAIN gets a mode; the others keep theirs.
        foreach ($modeOf as $sku => $m) {
            if (($acceptedOf[$sku] ?? 0) > 0) {
                foreach ($conflicts[$sku] ?? [] as [$no, $msg]) {
                    $add('mode_conflict', $msg, $no);
                    $out[$no]['problems'][] = $msg;
                }
                continue;
            }
            $modeOf[$sku]['mode'] = $m['current']['mode'];
            $modeOf[$sku]['source'] = 'kept';
            foreach ($out as $no => $l) {
                if ((int) $l['sku_id'] === (int) $sku) {
                    $out[$no]['mode'] = ['mode' => $m['current']['mode'], 'source' => 'kept'];
                }
            }
            $code = (string) ($out[$m['line_no']]['sku_code'] ?? $sku);
            $warnings[] = "{$code}: nothing of it is accepted into MAIN, so its selling mode stays " . ($m['current']['mode'] ?? 'as it is')
                . ' (a receipt sets the mode only of what it accepts).';
        }
        // The over-delivery tolerance per PO line (po.over_delivery_tolerance_pct, I52), over every line of this receipt on it.
        foreach ($byPoLine as $poNo => $parts) {
            $pl = $poLines[$poNo];
            $mine = array_sum(array_column($parts, 'units'));
            $ordered = (int) $pl['qty'];
            $before = (int) $pl['received_units'];
            $cap = ReceiptMath::toleranceCap($ordered, $tolerance);
            $lineNos = implode(', ', array_column($parts, 'line'));
            if ($before + $mine > $cap) {
                $add('over_tolerance', "Line" . (count($parts) > 1 ? 's' : '') . " {$lineNos}: " . ($before + $mine) . " units of {$poLabel} line {$poNo} would be received against "
                    . "{$ordered} ordered ({$before} before this receipt): more than the {$tolerance}% over-delivery tolerance. Ask the buyer, or key the extra on a "
                    . 'line not against the order.', (int) $parts[0]['line']);
            } elseif ($before + $mine > $ordered) {
                $warnings[] = "{$poLabel} line {$poNo}: " . ($before + $mine) . " units received against {$ordered} ordered (within the {$tolerance}% tolerance).";
            }
        }
        foreach ($modeOf as $sku => $m) {
            $from = $m['current']['mode'];
            if ($m['source'] !== 'kept' && $from !== $m['mode']) {
                $code = (string) ($out[$m['line_no']]['sku_code'] ?? $sku);
                $warnings[] = "{$code}: selling mode " . ($from ?? 'not known') . " → {$m['mode']} (" . match ($m['source']) {
                    'chosen' => 'chosen on the receipt',
                    'previous' => 'its mode before it went Out-Of-Stock',
                    'fallback' => 'no earlier mode known: the fallback mode for Out-Of-Stock items',
                    default => 'its last mode',
                } . ').';
            }
        }
        return [
            'doc' => $doc,
            'header' => $gr + ['received_uk' => $receivedUk, 'received_label' => self::time((string) $gr['received_at']), 'received_day' => self::day($receivedUk),
                'keyed_uk' => $keyedUk, 'unchecked' => $unchecked, 'backdated' => $backdated, 'cutoff' => $cutoff, 'refusal_regime' => $refusalRegime,
                'has_invoice_file' => $hasInvoice, 'supplier' => $supplier, 'tolerance_pct' => $tolerance, 'duty_pence_per_ml' => $rate],
            'lines' => $out,
            'problems' => $problems,
            'warnings' => array_values(array_unique($warnings)),
            'totals' => $totals,
            'modes' => $modeOf,
            'po' => $po === null ? null : $po + ['lines' => $poLines],
        ];
    }

    /** "1 Jan 2027" of a 'Y-m-d' date (the screens' and messages' date format). */
    public static function day(string $ymd): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        return $d === false ? $ymd : $d->format('j M Y');
    }

    /** "7 Oct 2026 14:05" (UK time) of a stored UTC time. */
    public static function time(string $dbTime): string
    {
        return Clock::fromDb($dbTime)->setTimezone(new \DateTimeZone('Europe/London'))->format('j M Y H:i');
    }

    /** "line 4", "lines 1-3 and 7" (consecutive numbers as a range). @param list<int> $nos */
    public static function lineList(array $nos): string
    {
        sort($nos);
        $runs = [];
        foreach ($nos as $n) {
            if ($runs !== [] && $runs[count($runs) - 1][1] === $n - 1) {
                $runs[count($runs) - 1][1] = $n;
            } else {
                $runs[] = [$n, $n];
            }
        }
        $parts = array_map(static fn (array $r): string => $r[0] === $r[1] ? (string) $r[0] : ($r[1] === $r[0] + 1 ? "{$r[0]}, {$r[1]}" : "{$r[0]}-{$r[1]}"), $runs);
        $last = array_pop($parts);
        return (count($nos) === 1 ? 'line ' : 'lines ') . ($parts === [] ? $last : implode(', ', $parts) . ' and ' . $last);
    }

    private static function extrasText(int $damaged, int $over): string
    {
        return implode(' and ', array_filter([$damaged > 0 ? "{$damaged} damaged" : null, $over > 0 ? "{$over} over" : null])) . ' units';
    }

    /**
     * The other live receipts (draft, waiting, posted) of this supplier whose supplier invoice is one of this receipt's invoice
     * files (the same stored file: FileStore deduplicates), whatever invoice number they were keyed with (I171).
     *
     * @return list<array{label: string, name: string}>
     */
    public static function invoiceCopyElsewhere(Db $db, int $documentId, int $supplierId): array
    {
        $out = [];
        foreach ($db->all("SELECT DISTINCT d.id, d.number, d.status, f.original_name FROM document_file mine JOIN document_file df ON df.file_id = mine.file_id "
            . "AND df.role = 'supplier_invoice' AND df.document_id <> mine.document_id JOIN goods_receipt g ON g.document_id = df.document_id AND g.supplier_id = ? "
            . "JOIN document d ON d.id = df.document_id AND d.status IN ('draft', 'awaiting_approval', 'posted') JOIN stored_file f ON f.id = mine.file_id "
            . "WHERE mine.document_id = ? AND mine.role = 'supplier_invoice' ORDER BY d.id LIMIT 5", [$supplierId, $documentId]) as $r) {
            $out[] = ['label' => $r['number'] ?? (str_replace('_', ' ', (string) $r['status']) . ' receipt #' . $r['id']), 'name' => (string) $r['original_name']];
        }
        return $out;
    }

    /**
     * The items whose goods-in comes from a site's ERPNext relay (plan §9 "one route per item"): an item with a mapped listing
     * on a channel granted the relay's goods_in (channel.movement_types, R17). sku_id => the channel codes.
     *
     * @param list<int> $skus
     * @return array<int, list<string>>
     */
    public static function relayRoutes(Db $db, array $skus): array
    {
        if ($skus === []) {
            return [];
        }
        $channels = $db->all("SELECT id, code FROM channel WHERE JSON_CONTAINS(movement_types, '\"goods_in\"')");
        if ($channels === []) {
            return [];
        }
        $codes = array_column($channels, 'code', 'id');
        $out = [];
        foreach (array_chunk($skus, 1000) as $chunk) {
            foreach ($db->all("SELECT DISTINCT sku_id, channel_id FROM channel_listing WHERE status = 'mapped' AND channel_id IN ("
                . implode(', ', array_fill(0, count($codes), '?')) . ') AND sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') ORDER BY sku_id, channel_id',
                [...array_keys($codes), ...$chunk]) as $r) {
                $out[(int) $r['sku_id']][] = (string) $codes[(int) $r['channel_id']];
            }
        }
        return $out;
    }
}
