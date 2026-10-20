<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Ui\Controller\InvoicesController;
use CW\Ui\Controller\SupplierReturnsController;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * What the intro of a supplier's paper and of a return says in each state (the 6.3 browser test and its Codex reviews): a final record
 * never tells the person to check a draft, a claim without goods never says goods go back, and a cancellation tells what it did only
 * once it is final (waiting for an OK, refused or stopped, it changed nothing).
 */
final class PurchasingIntrosTest extends TestCase
{
    private const STATES = ['draft', 'awaiting_approval', 'posted', 'rejected', 'cancelled', 'reversed'];

    public function testAReturnsIntro(): void
    {
        foreach (self::STATES as $status) {
            foreach ([true, false] as $claimOnly) {
                $original = SupplierReturnsController::introKey(false, $status, $claimOnly);
                $expect = $status === 'posted'
                    ? ($claimOnly ? 'supplier_return_claim_final' : 'supplier_return_final')
                    : ($claimOnly ? 'supplier_return_claim' : 'supplier_return');
                self::assertSame($expect, $original, "{$status}, claim only: " . var_export($claimOnly, true));
                $cancel = SupplierReturnsController::introKey(true, $status, $claimOnly);
                self::assertSame($status === 'posted' ? 'supplier_return_reversal' : 'supplier_return_reversal_pending', $cancel, "a cancellation, {$status}");
            }
        }
        foreach (['supplier_return', 'supplier_return_claim', 'supplier_return_final', 'supplier_return_claim_final', 'supplier_return_reversal',
            'supplier_return_reversal_pending'] as $key) {
            self::assertCount(2, Words::PAGE_INTRO[$key], $key);
        }
        self::assertStringContainsString('changes nothing until it is final', Words::PAGE_INTRO['supplier_return_reversal_pending'][1]);
        self::assertStringNotContainsString('Goods going back', Words::PAGE_INTRO['supplier_return_claim_final'][0]);
    }

    public function testASuppliersPapersIntro(): void
    {
        foreach (self::STATES as $status) {
            foreach (['invoice', 'credit_note'] as $kind) {
                $prefix = $kind === 'invoice' ? 'supplier_invoice' : 'supplier_credit_note';
                self::assertSame($status === 'posted' ? $prefix . '_final' : $prefix, InvoicesController::introKey(false, $kind, $status), "{$kind}, {$status}");
                self::assertSame($status === 'posted' ? 'supplier_paper_reversal' : 'supplier_paper_reversal_pending', InvoicesController::introKey(true, $kind, $status),
                    "the cancellation of a {$kind}, {$status}");
            }
        }
        self::assertStringNotContainsString('draft', Words::PAGE_INTRO['supplier_invoice_final'][1]);
        self::assertStringNotContainsString('draft', Words::PAGE_INTRO['supplier_credit_note_final'][1]);
    }
}
