<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

use CW\Tests\Support\TestDb;

/**
 * CW-1155 "Receive the rest" races with two real connections (arrival_worker.php, one process each; docs/decisions.md RR12): two people
 * pressing "Receive the rest" at once land on one draft; "Receive the rest" and "Claim what did not come" at once: exactly one of them,
 * the other refused; the posting of the rest against a claim, an acceptance of the difference, or the first receipt's reversal at the same
 * moment (and with the posting's locks held): the posting books, the other is refused; never a deadlock. The invariants (J1-J6) hold after
 * every race (assertPostConditions).
 */
final class ReceiveRestRaceTest extends ArrivalsTestCase
{
    use RestFixtures;

    /**
     * @param list<array<string, mixed>> $jobs
     * @return list<array<string, mixed>>
     */
    private static function race(array $jobs): array
    {
        $start = microtime(true) + 1.0;
        $procs = [];
        foreach ($jobs as $i => $job) {
            $pipes = [];
            $p = proc_open([PHP_BINARY, __DIR__ . '/arrival_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
                dirname(__DIR__, 3), ['CW_SLOT' => (string) getenv('CW_SLOT'), 'CW_DB_NAME' => TestDb::name()] + getenv());
            self::assertIsResource($p, "worker {$i}");
            fwrite($pipes[0], json_encode(['start' => $start + (float) ($job['delay'] ?? 0)] + $job, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $procs[] = [$p, $pipes];
        }
        $out = [];
        foreach ($procs as $i => [$p, $pipes]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($p);
            $line = json_decode(trim($stdout), true);
            self::assertTrue($code === 0 && is_array($line), "worker {$i} failed (exit {$code}): " . substr($stderr . $stdout, 0, 2000));
            $out[] = $line;
        }
        return $out;
    }

    /** @return array{desk: \CW\Caller, supplier: array<string, mixed>, sku: int, invoice: \CW\Documents\Document, first: \CW\Documents\Document} */
    private function shortInvoice(string $name): array
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people($name);
        $sku = self::makeSku($name . ' item');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        return ['desk' => $desk, 'supplier' => $s, 'sku' => $sku, 'invoice' => $p, 'first' => $g];
    }

    /** A benched draft of the rest of $x's invoice: the job that posts it. @param array<string, mixed> $x @return array<string, mixed> */
    private function restPosting(array $x): array
    {
        $r = $this->arrivals->receiveRest($x['desk'], $x['invoice']->id);
        $this->bench($this->staffUser('goods_in'), $r->id);
        return ['op' => 'post_receipt', 'staff_id' => $x['desk']->staffUserId, 'document_id' => $r->id, 'version' => $this->docs->get($r->id)->version];
    }

    public function testTwoPeoplePressingReceiveTheRestLandOnOneDraft(): void
    {
        $x = $this->shortInvoice('Race Two Rest Ltd');
        $other = $this->staffUser('goods_in');
        $res = self::race([['op' => 'receive_rest', 'staff_id' => $x['desk']->staffUserId, 'document_id' => $x['invoice']->id],
            ['op' => 'receive_rest', 'staff_id' => $other->staffUserId, 'document_id' => $x['invoice']->id]]);
        self::assertSame([200, 200], [$res[0]['status'], $res[1]['status']], json_encode($res));
        self::assertSame($res[0]['id'], $res[1]['id'], 'the same draft');
        self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks']);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM goods_receipt_invoice WHERE sinv_document_id = ?', [$x['invoice']->id]), 'the first and one draft');
    }

    public function testReceiveTheRestAndClaimAtOnceExactlyOneWins(): void
    {
        foreach (['claim held first' => true, 'at the same moment' => false] as $round => $claimFirst) {
            $x = $this->shortInvoice('Race Rest Claim ' . ($claimFirst ? 'A' : 'B') . ' Ltd');
            $claim = ['op' => 'claim', 'staff_id' => $x['desk']->staffUserId, 'document_id' => $x['first']->id];
            $rest = ['op' => 'receive_rest', 'staff_id' => $x['desk']->staffUserId, 'document_id' => $x['invoice']->id];
            $res = $claimFirst ? self::race([$claim + ['hold_ms' => 800], $rest + ['after_lock' => 'invoice_arrival']]) : self::race([$claim, $rest]);
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks'], $round);
            $codes = [$res[0]['status'] === 200 ? 'claimed' : $res[0]['error'], $res[1]['status'] === 200 ? 'rest' : $res[1]['error']];
            self::assertContains($codes, [['claimed', 'claimed'], ['rest_being_received', 'rest']], $round . ': ' . json_encode($res));
            if ($claimFirst) {
                self::assertSame(['claimed', 'claimed'], $codes, $round . ': the claim held the invoice: the rest is refused as claimed');
            }
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM supplier_return r JOIN document d ON d.id = r.document_id AND d.status = 'draft' "
                . 'WHERE r.grn_document_id = ?', [$x['first']->id]) + (int) self::$db->value("SELECT COUNT(*) FROM goods_receipt_invoice g JOIN document d ON d.id = g.grn_document_id "
                . "AND d.status = 'draft' WHERE g.sinv_document_id = ?", [$x['invoice']->id]), $round . ': exactly one live action');
        }
    }

    /**
     * The Codex check of a0d33a2, H1: a credit of the owed line and the posting of the rest at once: the credit note takes the invoice's arrival
     * rows too, so the posting reads what is owed after it (refused: more on the paperwork than is owed), or posts first (the credit then
     * leaves the line billed for fewer than came: the match says so).
     */
    public function testACreditOfTheOwedLineAgainstThePostingOfTheRest(): void
    {
        foreach (['credit held first' => true, 'at the same moment' => false] as $round => $creditFirst) {
            $x = $this->shortInvoice('Race Credit Rest ' . ($creditFirst ? 'A' : 'B') . ' Ltd');
            $post = $this->restPosting($x);
            $cn = $this->invoices->createDraft($x['desk'], 'credit_note', (int) $x['supplier']['id'], ['credits_id' => $x['invoice']->id]);
            $cn = $this->invoices->addCredit($x['desk'], $cn->id, $cn->version, 1, 2, '1.0000');
            $cn = $this->invoices->saveHeader($x['desk'], $cn->id, $this->docs->get($cn->id)->version, ['net_total' => '2.00', 'vat_total' => '0.00',
                'invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
            $credit = ['op' => 'post_doc', 'staff_id' => $x['desk']->staffUserId, 'document_id' => $cn->id, 'version' => $cn->version];
            $res = $creditFirst ? self::race([$credit + ['hold_ms' => 800], $post + ['after_lock' => 'invoice_arrival']]) : self::race([$credit, $post]);
            $what = $round . ': ' . json_encode($res);
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks'], $what);
            self::assertSame(200, $res[0]['status'], $what);
            if ($creditFirst || $res[1]['status'] !== 200) {
                self::assertSame([422, 'not_owed'], [$res[1]['status'], $res[1]['error']], $what . ': three owed, five on the paperwork');
                self::assertSame([['line' => 1, 'units' => 3, 'amount' => '3.00']], $this->owed($x['invoice']->id), $what);
                self::assertSame(0, $this->onHand($x['sku']), $what);
            } else {
                self::assertSame([5, 'needs_review'], [$this->onHand($x['sku']), $this->stored($x['invoice']->id)['match_state']], $what . ': billed 3, 5 came');
            }
        }
    }

    /** The Codex check of a0d33a2, M3: a new part of the rest started while an earlier part is taken back, by the same person: no deadlock. */
    public function testANewPartAgainstTheReversalOfAnEarlierPart(): void
    {
        foreach (['reversal held first' => true, 'at the same moment' => false] as $round => $reverseFirst) {
            $x = $this->shortInvoice('Race Part Back ' . ($reverseFirst ? 'A' : 'B') . ' Ltd');
            $r1 = $this->restReceipt($x['desk'], $x['invoice']->id, [1 => ['short_units' => '2']]);
            $back = ['op' => 'reverse', 'staff_id' => $x['desk']->staffUserId, 'document_id' => $r1->id];
            $next = ['op' => 'receive_rest', 'staff_id' => $x['desk']->staffUserId, 'document_id' => $x['invoice']->id];
            $res = $reverseFirst ? self::race([$back + ['hold_ms' => 800], $next + ['after_lock' => 'invoice_arrival']]) : self::race([$back, $next]);
            $what = $round . ': ' . json_encode($res);
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks'], $what);
            self::assertSame([200, 200], [$res[0]['status'], $res[1]['status']], $what);
            $units = (int) self::$db->value('SELECT qty FROM document_line WHERE document_id = ?', [$res[1]['id']]);
            self::assertContains($units, [2, 5], $what . ': what was owed when it read it (fewer than owed is a part arrival: the rest stays owed)');
            self::assertSame([['line' => 1, 'units' => 5, 'amount' => '5.00']], $this->owed($x['invoice']->id), $what);
        }
    }

    public function testThePostingOfTheRestAgainstAClaimAnAcceptanceAndTheFirstReceiptsReversal(): void
    {
        foreach (['claim', 'accept', 'reverse'] as $other) {
            foreach (['posting held first' => true, 'at the same moment' => false] as $round => $postFirst) {
                $x = $this->shortInvoice("Race Post {$other} " . ($postFirst ? 'A' : 'B') . ' Ltd');
                $post = $this->restPosting($x);
                $job = ['op' => $other, 'staff_id' => $x['desk']->staffUserId, 'document_id' => $other === 'accept' ? $x['invoice']->id : $x['first']->id];
                $res = $postFirst ? self::race([$post + ['hold_ms' => 800], $job + ['after_lock' => 'invoice_arrival']]) : self::race([$post, $job]);
                $what = "{$other}, {$round}: " . json_encode($res);
                self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks'], $what);
                self::assertSame(200, $res[0]['status'], $what . ': the rest is booked');
                self::assertContains($res[1]['error'] ?? 'done', match ($other) {
                    'claim' => ['rest_being_received', 'nothing_missing'],
                    'accept' => ['rest_being_received', 'not_needs_review', 'match_changed'],
                    default => ['rest_received'],
                }, $what);
                self::assertSame([5, 0, 'matched'], [$this->onHand($x['sku']), $this->incoming($x['sku']), $this->stored($x['invoice']->id)['match_state']], $what);
                self::assertSame([], $this->owed($x['invoice']->id), $what);
            }
        }
    }
}
