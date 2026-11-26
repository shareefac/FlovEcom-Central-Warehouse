<?php

declare(strict_types=1);

namespace CW\Tests\Integration\OwnItems;

use CW\Caller;
use CW\CwException;
use CW\Items\ItemOwners;
use CW\Tests\Integration\Invoices\InvoicesTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1153 B3 (docs/decisions.md VB4, VB7, VB13): the go-live kit's tools. bin/item_owner_set.php marks a file of items from a past moment in ONE
 * transaction (a dry run rolls everything back; a bad line refuses the whole file; a run again changes nothing) and, with --bill, the bill
 * made final before the marks (Cloud Tech's SINV-000627) as the shop's own bill, derived from its lines' periods at its posting time (a bill
 * with any item not the shop's own then is refused). bin/item_owner_refresh.php's dry run writes nothing.
 */
final class OwnItemsKitToolsTest extends InvoicesTestCase
{
    private int $vb;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->vb = self::makeChannel('vapebig');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function codesFile(string $text): string
    {
        $f = (string) tempnam(sys_get_temp_dir(), 'vbp-codes-');
        file_put_contents($f, $text);
        $this->files[] = $f;
        return $f;
    }

    /** @return array{code: int, out: string} */
    private static function script(string $name, string ...$args): array
    {
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . "/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out];
    }

    private static function code(int $sku): string
    {
        return (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
    }

    public function testTheMarkingToolIsAllOrNothingAndMarksTheBillMadeFinalBefore(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Cloud Tech Kit');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 1, 10, '36.2500', 1);
        $inv = $this->invoiceDelivery($desk, $x['grn']->id, '36.25', '7.25');
        $other = self::makeSku('Elux Legend 4000 Kiwi');
        $file = $this->codesFile(self::code($x['sku']) . "\n" . self::code($other) . "\n");
        $from = (string) self::$db->value("SELECT DATE_FORMAT(posted_at - INTERVAL 3 DAY, '%Y-%m-%d %H:%i:%s') FROM document WHERE id = ?", [$inv->id]);
        $bad = self::script('item_owner_set', '--items=' . $this->codesFile(self::code($x['sku']) . "\nCW-999999\n"), "--from={$from}", '--reason=the count', '--apply');
        self::assertSame(2, $bad['code'], $bad['out']);
        self::assertStringContainsString('line(s) 2', $bad['out']);
        $dry = self::script('item_owner_set', "--items={$file}", "--from={$from}", '--reason=the count that brought them in', "--bill={$inv->number}");
        self::assertSame(0, $dry['code'], $dry['out']);
        self::assertStringContainsString('DRY RUN', $dry['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_owner'));
        self::assertNull(self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$inv->id]));
        $done = self::script('item_owner_set', "--items={$file}", "--from={$from}", '--reason=the count that brought them in', "--bill={$inv->number}", '--apply');
        self::assertSame(0, $done['code'], $done['out']);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM item_owner WHERE to_at IS NULL'));
        self::assertSame($this->vb, (int) self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$inv->id]));
        self::assertNull($this->invoices->record($inv->id)['owed_e2']);
        $again = self::script('item_owner_set', "--items={$file}", "--from={$from}", '--reason=again', "--bill={$inv->number}", '--apply');
        self::assertSame(0, $again['code'], $again['out']);
        self::assertStringContainsString('0 items marked', $again['out']);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM item_owner'));
    }

    public function testABillWithAnItemNotTheShopsOwnAtItsPostingIsNeverMarked(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Cloud Tech Late');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 1, 10, '36.2500', 1);
        $inv = $this->invoiceDelivery($desk, $x['grn']->id, '36.25', '7.25');
        // marked only from now: after the bill was made final
        (new ItemOwners(self::$db))->add(Caller::system('own_items_kit'), self::code($x['sku']), 'from now');
        try {
            (new ItemOwners(self::$db))->markBill(Caller::system('own_items_kit'), (string) $inv->number, true);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('bill_not_own', $e->errorCode);
        }
        self::assertNull(self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$inv->id]));
        $dry = self::script('item_owner_refresh', '--items=' . $this->codesFile(self::code($x['sku'])), '--from=2026-11-01', '--to=2099-12-31');
        self::assertSame(0, $dry['code'], $dry['out']);
        self::assertStringContainsString('dry run', $dry['out']);
    }
}
