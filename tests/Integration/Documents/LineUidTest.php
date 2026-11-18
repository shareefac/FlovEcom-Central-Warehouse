<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Documents;

use CW\Documents\Documents;
use CW\Tests\Support\LineUids;

/**
 * A line's own id inside its document (document_line.uid; 0070, docs/decisions.md LV8): Documents::setLines() carries the id a line came
 * with and gives a line without one the document's next free id (document.next_line_uid, never reused), so who-is-here marks a line by
 * its id and not by its place. An id that is not a line of the draft, or twice in one save, is refused and nothing is saved. A reversal's
 * lines get none (it is never a draft). posted_hash is unchanged: the fingerprint covers no id. Through the generic base and the test ADJ.
 */
final class LineUidTest extends DocumentTestCase
{
    use LineUids;

    public function testASaveCarriesTheIdsItIsGivenAndGivesNewOnesNeverReused(): void
    {
        $sc = $this->staffUser('stock_controller');
        [$a, $b, $c] = [$this->item('strict', 10), $this->item('strict', 10), $this->item('strict', 10)];
        $d = $this->docs->createDraft($sc, 'ADJ', ['external_ref' => 'SUP-1']);
        self::assertSame(1, self::nextUidOf($d->id), 'a new draft: no line yet');
        $d = $this->docs->setLines($sc, $d->id, $d->version, [['sku_id' => $a, 'qty' => 1], ['sku_id' => $b, 'qty' => 2], ['sku_id' => $c, 'qty' => 3]]);
        self::assertSame([1, 2, 3], self::uidsOf($d->id));
        self::assertSame(4, self::nextUidOf($d->id));
        self::assertSame([1 => 1, 2 => 2, 3 => 3], $this->docs->lineUids($d->id), 'line number => its id, for the page');

        // Line 1 removed, line 3 changed and moved up, a new line last: the kept lines keep their ids, the new one takes 4.
        $d = $this->docs->setLines($sc, $d->id, $d->version, [['uid' => 3, 'sku_id' => $c, 'qty' => 30], ['uid' => 2, 'sku_id' => $b, 'qty' => 2],
            ['sku_id' => $a, 'qty' => 5]]);
        self::assertSame([3, 2, 4], self::uidsOf($d->id));
        self::assertSame([1 => 3, 2 => 2, 3 => 4], $this->docs->lineUids($d->id));
        self::assertSame(5, self::nextUidOf($d->id));
        self::assertSame([[1, $c, 30], [2, $b, 2], [3, $a, 5]], array_map(static fn (array $l): array => [$l['line_no'], $l['sku_id'], $l['qty']],
            $this->docs->lines($d->id)), 'the lines themselves as before: in list order, numbered 1..N');
        // A save without ids gives every line a new one: an id is never given twice, even to the same item.
        $d = $this->docs->setLines($sc, $d->id, $d->version, [['sku_id' => $c, 'qty' => 30], ['sku_id' => $b, 'qty' => 2]]);
        self::assertSame([5, 6], self::uidsOf($d->id));
        // An empty draft keeps its next free id; the next line takes it.
        $d = $this->docs->setLines($sc, $d->id, $d->version, []);
        self::assertSame([7, []], [self::nextUidOf($d->id), $this->docs->lineUids($d->id)]);
        $d = $this->docs->setLines($sc, $d->id, $d->version, [['uid' => null, 'sku_id' => $a, 'qty' => 1]]);
        self::assertSame([7], self::uidsOf($d->id), 'uid null: a new line');
        self::assertSame(8, self::nextUidOf($d->id));
        self::assertSame(['document.create', 'document.lines', 'document.lines', 'document.lines', 'document.lines', 'document.lines'], $this->audits($d->id));
    }

    public function testAnIdThatIsNotALineOfTheDraftOrIsGivenTwiceIsRefusedAndNothingIsSaved(): void
    {
        $sc = $this->staffUser('stock_controller');
        [$a, $b] = [$this->item('strict', 10), $this->item('strict', 10)];
        $d = $this->draft($sc, [['sku_id' => $a, 'qty' => 1], ['sku_id' => $b, 'qty' => 2]]);
        $other = $this->draft($sc, [['sku_id' => $a, 'qty' => 1], ['sku_id' => $b, 'qty' => 2], ['sku_id' => $b, 'qty' => 3]]);
        $before = [self::$db->all('SELECT * FROM document_line ORDER BY document_id, line_no'), self::$db->all('SELECT * FROM document ORDER BY id')];
        foreach ([
            'another draft\'s id' => [[['uid' => 3, 'sku_id' => $a, 'qty' => 1]], 1],
            'never given' => [[['uid' => 9, 'sku_id' => $a, 'qty' => 1]], 1],
            'twice' => [[['uid' => 1, 'sku_id' => $a, 'qty' => 1], ['uid' => 1, 'sku_id' => $b, 'qty' => 1]], 2],
            'twice, later' => [[['sku_id' => $a, 'qty' => 1], ['uid' => 2, 'sku_id' => $b, 'qty' => 1], ['uid' => 2, 'sku_id' => $b, 'qty' => 1]], 3],
            'zero' => [[['uid' => 0, 'sku_id' => $a, 'qty' => 1]], 1],
            'negative' => [[['uid' => -1, 'sku_id' => $a, 'qty' => 1]], 1],
            'text' => [[['uid' => '1', 'sku_id' => $a, 'qty' => 1]], 1],
            'too big' => [[['sku_id' => $a, 'qty' => 1], ['uid' => 4294967296, 'sku_id' => $a, 'qty' => 1]], 2],
            'a float' => [[['uid' => 1.0, 'sku_id' => $a, 'qty' => 1]], 1],
        ] as $what => [$lines, $line]) {
            $e = self::refused(400, 'bad_lines', fn () => $this->docs->setLines($sc, $d->id, $d->version, $lines));
            self::assertSame($line, $e->detail['line'] ?? null, $what);
        }
        // The other checks of a line come as before (an unknown field is still refused, with the id or without it).
        self::refused(400, 'bad_lines', fn () => $this->docs->setLines($sc, $d->id, $d->version, [['uid' => 1, 'sku_id' => $a, 'qty' => 1, 'rev' => 1]]));
        self::refused(400, 'bad_lines', fn () => $this->docs->setLines($sc, $d->id, $d->version, ['x' => ['uid' => 1, 'sku_id' => $a, 'qty' => 1]]), 'not a list');
        self::assertSame($before, [self::$db->all('SELECT * FROM document_line ORDER BY document_id, line_no'), self::$db->all('SELECT * FROM document ORDER BY id')],
            'nothing saved');
        self::assertSame([1, 2, 3], self::uidsOf($other->id));
    }

    public function testThePostingFingerprintCoversNoIdAndAReversalsLinesHaveNone(): void
    {
        $sc = $this->staffUser('stock_controller');
        [$a, $b] = [$this->item('strict', 10), $this->item('strict', 10)];
        $d = $this->draft($sc, [['sku_id' => $a, 'qty' => 5, 'unit_cost' => '1.25'], ['sku_id' => $b, 'qty' => -2, 'reason_code' => 'damaged']]);
        $d = $this->docs->setLines($sc, $d->id, $d->version, [['uid' => 2, 'sku_id' => $b, 'qty' => -2, 'reason_code' => 'damaged'],
            ['uid' => 1, 'sku_id' => $a, 'qty' => 5, 'unit_cost' => '1.25']]);
        self::assertSame([2, 1], self::uidsOf($d->id));
        $p = $this->docs->post($sc, $d->id, $d->version);
        self::assertSame('posted', $p->status);
        self::assertSame([2, 1], self::uidsOf($p->id), 'posting keeps the ids');
        self::assertPostingIgnoresUids($this->docs, $p->id);
        // The same content without ids (the lines as the code of before wrote them): the same fingerprint.
        $plain = $this->draft($sc, [['sku_id' => $b, 'qty' => -2, 'reason_code' => 'damaged'], ['sku_id' => $a, 'qty' => 5, 'unit_cost' => '1.25']]);
        self::$db->exec('UPDATE document_line SET uid = NULL WHERE document_id = ?', [$plain->id]);
        self::assertSame($this->docs->lines($p->id), $this->docs->lines($plain->id), 'the lines fingerprinted are the same with ids or without');

        $rev = $this->docs->reverse($sc, $p->id, 'entered_in_error', null);
        self::assertSame([null, null], self::uidsOf($rev->id), 'a reversal is never a draft: its lines need no id');
        self::assertSame(1, self::nextUidOf($rev->id));
        self::assertPostingIgnoresUids($this->docs, $rev->id);
        self::assertSame([1 => null, 2 => null], $this->docs->lineUids($rev->id));
    }
}
