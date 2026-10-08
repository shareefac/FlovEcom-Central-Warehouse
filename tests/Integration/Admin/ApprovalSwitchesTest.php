<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Admin;

use CW\Admin\ApprovalRules;
use CW\Admin\ConfigHistory;
use CW\Caller;
use CW\Company\CompanyDetails;
use CW\Mapping\KeySample;
use CW\Settings;
use CW\Suppliers\SupplierInvariants;
use CW\Tests\Integration\Suppliers\SupplierTestCase;

/**
 * The approval switches of the Approval rules page (0019; docs/decisions.md Y9-Y11, the owner's Q8 answer): each two-person rule
 * the owner can switch off works with one person while it is off and with two again once it is on, and the nightly checks stay
 * consistent with whatever was switched: a supplier activated alone names the version of the switch that said off (S2, S3), and
 * a made-up "alone" is found. Every test restores the settings it changed (app_setting is a seed table) and ends with the full
 * invariant check (StockTestCase).
 */
final class ApprovalSwitchesTest extends SupplierTestCase
{
    /** @var list<array<string, mixed>> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
                [$r['v'], (int) $r['provisional'], $r['updated_actor'], $r['setting_key']]);
        }
        parent::tearDown();
    }

    private function set(Caller $by, string $key, string $value): void
    {
        (new Settings(self::$db))->change($by, $key, $value, 'testing the switch ' . $key);
    }

    public function testASupplierIsSwitchedOnAloneWhileTheRuleIsOffAndTheChecksAgree(): void
    {
        $buyer = $this->staffUser('buyer');
        $admin = $this->staffUser('admin');
        self::assertTrue(ApprovalRules::on(self::$db, 'approvals.supplier_activation'), 'on by default: the rule as it was');
        $this->set($admin, 'approvals.supplier_activation', 'false');
        self::assertFalse(ApprovalRules::on(self::$db, 'approvals.supplier_activation'));
        $off = ConfigHistory::latest(self::$db, 'setting', 'approvals.supplier_activation');
        self::assertSame(['value' => 'false', 'provisional' => 1], $off['state']);

        $s = $this->draft($buyer, ['country' => 'NL', 'is_overseas' => '1', 'import_route' => 'Stamped at our Kent agent before dispatch']);
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        self::assertSame(['active', 1, 1, $buyer->staffUserId, $buyer->staffUserId, $off['id']],
            [$s['status'], (int) $s['approved_alone'], (int) $s['route_alone'], (int) $s['approved_by'], (int) $s['import_route_approved_by'], (int) $s['alone_change_id']]);
        self::assertSame(0, $this->openTask((int) $s['id']), 'no task: nobody else is asked');
        self::assertContains('supplier.activate_alone', $this->supplierAudits((int) $s['id']));
        self::assertSame([], SupplierInvariants::check(self::$db));

        // A new import route while the rule is off: approved by the buyer too, with the same version of the switch.
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['import_route' => 'Stamped at our Essex agent']);
        self::assertSame([1, $buyer->staffUserId, $off['id']], [(int) $s['route_alone'], (int) $s['import_route_approved_by'], (int) $s['alone_change_id']]);
        self::assertSame(0, $this->openTask((int) $s['id']));
        self::assertSame([], SupplierInvariants::check(self::$db));

        // A made-up "alone": the version of the switch said on.
        $baseline = (int) self::$db->value("SELECT id FROM config_change WHERE subject_type = 'setting' AND subject_key = 'approvals.supplier_activation' AND version = 1");
        self::$db->exec('UPDATE supplier SET alone_change_id = ? WHERE id = ?', [$baseline, $s['id']]);
        $v = SupplierInvariants::check(self::$db);
        self::assertCount(2, $v, implode("\n", $v));
        self::assertStringContainsString('was activated by one person: config version ' . $baseline . ' of approvals.supplier_activation says it was on', $v[0]);
        self::$db->exec('UPDATE supplier SET alone_change_id = ? WHERE id = ?', [$off['id'], $s['id']]);
        self::$db->exec('UPDATE supplier SET approved_alone = 0 WHERE id = ?', [$s['id']]);
        self::assertStringContainsString('has no decided activation task', implode("\n", SupplierInvariants::check(self::$db)), 'alone, but not marked so: S2 as before');
        self::$db->exec('UPDATE supplier SET approved_alone = 1 WHERE id = ?', [$s['id']]);

        // The rule on again: switching the supplier off and on needs a reviewer again, who clears the "alone" marks.
        $this->set($admin, 'approvals.supplier_activation', 'true');
        $s = $this->sup->deactivate($buyer, (int) $s['id'], (int) $s['version'], 'test over');
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        self::assertSame('pending_approval', $s['status']);
        $s = $this->sup->approve($this->staffUser('reviewer'), $this->openTask((int) $s['id']), null);
        self::assertSame(['active', 0, 0, null], [$s['status'], (int) $s['approved_alone'], (int) $s['route_alone'], $s['alone_change_id']]);
        self::assertSame([], SupplierInvariants::check(self::$db));
    }

    public function testAVersionOfTheSwitchThatWasNoLongerInForceIsFound(): void
    {
        $buyer = $this->staffUser('buyer');
        $admin = $this->staffUser('admin');
        $this->set($admin, 'approvals.supplier_activation', 'false');
        $s = $this->draft($buyer);
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        self::assertSame([], SupplierInvariants::check(self::$db));
        $this->set($admin, 'approvals.supplier_activation', 'true');
        // Activated "an hour after" the rule went back on, naming the off version: the switch was no longer off then.
        self::$db->exec('UPDATE supplier SET approved_at = NOW(6) + INTERVAL 1 HOUR WHERE id = ?', [$s['id']]);
        self::assertStringContainsString('the switch had changed again before the approval', implode("\n", SupplierInvariants::check(self::$db)));
        self::$db->exec('UPDATE supplier SET approved_at = NOW(6) WHERE id = ?', [$s['id']]);
    }

    public function testMatchesWhere1SaleIsNot1ProductAndJoiningCountedProductsFollowTheirSwitches(): void
    {
        $alt = $this->site('alt', 'live');
        [$lead, $admin] = [$this->staffUser('mapping_lead'), $this->staffUser('admin')];
        $sku = $this->item('legacy', 50);
        $e = $this->listing($alt, 'P10', null);
        $r = $this->decide($lead, 'link', $e, ['sku_id' => $sku, 'units_per_item' => 10]);
        self::assertSame(['pending_second', ['units_per_item']], [$r['state'], $r['needs_second']], 'on: a second matching lead');
        $this->ds->withdraw($lead, $r['decision_id']);
        $this->set($admin, 'approvals.match_multiple', 'false');
        $r = $this->decide($lead, 'link', $e, ['sku_id' => $sku, 'units_per_item' => 10]);
        self::assertSame(['applied', []], [$r['state'], $r['needs_second']], 'off: one person');
        self::assertSame(10, $this->link($e)['units_per_item']);

        // Joining a counted product: two people while the rule is on, one while it is off (the recount is still queued).
        $keep = $this->item('legacy', 5);
        $from = $this->item('legacy', 3);
        $l = $this->listing($alt, 'F1', $from);
        $this->book('count', $keep, 5, 'MAIN', '2026-09-26T10:00:00Z');
        self::assertSame([$keep], $this->ds->counted([$keep, $from]));
        $m = $this->decide($lead, 'merge_skus', $l, ['sku_id' => $keep, 'merge_from_sku_id' => $from]);
        self::assertSame(['pending_second', ['counted_item']], [$m['state'], $m['needs_second']]);
        $this->ds->withdraw($lead, $m['decision_id']);
        $this->set($admin, 'approvals.match_counted', 'false');
        $m = $this->decide($lead, 'merge_skus', $l, ['sku_id' => $keep, 'merge_from_sku_id' => $from]);
        self::assertSame(['applied', []], [$m['state'], $m['needs_second']]);
        $this->assertBal(8, 0, 0, $keep);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'merge_recount' AND sku_id = ?", [$keep]),
            'counted stock plus an estimate is still counted again');
    }

    public function testTheCompanyOwnChangeCheckAndTheSpotCheckSize(): void
    {
        $owner = $this->staffUser('reviewer');
        $admin = $this->staffUser('admin');
        $svc = new CompanyDetails(self::$db);
        $details = ['legal_name' => 'Example Vapes Ltd', 'trading_name' => 'Vape and Go', 'company_number' => '01234567', 'address' => "1 High Street\nLeeds\nLS1 1AA",
            'vat_registered' => 'yes', 'vat_number' => 'GB 123 4567 82', 'phone' => '0113 496 0000', 'email' => 'buying@example.co.uk',
            'delivery_address' => "Unit 4, Example Park\nLeeds LS2 2BB"];
        $svc->save($owner, 0, $details);
        $svc->confirm($owner, 1);
        $this->set($admin, 'approvals.company_own_change', 'false');
        $svc->save($owner, 2, ['delivery_address' => "Unit 9, Elsewhere\nBradford BD1 1AA"] + $details, 'moved warehouse');
        $c = $svc->confirm($owner, 3);
        self::assertSame(['confirmed', null, ['delivery_address']], [$c['result'], $c['review_task'], $c['watched_changed']], 'off: no second reviewer');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM review_task WHERE subject_type = 'company'"));

        // The spot check size (decision 2: 20) is the setting now: a smaller sample is refused, and a bigger rule makes old ones unfit.
        self::assertSame(20, KeySample::minSize(self::$db));
        $this->set($admin, 'approvals.spot_check_size', '30');
        self::assertSame(30, KeySample::minSize(self::$db));
        self::refused(400, 'bad_size', fn () => (new KeySample(self::$db))->create($this->staffUser('mapping_lead'), 'small', 20, false));
        self::refused(400, 'bad_value', fn () => $this->set($admin, 'approvals.spot_check_size', '4'));
    }

    public function testASwitchMissingFromAnOlderSchemaReadsAsItsDefault(): void
    {
        self::$db->exec("UPDATE app_setting SET value_type = 'int', value_json = CAST('1' AS JSON) WHERE setting_key = 'approvals.staff_grant'");
        self::assertFalse(ApprovalRules::on(self::$db, 'approvals.staff_grant'), 'not a bool any more: the default (off)');
        self::$db->exec("UPDATE app_setting SET value_type = 'bool', value_json = CAST('false' AS JSON) WHERE setting_key = 'approvals.staff_grant'");
        $this->expectException(\InvalidArgumentException::class);
        ApprovalRules::on(self::$db, 'approvals.nothing');
    }
}
