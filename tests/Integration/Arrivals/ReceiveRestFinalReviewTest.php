<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

use CW\Invariants;

/**
 * CW-1155: the final reviews of 2db71ed (cw_work/receive_rest_20261122/final_astra_{a,b}.txt, final_fable_review.md; the lead's F1-F4;
 * docs/decisions.md RR32), each shown failing first on 2db71ed: a receipt of the rest keeps its pack prices whatever a save sends, so no save
 * answers whether a hidden price matched, and its record holds no price (F1); V7 finds a posted receipt of the rest without its stored figures
 * (F3); a credit cancelled reopens an incident the rest resolved by itself (F4).
 */
final class ReceiveRestFinalReviewTest extends ArrivalsTestCase
{
    use RestFixtures;

    public function testARestDraftKeepsItsPricesWhateverASaveSends(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Hidden Price Ltd');
        $sku = self::makeSku('Hidden price');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.2345']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $r = $this->arrivals->receiveRest($desk, $p->id);
        $goodsIn = $this->staffUser('goods_in');
        $line = $this->grns->lines($r->id)[0];
        foreach (['9.9999', '1.2345', ''] as $typed) {
            $d = $this->grns->saveDraft($goodsIn, $r->id, $this->docs->get($r->id)->version, [], [['pack_price' => $typed] + $line]);
            self::assertSame('draft', $d->status, "typed {$typed}: the same answer");
            self::assertSame('1.2345', (string) self::$db->value('SELECT pack_price FROM grn_line WHERE document_id = ?', [$r->id]), "typed {$typed}: the invoice's price kept");
        }
        $detail = (array) json_decode((string) self::$db->value("SELECT CAST(detail AS CHAR) FROM audit_log WHERE action = 'arrival.receive_rest' AND entity_id = ?",
            [(string) $r->id]), true);
        self::assertSame([[$sku, 1, 0]], $detail['shape'] ?? null, 'its record holds no price (the audit screens show it to people who do not read money)');
        self::assertStringNotContainsString('1.2345', json_encode($detail, JSON_THROW_ON_ERROR));
        $this->bench($this->staffUser('goods_in'), $r->id);
        $this->post($desk, $r->id);
        self::assertSame('matched', $this->stored($p->id)['match_state']);
    }

    public function testV7FindsAPostedReceiptOfTheRestWithoutItsFigures(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('V7 Rest Ltd');
        $sku = self::makeSku('V7 rest');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $r = $this->restReceipt($desk, $p->id);
        $row = self::$db->one('SELECT * FROM grn_invoice_left WHERE grn_document_id = ?', [$r->id]);
        self::assertNotNull($row);
        self::$db->exec('DELETE FROM grn_invoice_left WHERE grn_document_id = ?', [$r->id]);
        $v = implode("\n", Invariants::check(self::$db));
        self::$db->exec('INSERT INTO grn_invoice_left (grn_document_id, units_left, value_left, updated_at) VALUES (?, ?, ?, ?)',
            [$r->id, $row['units_left'], $row['value_left'], $row['updated_at']]);
        self::assertStringContainsString("V7 delivery {$r->id} is named by an invoice, credit note or return but has no stored figures left to invoice", $v);
    }

    public function testACreditCancelledReopensTheIncidentTheRestResolved(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Credit Back Ltd');
        $sku = self::makeSku('Credit back');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 2, '1.0000');
        $cn = $this->postWithTotals($desk, $cn, '2.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        $r = $this->restReceipt($desk, $p->id);
        self::assertSame(['resolved', "The rest came on {$r->number}."], $this->shortIncident($g->id, 1));
        $this->invoices->reverse($desk, $cn->id, 'entered_in_error', null);
        self::assertSame([['line' => 1, 'units' => 2, 'amount' => '2.00']], $this->owed($p->id));
        self::assertSame(['open', null], $this->shortIncident($g->id, 1), 'two are waited for again (it stayed resolved)');
    }
}
