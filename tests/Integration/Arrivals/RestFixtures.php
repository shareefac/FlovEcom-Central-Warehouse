<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

use CW\Caller;
use CW\Documents\Document;

/**
 * CW-1155 "Receive the rest" (docs/decisions.md RR1-RR20): the fixtures of its tests, on ArrivalsTestCase: a final invoice counted on
 * arrival of goods lines no order names (its copy attached, as the desk books them), its first receipt (Goods In's Receive, the bench's
 * findings by line, posted) and a receipt of the rest booked in the same way.
 */
trait RestFixtures
{
    /**
     * A final invoice counted on arrival of these goods lines: [sku, packs, units per pack, pack price], its copy attached, its totals typed.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: string}> $lines
     */
    protected function finalInvoice(Caller $desk, int $supplierId, array $lines, string $number = ''): Document
    {
        $d = $this->invoices->createDraft($desk, 'invoice', $supplierId, ['invoice_number' => $number !== '' ? $number : 'RR-' . bin2hex(random_bytes(4)),
            'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, array_map(static fn (array $l): array => ['kind' => 'goods', 'sku_id' => $l[0], 'packs' => $l[1],
            'units_per_pack' => $l[2], 'pack_price' => $l[3]], $lines));
        $this->invoices->attach($desk, $d->id, $this->pdf('Invoice ' . $d->id), 'invoice.pdf', 'supplier_invoice');
        $net = (string) self::$db->value('SELECT CAST(COALESCE(SUM(amount), 0) AS DECIMAL(14,2)) FROM document_line WHERE document_id = ?', [$d->id]);
        $d = $this->invoices->saveHeader($desk, $d->id, $this->docs->get($d->id)->version, ['net_total' => $net, 'vat_total' => '0.00']);
        return $this->invoices->submit($desk, $d->id, $d->version);
    }

    /**
     * Goods In receives an invoice (its first receipt): the bench's findings by receipt line ([line => ['short_units' => 'n']]), posted.
     *
     * @param array<int, array<string, mixed>> $bench
     */
    protected function firstReceipt(Caller $desk, int $invoiceId, array $bench = []): Document
    {
        $g = $this->arrivals->receive($desk, $invoiceId);
        $this->bench($this->staffUser('goods_in'), $g->id, $bench);
        return $this->post($desk, $g->id);
    }

    /**
     * "Receive the rest" of an invoice, the bench's findings, posted.
     *
     * @param array<int, array<string, mixed>> $bench
     */
    protected function restReceipt(Caller $desk, int $invoiceId, array $bench = []): Document
    {
        $g = $this->arrivals->receiveRest($desk, $invoiceId);
        $this->bench($this->staffUser('goods_in'), $g->id, $bench);
        return $this->post($desk, $g->id);
    }

    /** @return list<array{line: int, units: int, amount: string}> what the invoice still waits for */
    protected function owed(int $invoiceId): array
    {
        return array_map(static fn (array $o): array => ['line' => $o['line_no'], 'units' => $o['units'], 'amount' => $o['amount']], $this->arrivals->owedOf($invoiceId));
    }

    /** @return array{0: string, 1: ?string} the status and resolution of the short incident of a receipt line */
    protected function shortIncident(int $grnId, int $line): array
    {
        $r = self::$db->one("SELECT status, resolution FROM incident WHERE dedupe_key = ?", ["grn:{$grnId}:{$line}:short"]);
        return $r === null ? ['none', null] : [(string) $r['status'], $r['resolution'] === null ? null : (string) $r['resolution']];
    }

    protected function left(int $grnId): ?int
    {
        $v = self::$db->value('SELECT units_left FROM grn_invoice_left WHERE grn_document_id = ?', [$grnId]);
        return $v === null ? null : (int) $v;
    }

    protected function arriveRows(int $grnId): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE document_id = ? AND movement_type = 'arrive'", [$grnId]);
    }
}
