<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

use CW\Arrivals\Arrivals;
use CW\Arrivals\InvoiceSchedule;
use CW\Caller;
use CW\Documents\Document;
use CW\Files\FileStore;
use CW\Tests\Integration\Invoices\InvoicesTestCase;

/**
 * Base of pack J's tests (docs/decisions.md PJ1-PJ16): the invoice and return services of pack C, the arrivals service, the timed
 * posting job, and an invoice of goods ordered but not delivered yet (from its approved order, the totals typed), the way the owner
 * books a purchase today for goods that arrive tomorrow.
 */
abstract class ArrivalsTestCase extends InvoicesTestCase
{
    protected Arrivals $arrivals;

    protected function services(): void
    {
        parent::services();
        $this->arrivals = new Arrivals(self::$db, $this->docs, fn (): FileStore => $this->store);
    }

    protected function job(): InvoiceSchedule
    {
        return new InvoiceSchedule(self::$db, fn (): FileStore => $this->store);
    }

    /**
     * A draft invoice of $packs x $upp of a fresh item ordered at $price (an approved order; nothing delivered yet), its totals typed.
     *
     * @return array{doc: Document, sku: int, po: Document}
     */
    protected function invoiceOfOrder(Caller $desk, Caller $buyer, int $supplierId, int $packs, int $upp = 1, string $price = '4.0000', string $number = ''): array
    {
        $o = $this->approvedPoFor($buyer, $supplierId, $packs, $upp, $price);
        $d = $this->invoices->createDraft($desk, 'invoice', $supplierId, ['invoice_number' => $number !== '' ? $number : 'ARR-' . bin2hex(random_bytes(3)),
            'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->addOrder($desk, $d->id, $d->version, $o['doc']->id);
        $net = (string) self::$db->value('SELECT CAST(COALESCE(SUM(amount), 0) AS DECIMAL(14,2)) FROM document_line WHERE document_id = ?', [$d->id]);
        $d = $this->invoices->saveHeader($desk, $d->id, $d->version, ['net_total' => $net, 'vat_total' => '0.00']);
        return ['doc' => $d, 'sku' => $o['sku'], 'po' => $o['doc']];
    }

    protected function incoming(int $sku, string $warehouse = 'MAIN'): int
    {
        return (int) (self::$db->value('SELECT incoming FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId($warehouse), $sku]) ?? 0);
    }

    protected function docStatus(int $id): string
    {
        return (string) self::$db->value('SELECT status FROM document WHERE id = ?', [$id]);
    }

    /** @return list<array{state: string, units: int, sellable: int, grn: ?int}> the invoice's arrival rows */
    protected function arrivalRows(int $invoiceId): array
    {
        return array_map(static fn (array $r): array => ['state' => (string) $r['state'], 'units' => (int) $r['units'], 'sellable' => (int) $r['sellable'],
            'grn' => $r['grn_document_id'] === null ? null : (int) $r['grn_document_id']],
            self::$db->all('SELECT state, units, sellable, grn_document_id FROM invoice_arrival WHERE sinv_document_id = ? ORDER BY line_no', [$invoiceId]));
    }

    /** The item sold under the strict policy (the sites sell only what CW says is there). */
    protected function strict(int $sku): void
    {
        self::$db->exec("UPDATE sku SET sell_policy = 'strict' WHERE id = ?", [$sku]);
    }
}
