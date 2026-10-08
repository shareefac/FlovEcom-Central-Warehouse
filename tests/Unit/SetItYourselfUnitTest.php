<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Admin\AuditSearch;
use CW\Admin\ConfigHistory;
use CW\Admin\DocumentRules;
use CW\Admin\Sites;
use CW\CwException;
use CW\Output\QrCode;
use CW\Ui\ConfigWords;
use CW\Ui\Controller\ApprovalsController;
use CW\Ui\Controller\SettingsController;
use CW\Ui\Controller\SystemController;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of the set-it-yourself pack (0019; docs/decisions.md Y1-Y40), without a database: the reason a change needs and the
 * tracked state of a version, the document rules' arithmetic, the QR code, the websites' commands, the audit log's filters, the
 * history and the hints in words.
 */
final class SetItYourselfUnitTest extends TestCase
{
    public function testAChangeNeedsAReasonAndAVersionKeepsTheTrackedFieldsTyped(): void
    {
        self::assertSame('owner asked', ConfigHistory::reason('  owner asked '));
        foreach (['', 'ab', str_repeat('x', 501), "bad\x01reason", "\xff\xfe"] as $bad) {
            try {
                ConfigHistory::reason($bad);
                self::fail('accepted ' . json_encode(mb_check_encoding($bad, 'UTF-8') ? $bad : bin2hex($bad)));
            } catch (CwException $e) {
                self::assertSame(['bad_reason', 400, 'reason'], [$e->errorCode, $e->httpStatus, $e->detail['field']]);
            }
        }
        self::assertSame(['value' => 'true', 'provisional' => 1], ConfigHistory::normalise('setting', ['value' => 'true', 'provisional' => '1', 'extra' => 'x']));
        self::assertSame(['review_rule' => 'all', 'review_limit_units' => null, 'review_due_days' => 7, 'approval_rule' => 'over_value', 'approval_limit_units' => 10000,
            'reject_action' => 'record'], ConfigHistory::normalise('document_rule', ['review_rule' => 'all', 'review_limit_units' => null, 'review_due_days' => '7',
                'approval_rule' => 'over_value', 'approval_limit_units' => '10000', 'reject_action' => 'record']));
        self::assertSame('MAIN/OVERFLOW', ConfigHistory::locationKey('MAIN', 'OVERFLOW'));
        foreach (ConfigHistory::TYPES as $type) {
            self::assertArrayHasKey($type, ConfigHistory::TRACKED);
        }
        $this->expectException(\InvalidArgumentException::class);
        ConfigHistory::normalise('nothing', []);
    }

    public function testTheDocumentRulesAreTheTablesChecksAndTheApprovalSwitch(): void
    {
        $po = ['review_rule' => 'all', 'review_limit_units' => null, 'review_due_days' => 7, 'approval_rule' => 'over_value', 'approval_limit_units' => 10000,
            'reject_action' => 'record'];
        self::assertSame(array_replace($po, ['approval_rule' => 'none']), DocumentRules::apply('PO', $po, ['approval' => false]), 'switched off: the limit is kept');
        $off = array_replace($po, ['approval_rule' => 'none']);
        self::assertSame($po, DocumentRules::apply('PO', $off, ['approval' => true]), 'switched on again: the type\'s own kind');
        self::assertSame(25000, DocumentRules::apply('PO', $po, ['approval_limit_units' => '25000'])['approval_limit_units']);
        self::assertSame(array_replace($po, ['review_rule' => 'over_limit', 'review_limit_units' => 25]),
            DocumentRules::apply('PO', $po, ['review_rule' => 'over_limit', 'review_limit_units' => 25]));
        self::assertSame(14, DocumentRules::apply('PO', $po, ['review_due_days' => '14'])['review_due_days']);
        // Not OK on a purchase order only records it (M7): an order with deliveries cannot be cancelled.
        try {
            DocumentRules::apply('PO', $po, ['reject_action' => 'reverse']);
            self::fail('a PO\'s Not OK is record only');
        } catch (\CW\CwException $e) {
            self::assertSame(['reject_record_only', 400], [$e->errorCode, $e->httpStatus]);
        }
        $adj = ['review_rule' => 'all', 'review_limit_units' => null, 'review_due_days' => 3, 'approval_rule' => 'positive_without_supplier_doc',
            'approval_limit_units' => 10, 'reject_action' => 'reverse'];
        self::assertSame('none', DocumentRules::apply('ADJ', $adj, ['approval' => false])['approval_rule']);
        self::assertSame('positive_without_supplier_doc', DocumentRules::apply('ADJ', ['approval_rule' => 'none'] + $adj, ['approval' => true])['approval_rule']);
        $grn = ['review_rule' => 'all', 'review_limit_units' => null, 'review_due_days' => 3, 'approval_rule' => 'none', 'approval_limit_units' => null,
            'reject_action' => 'reverse'];
        foreach ([
            ['GRN', $grn, ['approval_limit_units' => 5], 'no_approval_rule'],
            ['GRN', $grn, ['approval' => true], 'no_approval_rule'],
            ['PO', $po, ['review_limit_units' => 5], 'bad_rule'],
            ['PO', $po, ['review_rule' => 'over_limit'], 'limit_required'],
            ['PO', $po, ['review_rule' => 'sometimes'], 'bad_rule'],
            ['PO', $po, ['approval_limit_units' => -1], 'bad_limit'],
            ['PO', $po, ['approval_limit_units' => '12.5'], 'bad_limit'],
            ['PO', $po, ['approval_limit_units' => 2_000_000_001], 'bad_limit'],
            ['PO', $po, ['review_due_days' => 0], 'bad_days'],
            ['PO', $po, ['review_due_days' => '121'], 'bad_days'],
            ['PO', $po, ['reject_action' => 'shrug'], 'bad_reject_action'],
            ['ADJ', ['approval_limit_units' => null, 'approval_rule' => 'none'] + $adj, ['approval' => true], 'limit_required'],
        ] as [$type, $before, $change, $code]) {
            try {
                DocumentRules::apply($type, $before, $change);
                self::fail("{$type} " . json_encode($change) . ' was accepted');
            } catch (CwException $e) {
                self::assertSame([$code, 400], [$e->errorCode, $e->httpStatus], json_encode($change));
            }
        }
    }

    /**
     * Review finding I1 (Y45): which changes make an approval rule LOOSER (they need a Reviewer). A switch off, a smaller spot check, a
     * weaker review, a higher limit, the OK first off or a higher limit for it, Not OK that only records. Tightening, the days to
     * decide and a rule that is not one: never.
     */
    public function testWhatMakesAnApprovalRuleLooser(): void
    {
        foreach (array_keys(\CW\Admin\ApprovalRules::SWITCHES) as $key) {
            self::assertTrue(\CW\Admin\ApprovalRules::loosens($key, true, false), "{$key}: on -> off");
            self::assertFalse(\CW\Admin\ApprovalRules::loosens($key, false, true), "{$key}: off -> on");
            self::assertFalse(\CW\Admin\ApprovalRules::loosens($key, true, true), "{$key}: unchanged");
        }
        self::assertTrue(\CW\Admin\ApprovalRules::loosens('approvals.spot_check_size', 20, 10));
        self::assertFalse(\CW\Admin\ApprovalRules::loosens('approvals.spot_check_size', 20, 30));
        self::assertFalse(\CW\Admin\ApprovalRules::loosens('suppliers.approval_due_days', 3, 30), 'days to decide restrain nobody');
        self::assertFalse(\CW\Admin\ApprovalRules::loosens('po.default_vat_code', 'S', 'Z'), 'not an approval rule');
        self::assertTrue(\CW\Admin\ApprovalRules::isRule('approvals.staff_reset'));

        $po = ['review_rule' => 'all', 'review_limit_units' => null, 'review_due_days' => 7, 'approval_rule' => 'over_value', 'approval_limit_units' => 10000,
            'reject_action' => 'record'];
        $looser = static fn (array $change): bool => DocumentRules::loosens($po, DocumentRules::apply('PO', $po, $change));
        self::assertTrue($looser(['review_rule' => 'over_limit', 'review_limit_units' => 5]), 'every one -> over a limit');
        self::assertTrue($looser(['review_rule' => 'none']));
        self::assertTrue($looser(['approval' => false]), 'the OK first off');
        self::assertTrue($looser(['approval_limit_units' => '20000']), 'a higher limit for the OK first');
        self::assertFalse($looser(['approval_limit_units' => '5000']), 'a lower limit is stricter');
        self::assertFalse($looser(['review_due_days' => '30']), 'the days to check restrain nobody');
        $over = DocumentRules::apply('PO', $po, ['review_rule' => 'over_limit', 'review_limit_units' => 5]);
        self::assertTrue(DocumentRules::loosens($over, DocumentRules::apply('PO', $over, ['review_limit_units' => 50])), 'over a higher limit');
        self::assertFalse(DocumentRules::loosens($over, DocumentRules::apply('PO', $over, ['review_rule' => 'all'])), 'back to every one: stricter');
        $adj = ['review_rule' => 'all', 'review_limit_units' => null, 'review_due_days' => 3, 'approval_rule' => 'positive_without_supplier_doc',
            'approval_limit_units' => 10, 'reject_action' => 'reverse'];
        self::assertTrue(DocumentRules::loosens($adj, DocumentRules::apply('ADJ', $adj, ['reject_action' => 'record'])), 'Not OK only records: looser');
        self::assertFalse(DocumentRules::loosens(['reject_action' => 'record'] + $adj, $adj), 'Not OK cancels again: stricter');
    }

    /** Review finding I5 (Y42): the one-time set-up code: at least 60 bits, easy to type, read kindly, stored as a hash. */
    public function testTheSetUpCodeIsLongAndReadKindly(): void
    {
        $code = \CW\Staff\SetupCode::new();
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/D', $code);
        self::assertGreaterThanOrEqual(60, \CW\Staff\SetupCode::LENGTH * log(strlen(\CW\Staff\SetupCode::ALPHABET), 2), 'at least 60 bits');
        self::assertNotSame($code, \CW\Staff\SetupCode::new());
        $hash = \CW\Staff\SetupCode::hash($code);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $hash);
        self::assertSame($hash, \CW\Staff\SetupCode::hash(' ' . strtolower(str_replace('-', ' ', $code)) . ' '), 'case, spaces and dashes do not matter');
        self::assertSame(\CW\Staff\SetupCode::hash('10000-00000-0000A'), \CW\Staff\SetupCode::hash('lOooo-ooooo-oooOa'), 'O, I and L read as 0, 1 and 1');
        foreach (['', 'ABCDE-FGHJK', 'ABCDE-FGHJK-MNPQRS', 'ABCDE-FGHJK-MNPQU', str_repeat('A', 100)] as $bad) {
            self::assertNull(\CW\Staff\SetupCode::hash($bad), $bad);
        }
    }

    /** The QR code: the size of a QR version plus the quiet zone, square, and the three finder patterns where a reader looks. */
    public function testTheQrCodeIsASquareGridWithItsFinderPatterns(): void
    {
        $uri = 'otpauth://totp/CW%20Warehouse:fazil%40example.com?secret=' . str_repeat('ABCDEFGH', 4) . '&issuer=CW%20Warehouse&algorithm=SHA1&digits=6&period=30';
        $m = QrCode::matrix($uri);
        $n = count($m);
        self::assertSame(0, ($n - 2 * QrCode::QUIET - 21) % 4, 'a QR version is 21 + 4k modules wide');
        self::assertGreaterThan(21 + 2 * QrCode::QUIET, $n);
        foreach ($m as $row) {
            self::assertCount($n, $row);
        }
        $q = QrCode::QUIET;
        $inner = $n - 2 * $q;
        foreach ([[0, 0], [$inner - 7, 0], [0, $inner - 7]] as [$x0, $y0]) {
            for ($y = 0; $y < 7; $y++) {
                for ($x = 0; $x < 7; $x++) {
                    $ring = $x === 0 || $x === 6 || $y === 0 || $y === 6;
                    $core = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
                    self::assertSame($ring || $core, $m[$q + $y0 + $y][$q + $x0 + $x], "finder at {$x0},{$y0}: module {$x},{$y}");
                }
            }
        }
        foreach ([$m[0], $m[$n - 1], array_column($m, 0), array_column($m, $n - 1)] as $edge) {
            self::assertNotContains(true, $edge, 'the quiet zone is light');
        }
        self::assertNotSame($m, QrCode::matrix($uri . 'x'), 'another text, another code');
        foreach (['', str_repeat('a', QrCode::MAX_TEXT + 1), "caf\u{e9}"] as $bad) {
            try {
                QrCode::matrix($bad);
                self::fail('drew ' . strlen($bad) . ' characters');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheWebsitesCommandsAreTheServersExactOnes(): void
    {
        $c = array_column(Sites::commands(['code' => 'vapeandgo', 'mode' => 'shadow', 'writer' => false]), 'command', 'what');
        self::assertSame('php bin/channel_set.php --code=vapeandgo --mode=live --actor="<your name>"   # dry run; add --apply to change it', $c['mode']);
        self::assertSame('php bin/channel_set.php --code=vapeandgo --writer=on --actor="<your name>" --apply', $c['writer']);
        self::assertStringContainsString('--warehouse=<WAREHOUSE CODE>', $c['warehouse']);
        self::assertSame('php bin/rotate_key.php --code=vapeandgo', $c['key']);
        self::assertStringContainsString('--mode=shadow', array_column(Sites::commands(['code' => 'x', 'mode' => 'off', 'writer' => true]), 'command', 'what')['mode']);
        self::assertStringContainsString('--writer=off', array_column(Sites::commands(['code' => 'x', 'mode' => 'live', 'writer' => true]), 'command', 'what')['writer']);
    }

    public function testTheAuditLogsFilters(): void
    {
        $f = AuditSearch::filters([], '2026-10-08');
        self::assertSame(['from' => '2026-10-02', 'to' => '2026-10-08', 'who' => null, 'record' => null, 'id' => null, 'action' => null, 'before' => null], $f,
            'the last 7 days by default');
        $f = AuditSearch::filters(['from' => '2026-09-01', 'to' => '2026-09-30', 'who' => 'staff:12', 'record' => 'supplier', 'id' => '5', 'action' => 'supplier.approve',
            'before' => '991'], '2026-10-08');
        self::assertSame(['2026-09-01', '2026-09-30', 'staff:12', 'supplier', '5', 'supplier.approve', 991],
            [$f['from'], $f['to'], $f['who'], $f['record'], $f['id'], $f['action'], $f['before']]);
        foreach ([['from' => '2026-02-31'], ['to' => 'yesterday'], ['from' => '2026-10-09', 'to' => '2026-10-08'], ['from' => '2024-01-01', 'to' => '2026-01-01'],
            ['who' => 'staff:x'], ['who' => 'admin'], ['record' => 'Supplier;'], ['id' => '5'], ['action' => 'DROP TABLE'], ['before' => '-1']] as $q) {
            try {
                AuditSearch::filters($q, '2026-10-08');
                self::fail('accepted ' . json_encode($q));
            } catch (CwException $e) {
                self::assertSame(['bad_filter', 400], [$e->errorCode, $e->httpStatus], json_encode($q));
            }
        }
        $d = SystemController::described(['action' => 'setting.change', 'person' => 'Fazil', 'site' => null, 'entity_type' => 'app_setting']);
        self::assertSame(['Fazil', Words::AUDIT_ACTION['setting.change'], Words::AUDIT_RECORD['app_setting']], [$d['who'], $d['what'], $d['record_words']]);
        $d = SystemController::described(['action' => 'po.send', 'person' => null, 'site' => null, 'entity_type' => 'document']);
        self::assertSame([Words::AUDIT['cw'], Words::AUDIT_FAMILY['po']], [$d['who'], $d['what']], 'an action without words: its kind');
        self::assertSame('Vape and Go', SystemController::described(['action' => 'reservation.reserve', 'person' => null, 'site' => 'Vape and Go',
            'entity_type' => null])['who']);
        self::assertSame(['45 seconds', '3 minutes', '5 hours', '4 days'], array_map([SystemController::class, 'duration'], [45, 200, 18000, 350000]));
    }

    public function testTheHistoryAndTheHintsInWords(): void
    {
        self::assertSame(Words::CONFIG['yes'], ConfigWords::settingValue('bool', 'true'));
        self::assertSame(Words::CONFIG['not_set'], ConfigWords::settingValue('int', '""'));
        self::assertSame('0.50', ConfigWords::settingValue('decimal', '"0.50"'));
        self::assertSame(Words::SETTING_EDIT['now'] . ': 5 → 7', ConfigWords::changes('setting', ['value' => '5', 'provisional' => 1], ['value' => '7', 'provisional' => 1], 'int'));
        self::assertSame(Words::SETTING_EDIT['status'] . ': ' . Words::CONFIG['no'] . ' → ' . Words::CONFIG['yes'],
            ConfigWords::changes('setting', ['value' => '5', 'provisional' => 1], ['value' => '5', 'provisional' => 0], 'int'), 'agreed');
        self::assertSame(Words::APPROVALS['ok_first'] . ': ' . Words::CONFIG['on'] . ' → ' . Words::CONFIG['off'],
            ConfigWords::changes('document_rule', ['approval_rule' => 'over_value', 'approval_limit_units' => 10000], ['approval_rule' => 'none', 'approval_limit_units' => 10000]));
        self::assertSame(Words::CONFIG['set_up'], ConfigWords::who('system:migrate', null));
        self::assertSame(Words::CONFIG['server'], ConfigWords::who('system:settings', null));
        self::assertSame('Sam', ConfigWords::who('staff:3', 'Sam'));
        self::assertSame(Words::SETTING_EDIT['type_int'] . ' ' . sprintf(Words::SETTING_EDIT['range'], '1', '120'), SettingsController::hint('suppliers.approval_due_days', 'int'));
        self::assertSame(Words::SETTING_EDIT['type_int'] . ' ' . sprintf(Words::SETTING_EDIT['range'], '0', '120'), SettingsController::hint('reorder.default_lead_days', 'int'),
            'a day count: 0 to 120');
        self::assertStringContainsString(Words::SETTING_EDIT['vat'], SettingsController::hint('po.default_vat_code', 'string'));
        self::assertSame('rule-approvals-staff-grant', ApprovalsController::anchor('approvals.staff_grant'));
        self::assertSame('rule-PO', ApprovalsController::anchor('PO'));
    }
}
