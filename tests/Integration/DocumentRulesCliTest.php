<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * bin/document_rules.php (spec §2.3, I57): how owner decision 11 changes the PO approval limit or a review rule. Only with
 * the admin login (without --admin: exit 1, nothing connected); the table's CHECKs as usage errors (exit 2); a change
 * audited document_type.change {type, before, after, reason}; the same values again: "unchanged". document_type is a seed
 * table (TestDb::clean keeps it): every test restores the rows it changed.
 */
final class DocumentRulesCliTest extends IntegrationTestCase
{
    /** @var list<array<string, mixed>> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = self::$db->all('SELECT * FROM document_type');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $r) {
            self::$db->exec('UPDATE document_type SET review_rule = ?, review_limit_units = ?, review_due_days = ?, approval_rule = ?, approval_limit_units = ?, '
                . 'reject_action = ? WHERE code = ?', [$r['review_rule'], $r['review_limit_units'], $r['review_due_days'], $r['approval_rule'],
                    $r['approval_limit_units'], $r['reject_action'], $r['code']]);
        }
    }

    /** @return array{code: int, out: string, err: string} */
    private static function cli(string ...$args): array
    {
        $root = dirname(__DIR__, 2);
        $p = proc_open([PHP_BINARY, "{$root}/bin/document_rules.php", '--db=' . TestDb::name(), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    /** @return array<string, mixed> */
    private static function po(): array
    {
        return (array) self::$db->one("SELECT review_rule, review_limit_units, review_due_days, approval_rule, approval_limit_units, reject_action FROM document_type WHERE code = 'PO'");
    }

    public function testTheOwnerChangesThePoLimit(): void
    {
        $r = self::cli('--type=PO', '--approval-limit=25000', '--reason=owner decision 11: GBP 25k');
        self::assertSame(1, $r['code'], 'without --admin: refused before connecting');
        self::assertStringContainsString('needs --admin', $r['err']);
        self::assertSame(10000, self::po()['approval_limit_units']);

        $r = self::cli('--admin', '--type=PO', '--approval-limit=25000', '--review-due-days=14', '--reason=owner decision 11: GBP 25k');
        self::assertSame(0, $r['code'], $r['err'] . $r['out']);
        self::assertStringContainsString('PO changed: review_due_days 7 -> 14, approval_limit_units 10000 -> 25000', $r['out']);
        self::assertSame(['all', null, 14, 'over_value', 25000, 'record'], array_values(self::po()));
        $audit = self::$db->one("SELECT actor, entity_type, entity_id, detail FROM audit_log WHERE action = 'document_type.change'");
        self::assertSame(['system:document_rules', 'document_type', 'PO'], [$audit['actor'], $audit['entity_type'], $audit['entity_id']]);
        $detail = json_decode((string) $audit['detail'], true);
        self::assertSame(['PO', 10000, 25000, 'owner decision 11: GBP 25k'], [$detail['type'], $detail['before']['approval_limit_units'],
            $detail['after']['approval_limit_units'], $detail['reason']]);

        $r = self::cli('--admin', '--type=PO', '--approval-limit=25000', '--reason=again');
        self::assertSame([0, 1], [$r['code'], (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'document_type.change'")]);
        self::assertStringContainsString('PO unchanged', $r['out']);

        // A review rule: over_limit needs its limit; a limit on another rule is refused.
        $r = self::cli('--admin', '--type=WO', '--review-rule=all', '--reason=review every write-off');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame('all', self::$db->value("SELECT review_rule FROM document_type WHERE code = 'WO'"));
        $r = self::cli('--admin', '--type=WO', '--review-rule=over_limit', '--review-limit=25', '--reason=back to a limit');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame(['over_limit', 25], array_values((array) self::$db->one("SELECT review_rule, review_limit_units FROM document_type WHERE code = 'WO'")));
    }

    public function testTheRulesTheChecksHold(): void
    {
        foreach ([
            ['--type=GRN', '--approval-limit=5', '--reason=GRN has no approval rule'],
            ['--type=PO', '--review-limit=5', '--reason=PO reviews all'],
            ['--type=PO', '--review-rule=over_limit', '--reason=no limit given'],
            ['--type=PO', '--review-rule=sometimes', '--reason=bad rule'],
            ['--type=PO', '--approval-limit=-1', '--reason=negative'],
            ['--type=PO', '--approval-limit=12.5', '--reason=decimal'],
            ['--type=PO', '--review-due-days=0', '--reason=zero days'],
            ['--type=PO', '--review-due-days=121', '--reason=too many days'],
            ['--type=PO', '--approval-limit=5'],
            ['--type=PO', '--approval-limit=5', '--reason=no'],
            ['--type=XX', '--approval-limit=5', '--reason=unknown type'],
            ['--type=PO', '--reason=nothing to change'],
            ['--approval-limit=5', '--reason=no type'],
        ] as $args) {
            $r = self::cli('--admin', ...$args);
            self::assertSame(2, $r['code'], implode(' ', $args) . ': ' . $r['err'] . $r['out']);
        }
        self::assertSame(['all', null, 7, 'over_value', 10000, 'record'], array_values(self::po()));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'document_type.change'"));
    }
}
