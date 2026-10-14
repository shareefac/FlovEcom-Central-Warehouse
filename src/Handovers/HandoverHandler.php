<?php

declare(strict_types=1);

namespace CW\Handovers;

use CW\Caller;
use CW\Clock;
use CW\Company\CompanyDetails;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\DocumentHandler;
use CW\Documents\SizeApproval;
use CW\Movements;
use CW\StockOps\CostHints;

/**
 * What a hand-over (TRD) does when it is made final or cancelled (pack H, IM11; docs/decisions.md PH1-PH5). Stock.php stays the only
 * writer: the stock leaves our warehouse as `trade_sale` (a document-only movement, I8: on hand down, no cost: it goes out at the
 * average cost) in ONE Movements::bookForDocument call, booked last (I21).
 *
 * validate() (and post() again, under the posting's locks): the customer exists and is switched on; the warehouse is ours and switched
 * on; every line names an item, a number of units above zero and a price (0 or more); a protected item (sell policy strict or stopped)
 * never goes below zero (the stock out's rule, SO3: a hand-over carries no reason that could allow it); the VAT code is known when our
 * company is (or may be) VAT registered.
 *
 * post(), module rows first: the invoice's sums (HandoverMath), the VAT code and rate used, our company details and the customer's as
 * printed (the invoice PDF waits until ours were confirmed, Handovers::invoice), the prices remembered for the customer (a later day
 * wins), what the customer owes (counterparty_entry `handover`, + gross); then the stock; then the below-zero rule again on the
 * balances this transaction holds.
 *
 * reverse() (module rows only; Documents books the exact negation of the stock): the reversal gets the same header with the sums negated,
 * and the gross comes off what the customer owes (`handover_reversal`). Remembered prices stay: a price agreed is still the price.
 */
final class HandoverHandler implements DocumentHandler, SizeApproval
{
    public const TYPE = 'TRD';
    /** Sell policies whose stock must never be oversold (Stock::flagShortfalls; StockOpHandler::PROTECTED). */
    public const PROTECTED = ['strict', 'stopped'];

    private readonly Movements $moves;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock */
    public function __construct(private readonly Db $db, private readonly ?\Closure $clock = null)
    {
        $this->moves = new Movements($db, null, $clock);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(Db $db, Document $doc, array $lines): void
    {
        $h = self::header($db, $doc->id) ?? throw new CwException('customer_required', 'say who the stock is handed to', 422, ['field' => 'counterparty']);
        $c = $db->one('SELECT id, name, is_active FROM counterparty WHERE id = ?', [(int) $h['counterparty_id']])
            ?? throw new CwException('unknown_customer', 'there is no such customer', 422, ['field' => 'counterparty']);
        if ((int) $c['is_active'] !== 1) {
            throw new CwException('customer_off', "{$c['name']} is switched off: switch it on again on the Customers page, or choose another", 422, ['field' => 'counterparty']);
        }
        self::checkWarehouse($db, $doc->warehouseId);
        foreach ($lines as $l) {
            $no = (int) $l['line_no'];
            if ($l['sku_id'] === null || $l['qty'] === null || (int) $l['qty'] <= 0) {
                throw new CwException('bad_line', "line {$no}: a hand-over's line names an item and a number of units above zero", 422, ['line' => $no]);
            }
            if ($l['warehouse_id'] !== null && (int) $l['warehouse_id'] !== $doc->warehouseId) {
                throw new CwException('line_warehouse', "line {$no}: every line leaves from the hand-over's warehouse", 422, ['line' => $no]);
            }
            if ($l['unit_cost'] === null) {
                throw new CwException('price_required', "line {$no}: say the price of a unit", 422, ['line' => $no]);
            }
        }
        $this->vatFor($db, $h);
        $short = self::shortfalls($db, $doc, $lines, false);
        if ($short !== null) {
            throw $short;
        }
    }

    /** No "stock put back without a supplier document": a hand-over only takes stock out. */
    public function approvalUnits(Db $db, Document $doc, array $lines): int
    {
        return 0;
    }

    /** SizeApproval: the units handed over and their worth at the agreed prices, in whole pounds rounded up (before VAT). */
    public function size(Db $db, Document $doc, array $lines): array
    {
        $units = 0;
        foreach ($lines as $l) {
            if ($l['sku_id'] !== null && $l['qty'] !== null) {
                $units += abs((int) $l['qty']);
            }
        }
        return ['units' => $units, 'value' => CostHints::wholePounds(ltrim(HandoverMath::net($lines), '-'))];
    }

    public function post(Db $db, Document $doc, array $lines, Caller $caller, string $opKey): int
    {
        $h = self::header($db, $doc->id) ?? throw new \LogicException("hand-over {$doc->id} has no header");
        // The customer's row is read FOR SHARE: a payment received for it (FOR UPDATE) waits, so its "more than owed" sees this hand-over.
        $c = $db->one('SELECT * FROM counterparty WHERE id = ? FOR SHARE', [(int) $h['counterparty_id']]) ?? throw new \LogicException('no customer');
        if ((int) $c['is_active'] !== 1) {
            throw new CwException('customer_off', "{$c['name']} is switched off", 422, ['field' => 'counterparty']);
        }
        [$vatCode, $rate] = $this->vatFor($db, $h);
        $sums = HandoverMath::totals($lines, $rate);
        $company = (new CompanyDetails($db))->company();
        $buyer = ['name' => (string) $c['name'], 'kind' => (string) $c['kind'], 'address' => (string) $c['address'], 'company_number' => (string) $c['company_number'],
            'vat_number' => (string) $c['vat_number'], 'email' => (string) $c['email'], 'phone' => (string) $c['phone'], 'version' => (int) $c['version']];
        $now = Clock::db($this->now());
        $db->exec('UPDATE handover SET vat_code = ?, vat_rate = ?, currency = ?, net_amount = ?, vat_amount = ?, gross_amount = ?, company_version = ?, '
            . 'company_confirmed = ?, company_snapshot = CAST(? AS JSON), buyer_snapshot = CAST(? AS JSON), updated_at = ? WHERE document_id = ?',
            [$vatCode, $rate, (string) ($company['currency'] ?? 'GBP'), $sums['net'], $sums['vat'], $sums['gross'], (int) $company['version'], $company['confirmed'] ? 1 : 0,
                json_encode($company, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), json_encode($buyer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $now, $doc->id]);
        // The prices remembered for the customer: each item's price on this hand-over (its last line), unless a later day set one.
        $day = (string) ($doc->docDate ?? $this->now()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d'));
        $prices = [];
        foreach ($lines as $l) {
            $prices[(int) $l['sku_id']] = (string) $l['unit_cost'];
        }
        ksort($prices);
        foreach ($prices as $sku => $price) {
            $db->exec('INSERT INTO counterparty_price (counterparty_id, sku_id, unit_price, priced_on, source, document_id, source_ref, updated_by, updated_actor, updated_at) '
                . "VALUES (?, ?, ?, ?, 'handover', ?, NULL, ?, ?, ?) AS new ON DUPLICATE KEY UPDATE "
                . 'unit_price = IF(new.priced_on >= counterparty_price.priced_on, new.unit_price, counterparty_price.unit_price), '
                . 'source = IF(new.priced_on >= counterparty_price.priced_on, new.source, counterparty_price.source), '
                . 'document_id = IF(new.priced_on >= counterparty_price.priced_on, new.document_id, counterparty_price.document_id), '
                . 'source_ref = IF(new.priced_on >= counterparty_price.priced_on, NULL, counterparty_price.source_ref), '
                . 'updated_by = IF(new.priced_on >= counterparty_price.priced_on, new.updated_by, counterparty_price.updated_by), '
                . 'updated_actor = IF(new.priced_on >= counterparty_price.priced_on, new.updated_actor, counterparty_price.updated_actor), '
                . 'updated_at = IF(new.priced_on >= counterparty_price.priced_on, new.updated_at, counterparty_price.updated_at), '
                . 'priced_on = GREATEST(new.priced_on, counterparty_price.priced_on)',
                [(int) $c['id'], $sku, $price, $day, $doc->id, $caller->staffUserId, $caller->actor, $now]);
        }
        $db->insert('INSERT INTO counterparty_entry (counterparty_id, kind, amount, document_id, created_by, created_actor) VALUES (?, \'handover\', ?, ?, ?, ?)',
            [(int) $c['id'], $sums['gross'], $doc->id, $caller->staffUserId, $caller->actor]);
        $code = (string) $db->value('SELECT code FROM warehouse WHERE id = ?', [$doc->warehouseId]);
        $out = [];
        $units = 0;
        foreach ($lines as $l) {
            $out[] = ['document_line' => (int) $l['line_no'], 'sku_id' => (int) $l['sku_id'], 'qty' => (int) $l['qty']];
            $units += (int) $l['qty'];
        }
        $this->moves->bookForDocument($caller, ['document_id' => $doc->id, 'doc_ref' => (string) $doc->number], $opKey,
            [['type' => 'trade_sale', 'warehouse' => $code, 'lines' => $out]]);
        $short = self::shortfalls($db, $doc, $lines, true);
        if ($short !== null) {
            throw $short;
        }
        return $units;
    }

    public function reverse(Db $db, Document $original, Document $reversal, array $lines, Caller $caller, string $opKey): void
    {
        $h = self::header($db, $original->id) ?? throw new \LogicException("hand-over {$original->id} has no header");
        $neg = static fn (mixed $v): ?string => $v === null ? null : HandoverMath::negate((string) $v);
        $now = Clock::db($this->now());
        $db->exec('INSERT INTO handover (document_id, counterparty_id, vat_code, vat_rate, currency, net_amount, vat_amount, gross_amount, company_version, '
            . 'company_confirmed, company_snapshot, buyer_snapshot, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS JSON), CAST(? AS JSON), ?, ?)',
            [$reversal->id, (int) $h['counterparty_id'], $h['vat_code'], $h['vat_rate'], (string) $h['currency'], $neg($h['net_amount']), $neg($h['vat_amount']),
                $neg($h['gross_amount']), $h['company_version'], $h['company_confirmed'], $h['company_snapshot'], $h['buyer_snapshot'], $now, $now]);
        $e = $db->one("SELECT counterparty_id, amount FROM counterparty_entry WHERE document_id = ? AND kind = 'handover'", [$original->id])
            ?? throw new \LogicException("hand-over {$original->id} has no entry on what the customer owes");
        $db->insert("INSERT INTO counterparty_entry (counterparty_id, kind, amount, document_id, created_by, created_actor) VALUES (?, 'handover_reversal', ?, ?, ?, ?)",
            [(int) $e['counterparty_id'], HandoverMath::negate((string) $e['amount']), $reversal->id, $caller->staffUserId, $caller->actor]);
    }

    // ------------------------------------------------------------------------------------------
    // Rules
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null a hand-over's header row */
    public static function header(Db $db, int $documentId): ?array
    {
        return $db->one('SELECT * FROM handover WHERE document_id = ?', [$documentId]);
    }

    /**
     * The VAT code and rate a hand-over is invoiced with: the one chosen on it, else the customer's, else our company's. None when our
     * company said it is not VAT registered (it charges no VAT). 422 vat_code_required when none is known and our company is, or may
     * be, VAT registered; 422 vat_code_off for a code no longer in use.
     *
     * @param array<string, mixed> $h the handover row
     * @return array{0: ?string, 1: ?string} code, rate percent
     */
    public function vatFor(Db $db, array $h): array
    {
        $company = (new CompanyDetails($db))->company();
        if (($company['vat_registered'] ?? null) === false) {
            return [null, null];
        }
        $code = $h['vat_code'] ?? $db->value('SELECT vat_code FROM counterparty WHERE id = ?', [(int) $h['counterparty_id']]) ?? (($company['vat_code'] ?? '') === '' ? null : $company['vat_code']);
        if ($code === null) {
            throw new CwException('vat_code_required', 'say the VAT code of this hand-over (or give the customer or the company details one)', 422, ['field' => 'vat_code']);
        }
        $r = $db->one('SELECT rate_percent, is_active FROM vat_code WHERE code = ?', [(string) $code]);
        if ($r === null || (int) $r['is_active'] !== 1) {
            throw new CwException('vat_code_off', "the VAT code {$code} is not in use", 422, ['field' => 'vat_code']);
        }
        return [(string) $code, (string) $r['rate_percent']];
    }

    /** The warehouse the stock leaves from: one of ours, switched on (422 otherwise). */
    public static function checkWarehouse(Db $db, ?int $warehouseId): void
    {
        if ($warehouseId === null) {
            throw new CwException('warehouse_required', 'say which warehouse the stock leaves from', 422, ['field' => 'warehouse']);
        }
        $w = $db->one('SELECT name, is_active, CAST(stock_owner AS CHAR) AS stock_owner FROM warehouse WHERE id = ?', [$warehouseId])
            ?? throw new CwException('unknown_warehouse', 'there is no such warehouse', 422, ['field' => 'warehouse']);
        if ((int) $w['is_active'] !== 1) {
            throw new CwException('warehouse_inactive', "{$w['name']} is switched off", 422, ['field' => 'warehouse']);
        }
        if ($w['stock_owner'] !== 'own') {
            throw new CwException('not_own_stock', "{$w['name']} holds another account's stock: only our own stock is handed over", 422, ['field' => 'warehouse']);
        }
    }

    /**
     * The first item that would go below zero, as the refusal (null: none): per item the hand-over takes, a protected item (strict or
     * stopped) may not end with less available (on hand - reserved) than nothing. $booked: the stock is already on the balances (post(),
     * which holds them locked); else the balances as they are now.
     *
     * @param list<array<string, mixed>> $lines
     */
    private static function shortfalls(Db $db, Document $doc, array $lines, bool $booked): ?CwException
    {
        $down = [];
        $first = [];
        foreach ($lines as $l) {
            if ($l['sku_id'] === null || (int) $l['qty'] <= 0) {
                continue;
            }
            $sku = (int) $l['sku_id'];
            $down[$sku] = ($down[$sku] ?? 0) + (int) $l['qty'];
            $first[$sku] ??= (int) $l['line_no'];
        }
        if ($down === [] || $doc->warehouseId === null) {
            return null;
        }
        $skus = array_keys($down);
        $in = implode(', ', array_fill(0, count($skus), '?'));
        $bal = [];
        foreach ($db->all("SELECT sku_id, on_hand, allocated, held FROM stock_balance WHERE warehouse_id = ? AND sku_id IN ({$in})", [$doc->warehouseId, ...$skus]) as $b) {
            $bal[(int) $b['sku_id']] = $b;
        }
        foreach ($db->all("SELECT id, code, CAST(sell_policy AS CHAR) AS sell_policy FROM sku WHERE id IN ({$in})", $skus) as $s) {
            $sku = (int) $s['id'];
            if (!in_array((string) $s['sell_policy'], self::PROTECTED, true)) {
                continue;
            }
            $b = $bal[$sku] ?? ['on_hand' => 0, 'allocated' => 0, 'held' => 0];
            $n = $down[$sku];
            $onHand = (int) $b['on_hand'] - ($booked ? 0 : $n);
            $reserved = (int) $b['allocated'] + (int) $b['held'];
            if ($onHand - $reserved < 0) {
                $have = $onHand + $n - $reserved;
                return new CwException('below_zero', "line {$first[$sku]}: {$s['code']} would go below zero ({$have} available, {$n} handed over)", 422,
                    ['line' => $first[$sku], 'sku_code' => (string) $s['code'], 'have' => $have, 'wanted' => $n]);
            }
        }
        return null;
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock ?? static fn (): \DateTimeImmutable => Clock::now())();
    }
}
