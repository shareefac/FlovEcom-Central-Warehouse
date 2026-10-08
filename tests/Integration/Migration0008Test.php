<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\IntegrationTestCase;

/**
 * 0008 (I17-I23): the seeded reference lists (22 reason codes, 8 document types, 8 number series at 0) and the CHECKs
 * that hold a document, a review task and a stored file to their rules even against admin SQL. 0008 has no backfill,
 * so it is tested on the slot's schema (TestDb::clean keeps the seeds and resets the series).
 */
final class Migration0008Test extends IntegrationTestCase
{
    private const CHECK_VIOLATED = 3819;

    public function testTheSeeds(): void
    {
        // 0010 (the pos task, I48) adds the three PO reversal reasons po_amended, supplier_cannot_supply and not_needed: 0008's 22 are the rest.
        $po = "('po_amended', 'supplier_cannot_supply', 'not_needed')";
        self::assertSame(22, (int) self::$db->value("SELECT COUNT(*) FROM reason_code WHERE code NOT IN {$po}"));
        self::assertSame(['damaged', 'faulty', 'expired', 'lost_theft', 'found', 'wrong_item_booked', 'supplier_error', 'supplier_collection', 'destroyed',
            'free_gift', 'sample', 'unstamped_found', 'count_difference', 'recount', 'data_correction', 'customer_return_resaleable',
            'customer_return_damaged', 'entered_in_error', 'duplicate', 'opening_rebase', 'review_rejected', 'other'],
            array_map('strval', self::$db->column("SELECT code FROM reason_code WHERE code NOT IN {$po} ORDER BY sort_order")));
        $flags = static fn (string $code): array => array_map('intval', (array) self::$db->one(
            'SELECT needs_note, is_gift, system_only, is_active FROM reason_code WHERE code = ?', [$code]));
        self::assertSame(['needs_note' => 0, 'is_gift' => 1, 'system_only' => 0, 'is_active' => 1], $flags('free_gift'));
        self::assertSame(['needs_note' => 0, 'is_gift' => 0, 'system_only' => 1, 'is_active' => 1], $flags('review_rejected'));
        self::assertSame(['needs_note' => 0, 'is_gift' => 0, 'system_only' => 1, 'is_active' => 1], $flags('opening_rebase'));
        foreach (['destroyed', 'unstamped_found', 'data_correction', 'other'] as $c) {
            self::assertSame(1, $flags($c)['needs_note'], $c);
        }
        // 0019 (Y51) adds the order screens' uses to the reasons they offered, `other` among them.
        self::assertSame('adjustment,write_off,count,return,supplier_return,reversal,po_cancel,po_draft_cancel,po_amend',
            self::$db->value("SELECT applies_to FROM reason_code WHERE code = 'other'"));
        self::assertSame(['increase', 'decrease', 'either'], [self::$db->value("SELECT direction FROM reason_code WHERE code = 'found'"),
            self::$db->value("SELECT direction FROM reason_code WHERE code = 'damaged'"), self::$db->value("SELECT direction FROM reason_code WHERE code = 'recount'")]);
        self::assertSame(['entered_in_error', 'duplicate', 'review_rejected', 'other'],
            array_map('strval', self::$db->column("SELECT code FROM reason_code WHERE FIND_IN_SET('reversal', applies_to) > 0 AND code NOT IN {$po} ORDER BY sort_order")));

        $types = self::$db->all('SELECT code, prefix, phase, review_rule, review_limit_units, approval_rule, approval_limit_units, review_due_days FROM document_type ORDER BY code');
        self::assertCount(8, $types);
        $by = array_column($types, null, 'code');
        self::assertSame(['ADJ', 'CNT', 'DN', 'GRN', 'PO', 'SINV', 'TRD', 'WO'], array_keys($by));
        foreach ($by as $code => $t) {
            self::assertSame($code, $t['prefix']);
        }
        self::assertSame(['positive_without_supplier_doc', 10, 'all'], [$by['ADJ']['approval_rule'], $by['ADJ']['approval_limit_units'], $by['ADJ']['review_rule']]);
        self::assertSame(['over_limit', 10], [$by['CNT']['review_rule'], $by['CNT']['review_limit_units']]);
        self::assertSame(['over_limit', 10], [$by['WO']['review_rule'], $by['WO']['review_limit_units']]);
        // 0010 (provisional decision 11, I48) changed the PO row to review 'all' and approval over_value 10000 (Migration0010Test).
        self::assertSame(['all', 7, 'I-2'], [$by['PO']['review_rule'], $by['PO']['review_due_days'], $by['PO']['phase']]);
        self::assertSame(['I-3', 'I-4', 'I-4', 'I-6'], [$by['GRN']['phase'], $by['SINV']['phase'], $by['DN']['phase'], $by['TRD']['phase']]);

        self::assertSame(array_fill_keys(['ADJ', 'CNT', 'DN', 'GRN', 'PO', 'SINV', 'TRD', 'WO'], [0, 6]),
            array_map(static fn (array $r): array => [(int) $r['last_no'], (int) $r['pad']],
                array_column(self::$db->all('SELECT prefix, last_no, pad FROM number_series ORDER BY prefix'), null, 'prefix')));
        foreach (['document', 'document_line', 'document_posting', 'review_task', 'stored_file', 'document_file'] as $t) {
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM ' . $t), $t);
        }
    }

    public function testTheChecksHoldEvenForAdminSql(): void
    {
        $doc = static fn (array $over): int => self::$db->insert(
            'INSERT INTO document (doc_type, number, status, created_actor, posted_actor, posted_at, posted_hash, review_state, cancelled_at, submitted_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values(array_merge(['doc_type' => 'ADJ', 'number' => null, 'status' => 'draft', 'created_actor' => 'staff:1', 'posted_actor' => null,
                'posted_at' => null, 'posted_hash' => null, 'review_state' => null, 'cancelled_at' => null, 'submitted_at' => null], $over)),
        );
        $posted = ['number' => 'ADJ-000001', 'status' => 'posted', 'posted_actor' => 'staff:1', 'posted_at' => '2026-10-02 10:00:00', 'posted_hash' => str_repeat('a', 64),
            'review_state' => 'not_required'];
        $ok = $doc($posted);
        self::assertGreaterThan(0, $ok);
        self::assertGreaterThan(0, $doc(['status' => 'cancelled', 'cancelled_at' => '2026-10-02 10:00:00']));
        self::assertGreaterThan(0, $doc(['status' => 'awaiting_approval', 'submitted_at' => '2026-10-02 10:00:00']));
        foreach ([
            'a posted document without a number' => ['number' => null] + $posted,
            'a draft with a number' => ['number' => 'ADJ-000009'],
            'a posted document without its hash' => ['number' => 'ADJ-000002', 'posted_hash' => null] + $posted,
            'a posted document without a review state' => ['number' => 'ADJ-000003', 'review_state' => null] + $posted,
            'a draft with a review state' => ['review_state' => 'pending'],
            'cancelled without cancelled_at' => ['status' => 'cancelled'],
            'cancelled_at on a draft' => ['cancelled_at' => '2026-10-02 10:00:00'],
            'awaiting approval without submitted_at' => ['status' => 'awaiting_approval'],
            'an upper-case hash' => ['number' => 'ADJ-000004', 'posted_hash' => str_repeat('A', 64)] + $posted,
            'a short hash' => ['number' => 'ADJ-000005', 'posted_hash' => 'abc'] + $posted,
        ] as $what => $over) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $doc($over)), $what);
        }
        self::assertSame(1062, self::mysqlError(fn () => $doc(['number' => 'ADJ-000001'] + $posted)), 'numbers are unique');
        $rev = $doc(['number' => 'ADJ-000006'] + $posted);
        self::$db->exec('UPDATE document SET reverses_id = ? WHERE id = ?', [$ok, $rev]);
        $rev2 = $doc(['number' => 'ADJ-000007'] + $posted);
        self::assertSame(1062, self::mysqlError(fn () => self::$db->exec('UPDATE document SET reverses_id = ? WHERE id = ?', [$ok, $rev2])),
            'a document has one reversal');
        // ... one LIVE reversal: a cancelled reversal request does not hold the slot (live_reverses_id, I32).
        $cancelledRequest = $doc(['status' => 'cancelled', 'cancelled_at' => '2026-10-02 10:00:00']);
        self::$db->exec('UPDATE document SET reverses_id = ? WHERE id = ?', [$ok, $cancelledRequest]);
        self::assertNull(self::$db->value('SELECT live_reverses_id FROM document WHERE id = ?', [$cancelledRequest]));
        // The posting record (I33): one per document and number, a lower-case sha256.
        $record = static fn (int $id, string $number, string $hash): int => self::$db->exec(
            'INSERT INTO document_posting (document_id, number, posted_hash, posted_actor, posted_at, content) VALUES (?, ?, ?, ?, NOW(6), ?)',
            [$id, $number, $hash, 'staff:1', '{}']);
        self::assertSame(1, $record($ok, 'ADJ-000001', hash('sha256', '{}')));
        self::assertSame(1062, self::mysqlError(fn () => $record($ok, 'ADJ-000001', hash('sha256', '{}'))), 'written once');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $record($rev, 'ADJ-000006', strtoupper(hash('sha256', '{}')))));
        self::assertSame(1452, self::mysqlError(fn () => $record(999_999, 'ADJ-000999', hash('sha256', '{}'))), 'only for a document that exists');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec('INSERT INTO document_line (document_id, line_no, qty) VALUES (?, 0, 1)', [$ok])));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            'INSERT INTO document_line (document_id, line_no, qty, unit_cost) VALUES (?, 1, 1, -1)', [$ok])));
        self::$db->exec('INSERT INTO document_line (document_id, line_no, sku_id, qty) VALUES (?, 1, 999999, 1)', [$ok]); // no FK on sku_id (D15, I21)

        // review_task: open iff undecided, decided by someone, never by its opener; one open task per subject and kind.
        $task = static fn (array $over): int => self::$db->insert(
            'INSERT INTO review_task (subject_type, subject_id, kind, reason, state, opened_by, opened_actor, due_at, decided_by, decided_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values(array_merge(['subject_type' => 'document', 'subject_id' => $ok, 'kind' => 'review', 'reason' => 'all_documents', 'state' => 'open',
                'opened_by' => 5, 'opened_actor' => 'staff:5', 'due_at' => '2026-10-05 10:00:00', 'decided_by' => null, 'decided_at' => null], $over)),
        );
        $open = $task([]);
        self::assertSame(1062, self::mysqlError(fn () => $task([])), 'one open review per document');
        $task(['kind' => 'approval']);
        foreach ([
            'decided without a time' => ['state' => 'approved', 'decided_by' => 6],
            'open with a decision time' => ['decided_at' => '2026-10-03 10:00:00', 'kind' => 'approval', 'subject_id' => $ok + 1000],
            'approved by nobody' => ['state' => 'approved', 'decided_at' => '2026-10-03 10:00:00'],
            'approved by its opener' => ['state' => 'approved', 'decided_by' => 5, 'decided_at' => '2026-10-03 10:00:00'],
        ] as $what => $over) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $task($over)), $what);
        }
        self::$db->exec("UPDATE review_task SET state = 'approved', decided_by = 6, decided_at = '2026-10-03 10:00:00' WHERE id = ?", [$open]);
        self::assertGreaterThan(0, $task([]), 'a decided task frees the subject for a new open one');
        self::assertGreaterThan(0, $task(['state' => 'withdrawn', 'decided_at' => '2026-10-03 10:00:00']), 'a withdrawal has no decider');

        // stored_file: sha256 format, size > 0, kept at least 7 years from its creation.
        $file = static fn (array $over): int => self::$db->insert(
            'INSERT INTO stored_file (sha256, size_bytes, mime, original_name, kind, backend, storage_key, retain_until, stored_actor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values(array_merge(['sha256' => hash('sha256', 'x'), 'size_bytes' => 1, 'mime' => 'text/plain', 'original_name' => 'x.txt', 'kind' => 'other',
                'backend' => 'local', 'storage_key' => hash('sha256', 'x'), 'retain_until' => gmdate('Y-m-d', strtotime('+7 years')), 'stored_actor' => 'system:test'], $over)),
        );
        self::assertGreaterThan(0, $file([]));
        foreach ([
            'kept less than 7 years' => ['sha256' => hash('sha256', 'y'), 'retain_until' => gmdate('Y-m-d', strtotime('+7 years -2 days'))],
            'an upper-case sha256' => ['sha256' => strtoupper(hash('sha256', 'z'))],
            'a short sha256' => ['sha256' => 'abc'],
            'an empty file' => ['sha256' => hash('sha256', 'w'), 'size_bytes' => 0],
        ] as $what => $over) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $file($over)), $what);
        }
        self::assertSame(1062, self::mysqlError(fn () => $file([])), 'one row per content');
        // document_file: each attachment is kept at least 7 years from when it was made (I36).
        $fid = (int) self::$db->value('SELECT id FROM stored_file ORDER BY id LIMIT 1');
        $attach = static fn (string $role, string $until): int => self::$db->exec(
            "INSERT INTO document_file (document_id, file_id, role, attached_actor, retain_until) VALUES (?, ?, ?, 'system:test', ?)", [$ok, $fid, $role, $until]);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $attach('evidence', gmdate('Y-m-d', strtotime('+7 years -2 days')))));
        self::assertSame(1, $attach('evidence', gmdate('Y-m-d', strtotime('+7 years'))));

        // The format CHECKs are case-sensitive (REGEXP_LIKE 'c'; a plain REGEXP would follow the _ai_ci collation).
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO reason_code (code, label, applies_to) VALUES ('Damaged2', 'x', 'adjustment')")));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO document_type (code, prefix, name, phase) VALUES ('xx', 'XX', 'x', 'I-9')")));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO document_type (code, prefix, name, phase) VALUES ('XX', 'xx', 'x', 'I-9')")));
    }
}
