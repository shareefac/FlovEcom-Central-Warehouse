<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0059 (ERPNext mode changes no longer wait in Approvals; docs/decisions.md MO1, MO3), applied to a scratch schema that already holds
 * requests: every open ERPNext mode change is closed with one audit row, nothing else is touched, the switch is added off and agreed,
 * and a second run changes nothing.
 */
final class Migration0059Test extends IntegrationTestCase
{
    private const FILE = '0059_mode_review_off.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m59', $this->dir);
        }
    }

    public function testOpenModeChangesAreClosedOnceAndNothingElseMoves(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bALTER\s+TABLE\b|\bDROP\b/i', implode(";\n", $statements), 'nothing removed, no table changed');

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m59', '0058_store_choice.sql');
        $sku = $db->insert("INSERT INTO sku (name) VALUES ('Mr Blue 10mg')");
        $review = static fn (string $source, string $status, string $at): int => $db->insert(
            'INSERT INTO policy_review (sku_id, source, site_mode, current_policy, proposed_policy, status, resolution, resolved_at, created_at) '
            . "VALUES (?, ?, 'From-Warehouse', 'legacy', 'strict', ?, ?, ?, ?)",
            [$sku, $source, $status, $status === 'open' ? null : 'superseded', $status === 'open' ? null : $at, $at]);
        $open1 = $review('erp_update_product', 'open', '2026-11-01 10:00:00.000000');
        $open2 = $review('erp_update_product', 'open', '2026-11-03 12:00:00.000000');
        $done = $review('erp_update_product', 'dismissed', '2026-10-30 09:00:00.000000');
        $gate = $review('count_gate', 'open', '2026-11-02 08:00:00.000000');
        $untouched = $db->all('SELECT * FROM policy_review WHERE id IN (?, ?) ORDER BY id', [$done, $gate]);

        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        $after = static fn (): array => $db->all('SELECT id, CAST(status AS CHAR) AS s, resolution, resolved_by, resolved_at IS NOT NULL AS at_set, note '
            . 'FROM policy_review WHERE id IN (?, ?) ORDER BY id', [$open1, $open2]);
        $closed = $after();
        foreach ($closed as $row) {
            self::assertSame(['dismissed', 'review_off', null, 1], [$row['s'], $row['resolution'], $row['resolved_by'], (int) $row['at_set']]);
            self::assertStringContainsString('no longer wait in Approvals', (string) $row['note']);
        }
        self::assertSame($untouched, $db->all('SELECT * FROM policy_review WHERE id IN (?, ?) ORDER BY id', [$done, $gate]), 'the others as they were');
        $audit = $db->all("SELECT actor, entity_id, CAST(detail AS CHAR) AS d FROM audit_log WHERE action = 'queues.modes.close_all'");
        self::assertCount(1, $audit);
        self::assertSame(['system:migrate', 'erp_update_product'], [$audit[0]['actor'], $audit[0]['entity_id']]);
        $detail = json_decode((string) $audit[0]['d'], true);
        self::assertSame(2, $detail['closed']);
        self::assertStringStartsWith('2026-11-01 10:00:00', (string) $detail['oldest']);
        self::assertStringStartsWith('2026-11-03 12:00:00', (string) $detail['newest']);
        $setting = $db->one("SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = 'relay.mode_review'");
        self::assertSame(['bool', 'false', 0, 'MO1'], [$setting['value_type'], $setting['v'], (int) $setting['provisional'], $setting['decision']]);
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'relay.mode_review' "
            . "AND version = 1 AND action = 'baseline' AND actor = 'system:migrate'"));

        // A second run (a run stopped half way, run again) adds no row and changes none.
        $snapshot = [$db->all('SELECT * FROM policy_review ORDER BY id'), $db->all('SELECT * FROM audit_log ORDER BY id'),
            $db->all("SELECT * FROM app_setting WHERE setting_key = 'relay.mode_review'"), $db->all('SELECT * FROM config_change ORDER BY id')];
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame($snapshot, [$db->all('SELECT * FROM policy_review ORDER BY id'), $db->all('SELECT * FROM audit_log ORDER BY id'),
            $db->all("SELECT * FROM app_setting WHERE setting_key = 'relay.mode_review'"), $db->all('SELECT * FROM config_change ORDER BY id')]);

        // A run stopped after its audit row and before the close (the Codex review, MEDIUM 1): run again, it closes the rest and writes
        // no second audit row.
        $late = $review('erp_update_product', 'open', '2026-11-04 09:00:00.000000');
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame('dismissed', (string) $db->value('SELECT CAST(status AS CHAR) FROM policy_review WHERE id = ?', [$late]));
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'queues.modes.close_all'"));
    }
}
