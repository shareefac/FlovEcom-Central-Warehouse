<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\CwException;
use CW\Ops\ErpOpening;
use CW\Ops\ErpOpeningRules;
use CW\Schema\Grants;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;

/**
 * 0039 (pack I, the ERPNext opening import; docs/decisions.md PI1-PI16), on the migrated test schema: the eight settings with their
 * baselines, versions and rules, the run table with its checks, and the app login's rights on it. Nothing is backfilled.
 */
final class Migration0039Test extends IntegrationTestCase
{
    private const SETTINGS = [
        'erp_opening.warehouses' => ['text', '"Stores - VPG"', 'PI2'],
        'erp_opening.companies' => ['text', '"VAPE AND GO"', 'PI2'],
        'erp_opening.blocked_names' => ['text', '"VPG 2"', 'PI2'],
        'erp_opening.below_zero' => ['string', '"zero"', 'PI4'],
        'erp_opening.placeholder_costs' => ['string', '"0,1"', 'PI5'],
        'erp_opening.listing_store' => ['string', '"vapeandgo"', 'PI3'],
        'erp_opening.cw_warehouse' => ['string', '"MAIN"', 'PI1'],
        'erp_opening.require_pending_sales' => ['bool', 'true', 'PI6'],
    ];
    private const BAD_CHECK = 3819;

    public function testTheSettingsArriveWithTheirBaselinesAndTheRulesReadThem(): void
    {
        $db = self::$db;
        foreach (self::SETTINGS as $key => [$type, $json, $decision]) {
            $row = $db->one('SELECT value_type, CAST(value_json AS CHAR) j, provisional, decision FROM app_setting WHERE setting_key = ?', [$key]);
            self::assertNotNull($row, $key);
            self::assertSame([$type, $json, 1, $decision], [$row['value_type'], $row['j'], (int) $row['provisional'], $row['decision']], $key);
            self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = ? AND version = 1 "
                . "AND action = 'baseline' AND actor = 'system:migrate'", [$key]), "{$key}: its version 1");
        }
        self::assertSame(array_keys(self::SETTINGS), ErpOpeningRules::KEYS);
        self::assertSame([], ConfigInvariants::check($db));

        $rules = ErpOpeningRules::fromSettings(new Settings($db));
        self::assertSame(['warehouses' => ['Stores - VPG'], 'companies' => ['VAPE AND GO'], 'blocked_names' => ['VPG 2'], 'below_zero' => 'zero',
            'placeholder_costs' => ['0.000000', '1.000000'], 'listing_store' => 'vapeandgo', 'cw_warehouse' => 'MAIN', 'require_pending_sales' => true], $rules->toArray());

        $s = new Settings($db);
        foreach (['erp_opening.below_zero' => 'half', 'erp_opening.placeholder_costs' => '1,,2', 'erp_opening.listing_store' => 'Vape and Go',
            'erp_opening.cw_warehouse' => 'main'] as $key => $bad) {
            try {
                $s->checkRule($key, $bad);
                self::fail("{$key} = {$bad} must be refused");
            } catch (CwException $e) {
                self::assertSame('bad_value', $e->errorCode);
            }
        }
        foreach (['erp_opening.below_zero' => 'carry', 'erp_opening.placeholder_costs' => '0,1,0.01', 'erp_opening.listing_store' => 'electrofag',
            'erp_opening.cw_warehouse' => 'MAIN'] as $key => $good) {
            $s->checkRule($key, $good);
        }
    }

    public function testTheRunTableKeepsWhatARunWasAndTheAppLoginOnlyFinishesIt(): void
    {
        $db = self::$db;
        $sha = str_repeat('a', 64);
        $id = $db->insert("INSERT INTO erp_opening_run (doc_ref, as_of, file_name, file_sha256, status, actor) VALUES ('erp-opening:t', '2026-10-31 23:00:00', "
            . "'sb.csv', ?, 'running', 'system:opening_estimate')", [$sha]);
        self::assertNull(ErpOpening::lastRun($db), 'a run still going is not the last run');
        self::assertSame(self::BAD_CHECK, self::mysqlError(static fn () => $db->exec("UPDATE erp_opening_run SET status = 'done' WHERE id = ?", [$id])),
            'a finished run has its time');
        self::assertSame(self::BAD_CHECK, self::mysqlError(static fn () => $db->exec("INSERT INTO erp_opening_run (doc_ref, as_of, file_name, file_sha256, actor) "
            . "VALUES ('x', '2026-10-31 23:00:00', 'f', 'NOT-A-HASH', 'a')")));
        $db->exec("UPDATE erp_opening_run SET status = 'done', summary = JSON_OBJECT('booked', 3), finished_at = UTC_TIMESTAMP(6) WHERE id = ?", [$id]);
        $last = ErpOpening::lastRun($db);
        self::assertSame(['erp-opening:t', 'done', ['booked' => 3]], [$last['doc_ref'], $last['status'], $last['summary']]);

        self::assertSame(['Select', 'Insert'], Grants::desired('erp_opening_run'));
        self::assertSame(['status' => ['Update'], 'summary' => ['Update'], 'finished_at' => ['Update']], Grants::desiredColumns('erp_opening_run'));
    }
}
