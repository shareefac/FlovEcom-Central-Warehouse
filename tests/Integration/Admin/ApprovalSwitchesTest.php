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
        $owner = $this->staffUser('reviewer');
        self::assertTrue(ApprovalRules::on(self::$db, 'approvals.supplier_activation'), 'on by default: the rule as it was');
        $this->set($owner, 'approvals.supplier_activation', 'false'); // switching a rule off needs a Reviewer (I1)
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

    /**
     * Review findings M1 and M2 (Y49): a supplier made usable by one person names the version of the switch read with its row locked;
     * the list marks it "approved alone" (and filters on it) until a reviewer who did not make it usable gives the OK afterwards; the
     * marks themselves stay as the evidence S2/S3 check.
     */
    public function testASupplierApprovedAloneIsMarkedUntilAReviewerChecksIt(): void
    {
        $buyer = $this->staffUser('buyer');
        $owner = $this->staffUser('reviewer');
        $this->set($owner, 'approvals.supplier_activation', 'false');
        $off = ConfigHistory::latest(self::$db, 'setting', 'approvals.supplier_activation');
        $s = $this->draft($buyer);
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        self::assertSame([1, $off['id'], null], [(int) $s['approved_alone'], (int) $s['alone_change_id'], $s['alone_checked_by']]);
        self::refused(403, 'role_not_allowed', fn () => $this->sup->checkAlone($buyer, (int) $s['id'], (int) $s['version'], null));
        self::refused(403, 'admin_cannot_review', fn () => $this->sup->checkAlone($this->staffUser('admin'), (int) $s['id'], (int) $s['version'], null));
        self::refused(409, 'version_conflict', fn () => $this->sup->checkAlone($owner, (int) $s['id'], (int) $s['version'] - 1, null));
        $checked = $this->sup->checkAlone($owner, (int) $s['id'], (int) $s['version'], 'details and proof look right');
        self::assertSame([1, $owner->staffUserId], [(int) $checked['approved_alone'], (int) $checked['alone_checked_by']], 'the evidence stays; the mark goes');
        self::assertNotNull($checked['alone_checked_at']);
        self::assertContains('supplier.alone_checked', $this->supplierAudits((int) $s['id']));
        self::refused(409, 'alone_checked', fn () => $this->sup->checkAlone($this->staffUser('reviewer'), (int) $s['id'], (int) $checked['version'], null));
        self::assertSame([], SupplierInvariants::check(self::$db));
        // A supplier approved by two people has nothing to check afterwards.
        $this->set($owner, 'approvals.supplier_activation', 'true');
        $t = $this->draft($buyer);
        $t = $this->sup->requestActivation($buyer, (int) $t['id'], (int) $t['version']);
        $t = $this->sup->approve($owner, $this->openTask((int) $t['id']), null);
        self::refused(409, 'not_alone', fn () => $this->sup->checkAlone($this->staffUser('reviewer'), (int) $t['id'], (int) $t['version'], null));
        // The person who made it usable alone never checks it themselves.
        $this->set($owner, 'approvals.supplier_activation', 'false');
        $lead = $this->staffUser(['buyer', 'reviewer']);
        $u = $this->draft($lead);
        $u = $this->sup->requestActivation($lead, (int) $u['id'], (int) $u['version']);
        self::refused(403, 'own_supplier', fn () => $this->sup->checkAlone($lead, (int) $u['id'], (int) $u['version'], null));
    }

    public function testAVersionOfTheSwitchThatWasNoLongerInForceIsFound(): void
    {
        $buyer = $this->staffUser('buyer');
        $admin = $this->staffUser('admin');
        $this->set($this->staffUser('reviewer'), 'approvals.supplier_activation', 'false');
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
        $this->set($this->staffUser('reviewer'), 'approvals.match_multiple', 'false');
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
        $this->set($this->staffUser('reviewer'), 'approvals.match_counted', 'false');
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
        $this->set($this->staffUser('reviewer'), 'approvals.company_own_change', 'false');
        $svc->save($owner, 2, ['delivery_address' => "Unit 9, Elsewhere\nBradford BD1 1AA"] + $details, 'moved warehouse');
        $c = $svc->confirm($owner, 3);
        self::assertSame(['confirmed', null, ['delivery_address']], [$c['result'], $c['review_task'], $c['watched_changed']], 'off: no second reviewer');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM review_task WHERE subject_type = 'company'"));

        // The spot check size (decision 2: 20) is the setting now: a smaller NEW sample is refused (a sample already drawn keeps the
        // size it was drawn with: KeySampleSizeTest, Y47).
        self::assertSame(20, KeySample::minSize(self::$db));
        $this->set($admin, 'approvals.spot_check_size', '30'); // bigger: stricter, the admin may
        self::assertSame(30, KeySample::minSize(self::$db));
        self::refused(400, 'bad_size', fn () => (new KeySample(self::$db))->create($this->staffUser('mapping_lead'), 'small', 20, false));
        self::refused(400, 'bad_value', fn () => $this->set($admin, 'approvals.spot_check_size', '4'));
        self::refused(403, 'loosen_needs_reviewer', fn () => $this->set($admin, 'approvals.spot_check_size', '25'), 'smaller: looser, a Reviewer only');
    }

    /**
     * Review finding I1 (Y45): the admin a rule restrains cannot lift it. Switching a rule off, a smaller spot check, fewer checks of a
     * kind of record, a higher limit, the OK first off: a Reviewer only (403 loosen_needs_reviewer, nothing saved, no version). Making
     * a rule stricter, or only marking it agreed: an admin too. The server's tools may do both. The audit row says `loosened`.
     */
    public function testLooseningAnApprovalRuleNeedsAReviewerTighteningDoesNot(): void
    {
        $admin = $this->staffUser('admin');
        $owner = $this->staffUser('reviewer');
        $both = $this->staffUser('admin');
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role, granted_by) VALUES (?, 'reviewer', NULL)", [$both->staffUserId]);
        $settings = new Settings(self::$db);
        $rules = new \CW\Admin\DocumentRules(self::$db);
        $saved = self::$db->all('SELECT * FROM document_type');
        try {
            foreach (array_keys(ApprovalRules::SWITCHES) as $key) {
                $was = ApprovalRules::on(self::$db, $key);
                if (!$was) {
                    $this->set($admin, $key, 'true'); // switching on is stricter: the admin may
                    self::assertTrue(ApprovalRules::on(self::$db, $key), $key);
                }
                $v = ConfigHistory::version(self::$db, 'setting', $key);
                self::refused(403, 'loosen_needs_reviewer', fn () => $this->set($admin, $key, 'false'), $key);
                self::refused(403, 'loosen_needs_reviewer', fn () => $this->set($both, $key, 'false'), "{$key}: Admin switches the Reviewer job off");
                self::assertTrue(ApprovalRules::on(self::$db, $key), "{$key}: nothing saved");
                self::assertSame($v, ConfigHistory::version(self::$db, 'setting', $key), "{$key}: no version");
                // Only marking it agreed is no loosening.
                $settings->change($admin, $key, 'true', 'the owner agreed it', true);
                $this->set($owner, $key, 'false');
                self::assertFalse(ApprovalRules::on(self::$db, $key), $key);
                $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'setting.change' AND entity_id = ? ORDER BY id DESC LIMIT 1",
                    [$key]), true);
                self::assertTrue($audit['loosened'] ?? false, "{$key}: the audit row says loosened");
                if ($was) {
                    $this->set($admin, $key, 'true');
                }
            }
            // A kind of record's rules: the OK first off, a higher limit, fewer checks need a Reviewer; a lower limit does not.
            self::refused(403, 'loosen_needs_reviewer', fn () => $rules->set($admin, 'PO', ['approval' => false], 'leave it'));
            self::refused(403, 'loosen_needs_reviewer', fn () => $rules->set($admin, 'PO', ['approval' => true, 'approval_limit_units' => '50000'], 'more'));
            self::refused(403, 'loosen_needs_reviewer', fn () => $rules->set($admin, 'PO', ['review_rule' => 'none'], 'fewer checks'));
            self::refused(403, 'loosen_needs_reviewer', fn () => $rules->set($admin, 'ADJ', ['reject_action' => 'record'], 'only record'));
            self::assertSame(5000, $rules->set($admin, 'PO', ['approval' => true, 'approval_limit_units' => '5000'], 'stricter')['after']['approval_limit_units']);
            self::assertSame(30, $rules->set($admin, 'PO', ['review_due_days' => '30'], 'more days to check')['after']['review_due_days'], 'days: either way');
            $r = $rules->set($owner, 'PO', ['approval' => false], 'leave it for now');
            self::assertSame('none', $r['after']['approval_rule']);
            self::assertTrue(json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'document_type.change' ORDER BY id DESC LIMIT 1"), true)['loosened']);
            // The server's tool (root) may.
            self::assertSame('none', $rules->set(Caller::system('document_rules'), 'ADJ', ['approval' => false], 'server')['after']['approval_rule']);
        } finally {
            foreach ($saved as $r) {
                self::$db->exec('UPDATE document_type SET review_rule = ?, review_limit_units = ?, review_due_days = ?, approval_rule = ?, approval_limit_units = ?, '
                    . 'reject_action = ? WHERE code = ?', [$r['review_rule'], $r['review_limit_units'], $r['review_due_days'], $r['approval_rule'],
                        $r['approval_limit_units'], $r['reject_action'], $r['code']]);
            }
        }
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
