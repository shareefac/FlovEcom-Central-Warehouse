<?php

declare(strict_types=1);

namespace CW\Tests\Integration\StockOps;

use CW\Caller;
use CW\Documents\Document;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\StockOps\OtherAccounts;
use CW\StockOps\StockOps;
use CW\Tests\Support\MappingTestCase;

/**
 * Base of the stock record tests (pack A1; docs/decisions.md SO1-SO16): the document base with the production handlers (the real ADJ,
 * SIN, SOUT, TRF, REL), the stock record service with a temporary file store (never the server's own, docs/dev.md), the other
 * accounts' balances, staff with roles, warehouses and places made for a test, document rules and reasons changed for a test and put
 * back in tearDown (seed rows), and the full invariant check after every test (stock, value sequence, documents D1-D7, the stock
 * records O1-O6).
 */
abstract class StockOpsTestCase extends MappingTestCase
{
    protected Documents $docs;
    protected StockOps $ops;
    protected OtherAccounts $accounts;
    protected string $dir;
    /** @var array<string, array<string, mixed>> document_type code => its row to put back */
    private array $savedTypes = [];
    /** @var array<string, array<string, mixed>> reason code => its row to put back */
    private array $savedReasons = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_sop_' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/store', 0700, true);
        $store = new FileStore(self::$db, new LocalFileStorage($this->dir . '/store'));
        $this->docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $this->ops = new StockOps(self::$db, $this->docs, static fn (): FileStore => $store);
        $this->accounts = new OtherAccounts(self::$db);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedTypes as $code => $row) {
            self::$db->exec('UPDATE document_type SET review_rule = ?, review_limit_units = ?, approval_rule = ?, approval_limit_units = ?, size_approval = ?, '
                . 'size_units = ?, size_value = ? WHERE code = ?', [$row['review_rule'], $row['review_limit_units'], $row['approval_rule'], $row['approval_limit_units'],
                    $row['size_approval'], $row['size_units'], $row['size_value'], $code]);
        }
        foreach ($this->savedReasons as $code => $row) {
            self::$db->exec('UPDATE reason_code SET needs_given_to = ?, below_zero = ?, is_active = ? WHERE code = ?',
                [$row['needs_given_to'], $row['below_zero'], $row['is_active'], $code]);
        }
        $this->savedTypes = [];
        $this->savedReasons = [];
        if (str_starts_with(basename($this->dir), 'cw_sop_')) {
            exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    /** Changes a kind of record's rules for this test (admin connection; put back in tearDown). @param array<string, mixed> $set */
    protected function rule(string $type, array $set): void
    {
        $this->savedTypes[$type] ??= (array) self::$db->one('SELECT * FROM document_type WHERE code = ?', [$type]);
        foreach ($set as $col => $v) {
            self::$db->exec("UPDATE document_type SET `{$col}` = ? WHERE code = ?", [$v, $type]);
        }
    }

    /** Changes a reason's rules for this test (put back in tearDown). @param array<string, mixed> $set */
    protected function reasonRule(string $code, array $set): void
    {
        $this->savedReasons[$code] ??= (array) self::$db->one('SELECT * FROM reason_code WHERE code = ?', [$code]);
        foreach ($set as $col => $v) {
            self::$db->exec("UPDATE reason_code SET `{$col}` = ? WHERE code = ?", [$v, $code]);
        }
    }

    /** A warehouse made for this test (TestDb::clean removes it). */
    protected function warehouse(string $code, string $owner = 'own', ?string $entity = null, bool $sellable = false): int
    {
        return (int) self::$db->insert('INSERT INTO warehouse (code, name, is_sellable, is_active, stock_owner, owner_entity, sort_order) VALUES (?, ?, ?, 1, ?, ?, 50)',
            [$code, ucfirst(strtolower($code)) . ' room', $sellable ? 1 : 0, $owner, $entity]);
    }

    /** A place inside a warehouse. */
    protected function place(string $warehouse, string $code): int
    {
        return (int) self::$db->insert('INSERT INTO warehouse_location (warehouse_id, code, name) VALUES (?, ?, ?)', [self::warehouseId($warehouse), $code, "Place {$code}"]);
    }

    /**
     * A draft stock record of $kind by $who with these lines.
     *
     * @param array<string, mixed> $header warehouse (a code or an id), location, to_warehouse, to_location, reason_code, given_to, ...
     * @param list<array<string, mixed>> $lines
     */
    protected function draft(string $kind, Caller $who, array $header, array $lines): Document
    {
        foreach (['warehouse', 'to_warehouse'] as $k) {
            if (isset($header[$k]) && is_string($header[$k]) && preg_match('/^[A-Z]/', $header[$k]) === 1) {
                $header[$k] = self::warehouseId($header[$k]);
            }
        }
        $doc = $this->ops->createDraft($who, $kind, $header + ['warehouse' => self::warehouseId('MAIN')]);
        return $lines === [] ? $doc : $this->ops->saveLines($who, $doc->id, $doc->version, $lines);
    }

    /** A record drafted and posted by $who (posted, or waiting for an OK when a rule asks). @param array<string, mixed> $header @param list<array<string, mixed>> $lines */
    protected function posted(string $kind, Caller $who, array $header, array $lines): Document
    {
        $d = $this->draft($kind, $who, $header, $lines);
        return $this->ops->post($who, $d->id, $d->version);
    }

    /** The open task of a document (0 when none). */
    protected function openTask(int $documentId, string $kind = 'approval'): int
    {
        return (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = ? AND state = 'open'", [$documentId, $kind]);
    }

    /**
     * The ledger rows a document booked: [warehouse code, movement, qty, unit cost].
     *
     * @return list<array{0: string, 1: string, 2: int, 3: ?string}>
     */
    protected function ledgerOf(int $documentId): array
    {
        return array_map(static fn (array $r): array => [(string) $r['code'], (string) $r['movement_type'], (int) $r['qty_delta'], $r['unit_cost'] === null ? null : (string) $r['unit_cost']],
            self::$db->all('SELECT w.code, l.movement_type, l.qty_delta, l.unit_cost FROM stock_ledger l JOIN warehouse w ON w.id = l.warehouse_id '
                . "WHERE l.document_id = ? AND l.bucket = 'on_hand' ORDER BY l.id", [$documentId]));
    }

    /** on_hand of an item at a warehouse (0 without a balance). */
    protected function onHand(int $sku, string $warehouse = 'MAIN'): int
    {
        return (int) (self::$db->value('SELECT on_hand FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId($warehouse), $sku]) ?? 0);
    }

    /** Asserts a refusal's error code (and runs the call). */
    protected static function refusedWith(string $code, callable $fn): \CW\CwException
    {
        try {
            $fn();
        } catch (\CW\CwException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
            return $e;
        }
        self::fail("expected {$code}");
    }
}
