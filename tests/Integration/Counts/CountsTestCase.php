<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Counts;

use CW\Caller;
use CW\Clock;
use CW\Counts\CountPlan;
use CW\Counts\CountReviews;
use CW\Counts\CountSheets;
use CW\CwException;
use CW\Documents\Document;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Tests\Support\MappingTestCase;

/**
 * Base of the stock counting tests (pack A2a; docs/decisions.md CT1-CT18): the document base with the production handlers (the real
 * CNT), the count sheet service, the differences to check and the plan, all on the stock tests' clock ($this->now: a test moves it
 * between a count and what happens after it), staff with roles, settings and the CNT rule changed for a test and put back in tearDown
 * (seed rows: the VALUE only, never who changed it), and the full invariant check after every test.
 */
abstract class CountsTestCase extends MappingTestCase
{
    protected Documents $docs;
    protected CountSheets $sheets;
    protected CountReviews $reviews;
    protected CountPlan $plan;
    /** @var array<string, string> setting key => its value_json to put back */
    private array $savedSettings = [];
    /** @var array<string, mixed>|null the CNT rule to put back */
    private ?array $savedRule = null;

    protected function setUp(): void
    {
        parent::setUp();
        $clock = fn (): \DateTimeImmutable => $this->now;
        $this->docs = new Documents(self::$db, DocumentHandlers::all(self::$db, $clock), $clock);
        $this->sheets = new CountSheets(self::$db, $this->docs, $clock);
        $this->reviews = new CountReviews(self::$db, $this->docs, $clock);
        $this->plan = new CountPlan(self::$db);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSettings as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        if ($this->savedRule !== null) {
            self::$db->exec("UPDATE document_type SET review_rule = ?, review_limit_units = ? WHERE code = 'CNT'",
                [$this->savedRule['review_rule'], $this->savedRule['review_limit_units']]);
        }
        $this->savedSettings = [];
        $this->savedRule = null;
        parent::tearDown();
    }

    /** Changes a setting's value for this test (the value only: who changed it last stays, Migration0009Test reads it). */
    protected function setting(string $key, int|bool $value): void
    {
        $this->savedSettings[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, $key]);
    }

    /** The reviewer check of a stock count for this test: none | all | over_limit (with its limit). */
    protected function countRule(string $rule, ?int $limit = null): void
    {
        $this->savedRule ??= (array) self::$db->one("SELECT CAST(review_rule AS CHAR) AS review_rule, review_limit_units FROM document_type WHERE code = 'CNT'");
        self::$db->exec("UPDATE document_type SET review_rule = ?, review_limit_units = COALESCE(?, review_limit_units) WHERE code = 'CNT'", [$rule, $limit]);
    }

    protected function later(string $by): void
    {
        $this->now = $this->now->modify('+' . $by);
    }

    /** A sheet opened by $who at MAIN for these items. @param list<int> $skus */
    protected function sheet(Caller $who, array $skus, array $header = []): Document
    {
        return $this->sheets->open($who, $header + ['warehouse' => self::warehouseId('MAIN')], $skus);
    }

    /** Enters the quantity of an item's line on a sheet (null clears it), as its opener. */
    protected function enter(Caller $who, Document $sheet, int $sku, ?int $qty): void
    {
        $this->sheets->setQuantities($who, $sheet->id, [$this->lineOf($sheet->id, $sku) => $qty]);
    }

    /** Posts a sheet at the version it has now. */
    protected function post(Caller $who, Document $sheet): Document
    {
        return $this->sheets->post($who, $sheet->id, $this->docs->get($sheet->id)->version);
    }

    protected function lineOf(int $sheetId, int $sku): int
    {
        return (int) self::$db->value('SELECT line_no FROM count_sheet_line WHERE document_id = ? AND sku_id = ?', [$sheetId, $sku]);
    }

    /** @return array<string, mixed> a sheet line's row */
    protected function sheetLine(int $sheetId, int $sku): array
    {
        return (array) self::$db->one('SELECT * FROM count_sheet_line WHERE document_id = ? AND sku_id = ?', [$sheetId, $sku]);
    }

    /**
     * The on-hand ledger rows a document booked: [item, movement, qty, the time it counts from].
     *
     * @return list<array{0: int, 1: string, 2: int, 3: string}>
     */
    protected function ledgerOf(int $documentId): array
    {
        return array_map(static fn (array $r): array => [(int) $r['sku_id'], (string) $r['movement_type'], (int) $r['qty_delta'], (string) $r['effective_at']],
            self::$db->all("SELECT sku_id, movement_type, qty_delta, effective_at FROM stock_ledger WHERE document_id = ? AND bucket = 'on_hand' ORDER BY sku_id, id", [$documentId]));
    }

    protected function onHand(int $sku, string $warehouse = 'MAIN'): int
    {
        return (int) (self::$db->value('SELECT on_hand FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId($warehouse), $sku]) ?? 0);
    }

    protected function countedAt(int $sku, string $warehouse = 'MAIN'): ?string
    {
        $v = self::$db->value('SELECT counted_at FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId($warehouse), $sku]);
        return $v === null ? null : (string) $v;
    }

    protected function nowDb(): string
    {
        return Clock::db($this->now);
    }

    protected static function refusedWith(string $code, callable $fn): CwException
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
            return $e;
        }
        self::fail("expected {$code}");
    }
}
