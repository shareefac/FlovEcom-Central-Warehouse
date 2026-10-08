<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Admin;

use CW\Admin\ConfigHistory;
use CW\Admin\ConfigInvariants;
use CW\Admin\DocumentRules;
use CW\Admin\ReasonCodes;
use CW\Admin\Warehouses;
use CW\Caller;
use CW\Invariants;
use CW\Settings;
use CW\Tests\Integration\Documents\DocumentTestCase;

/**
 * The set-it-yourself services (0019; docs/decisions.md Y2-Y19): a setting, a kind of record's rules, the reasons and the warehouses
 * with their places are changed by an admin or a reviewer (settings.manage, re-read inside the transaction; the CLI as a system
 * caller), always with a reason, as the row's next history version (config_change) and an audit row; a form drawn at an older
 * version is refused; nothing is ever deleted; the nightly configuration checks (K1-K3) find a change made around the history.
 * Every test restores the seed rows it changed (TestDb::clean keeps them) and ends with the full invariant check (StockTestCase).
 */
final class ConfigServicesTest extends DocumentTestCase
{
    /** @var list<array<string, mixed>> */
    private array $settings = [];
    /** @var list<array<string, mixed>> */
    private array $rules = [];
    /** @var list<array<string, mixed>> */
    private array $warehouses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting');
        $this->rules = self::$db->all('SELECT * FROM document_type');
        $this->warehouses = self::$db->all('SELECT id, name, is_sellable, is_active, stock_owner, owner_entity, note FROM warehouse');
    }

    protected function tearDown(): void
    {
        foreach ($this->settings as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
                [$r['v'], (int) $r['provisional'], $r['updated_actor'], $r['setting_key']]);
        }
        foreach ($this->rules as $r) {
            self::$db->exec('UPDATE document_type SET review_rule = ?, review_limit_units = ?, review_due_days = ?, approval_rule = ?, approval_limit_units = ?, '
                . 'reject_action = ? WHERE code = ?', [$r['review_rule'], $r['review_limit_units'], $r['review_due_days'], $r['approval_rule'],
                    $r['approval_limit_units'], $r['reject_action'], $r['code']]);
        }
        self::$db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->warehouses as $w) {
            self::$db->exec('UPDATE warehouse SET name = ?, is_sellable = ?, is_active = ?, stock_owner = ?, owner_entity = ?, note = ? WHERE id = ?',
                [$w['name'], $w['is_sellable'], $w['is_active'], $w['stock_owner'], $w['owner_entity'], $w['note'], $w['id']]);
        }
        self::$db->exec("DELETE FROM reason_code WHERE code IN ('seal', 'damp')"); // a test's records may still name it (the next clean() empties them)
        self::$db->exec('SET FOREIGN_KEY_CHECKS = 1');
        parent::tearDown();
    }

    public function testASettingIsChangedByAnAdminOrAReviewerWithAReasonAndKeptAsAVersion(): void
    {
        [$admin, $reviewer, $buyer] = [$this->staffUser('admin'), $this->staffUser('reviewer'), $this->staffUser('buyer')];
        $s = new Settings(self::$db);
        self::refused(403, 'role_not_allowed', fn () => $s->change($buyer, 'reorder.default_safety_days', '6', 'buyers may not'));
        self::refused(400, 'bad_reason', fn () => $s->change($admin, 'reorder.default_safety_days', '6', 'no'));
        self::refused(400, 'bad_value', fn () => $s->change($admin, 'reorder.default_safety_days', '121', 'too many days'));
        self::refused(400, 'company_details', fn () => $s->change($admin, 'company.legal_name', 'X', 'not a setting'));

        $r = $s->change($admin, 'reorder.default_safety_days', '6', 'owner wants six', null, 1);
        self::assertSame([true, 5, 6, 2], [$r['changed'], $r['before'], $r['after'], $r['version']]);
        self::assertSame(6, (new Settings(self::$db))->get('reorder.default_safety_days'));
        $row = self::$db->one("SELECT provisional, updated_actor FROM app_setting WHERE setting_key = 'reorder.default_safety_days'");
        self::assertSame([1, 'staff:' . $admin->staffUserId], [(int) $row['provisional'], $row['updated_actor']], 'a change does not agree it');
        $e = self::refused(409, 'changed_meanwhile', fn () => $s->change($reviewer, 'reorder.default_safety_days', '7', 'from an old page', null, 1));
        self::assertSame(['version' => 2, 'by' => 'staff:' . $admin->staffUserId], ['version' => $e->detail['version'], 'by' => $e->detail['by']]);
        $r = $s->change($reviewer, 'reorder.default_safety_days', '6', 'the owner agreed six', true, 2);
        self::assertSame([true, 3], [$r['changed'], $r['version']]);
        self::assertSame(0, (int) self::$db->value("SELECT provisional FROM app_setting WHERE setting_key = 'reorder.default_safety_days'"));
        self::assertSame(['changed' => false, 'before' => 6, 'after' => 6, 'version' => 3], $s->change($reviewer, 'reorder.default_safety_days', '6', 'same again', true, 3));

        $h = ConfigHistory::history(self::$db, 'setting', 'reorder.default_safety_days');
        self::assertSame([[3, 'agree', 'the owner agreed six', $reviewer->staffUserId], [2, 'change', 'owner wants six', $admin->staffUserId], [1, 'baseline', null, null]],
            array_map(static fn (array $v): array => [$v['version'], $v['action'], $v['reason'], $v['staff_user_id']], $h));
        self::assertSame(['value' => '5', 'provisional' => 1], $h[1]['before']);
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'setting.change' ORDER BY id DESC LIMIT 1"), true);
        self::assertSame(['reorder.default_safety_days', 6, 6, 3, true], [$audit['key'], $audit['before'], $audit['after'], $audit['version'], $audit['confirmed']]);
        self::assertSame([], ConfigInvariants::check(self::$db));

        // The CLI keeps working, through the same code, and is recorded the same way (actor system:settings).
        (new Settings(self::$db))->set(Caller::system('settings'), 'reorder.default_safety_days', '5', 'set back on the server');
        self::assertSame([4, 'system:settings', null], [ConfigHistory::version(self::$db, 'setting', 'reorder.default_safety_days'),
            ConfigHistory::latest(self::$db, 'setting', 'reorder.default_safety_days')['actor'], ConfigHistory::history(self::$db, 'setting', 'reorder.default_safety_days')[0]['staff_user_id']]);
        self::assertSame([], ConfigInvariants::check(self::$db));
    }

    public function testTheNightlyChecksFindAChangeMadeAroundTheHistory(): void
    {
        self::assertSame([], ConfigInvariants::check(self::$db), 'the baselines of 0019 match');
        self::assertSame([], Invariants::nightly(self::$db));
        self::$db->exec("UPDATE app_setting SET value_json = CAST('9' AS JSON) WHERE setting_key = 'reorder.default_safety_days'");
        self::$db->exec("UPDATE document_type SET review_due_days = 9 WHERE code = 'GRN'");
        self::$db->exec("UPDATE warehouse SET name = 'Renamed by hand' WHERE code = 'VERIFY'");
        $v = ConfigInvariants::check(self::$db);
        self::assertContains('config setting reorder.default_safety_days: changed outside its history since version 1 (value "5" -> "9")', $v);
        self::assertContains('config document_rule GRN: changed outside its history since version 1 (review_due_days 3 -> 9)', $v);
        self::assertContains('config warehouse VERIFY: changed outside its history since version 1 (name "Doubtful cancels awaiting recount" -> "Renamed by hand")', $v);
        self::assertSame($v, array_slice(Invariants::nightly(self::$db), -count($v)), 'the nightly run reports them');
        // K1 (a gap) and K3 (an actor that does not match its person), on rows made up by hand.
        self::$db->exec("INSERT INTO config_change (subject_type, subject_key, version, action, state, before_state, reason, actor) VALUES "
            . "('reason', 'damaged', 3, 'rename', JSON_OBJECT('label', 'x'), JSON_OBJECT('label', 'y'), 'made up', 'staff:999')");
        $v = ConfigInvariants::check(self::$db);
        self::assertContains('config reason damaged: versions 1..3 in 2 rows (expected 1..2, starting with a baseline or an add)', $v);
        self::assertContains('config reason damaged version 3: actor staff:999 does not match staff NULL', $v);
        self::$db->exec("DELETE FROM config_change WHERE subject_key = 'damaged' AND version = 3");
    }

    public function testTheRulesOfAKindOfRecordAndTheOkFirstSwitchedOff(): void
    {
        [$admin, $reviewer, $sc] = [$this->staffUser('admin'), $this->staffUser('reviewer'), $this->staffUser('stock_controller')];
        $rules = new DocumentRules(self::$db);
        self::refused(403, 'role_not_allowed', fn () => $rules->set($sc, 'ADJ', ['approval' => false], 'not mine'));
        self::refused(404, 'unknown_type', fn () => $rules->set($admin, 'XX', ['review_due_days' => 3], 'no such type'));
        $a = $this->item('strict', 10);
        // On (the 0008 default): 40 found units without a supplier document wait for a reviewer's OK.
        self::assertSame('awaiting_approval', $this->posted($sc, [['sku_id' => $a, 'qty' => 40]], [])->status);
        $r = $rules->set($admin, 'ADJ', ['approval' => false], 'leave it for now', 1);
        self::assertSame(['positive_without_supplier_doc', 'none', 10, 2], [$r['before']['approval_rule'], $r['after']['approval_rule'],
            $r['after']['approval_limit_units'], $r['version']]);
        self::assertSame('posted', $this->posted($sc, [['sku_id' => $a, 'qty' => 40]], [])->status, 'off: it posts at once (and is reviewed after)');
        $r = $rules->set($reviewer, 'ADJ', ['approval' => true, 'approval_limit_units' => '50'], 'back on, a higher limit', 2);
        self::assertSame(['positive_without_supplier_doc', 50], [$r['after']['approval_rule'], $r['after']['approval_limit_units']]);
        self::assertSame('posted', $this->posted($sc, [['sku_id' => $a, 'qty' => 40]], [])->status, '40 is under the new limit');
        self::assertSame('awaiting_approval', $this->posted($sc, [['sku_id' => $a, 'qty' => 51]], [])->status);
        $r = $rules->set($admin, 'ADJ', ['review_rule' => 'over_limit', 'review_limit_units' => '5', 'review_due_days' => '14', 'reject_action' => 'record'], 'fewer checks', 3);
        self::assertSame(['over_limit', 5, 14, 'record'], [$r['after']['review_rule'], $r['after']['review_limit_units'], $r['after']['review_due_days'], $r['after']['reject_action']]);
        self::refused(409, 'changed_meanwhile', fn () => $rules->set($admin, 'ADJ', ['review_due_days' => '3'], 'from an old page', 3));
        self::assertSame(['changed' => false], array_intersect_key($rules->set($admin, 'ADJ', ['review_due_days' => '14'], 'same', 4), ['changed' => 1]));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'document_type.change' AND entity_id = 'ADJ' "
            . "AND JSON_EXTRACT(detail, '$.version') = 4"));
        self::assertSame([], ConfigInvariants::check(self::$db));
    }

    public function testReasonsAreAddedRenamedAndSwitchedOffNeverDeleted(): void
    {
        [$admin, $reviewer, $sc] = [$this->staffUser('admin'), $this->staffUser('reviewer'), $this->staffUser('stock_controller')];
        $reasons = new ReasonCodes(self::$db);
        self::refused(403, 'role_not_allowed', fn () => $reasons->add($sc, 'seal', 'Broken seal', ['adjustment'], 'decrease', false, false, 'mine'));
        self::refused(400, 'bad_code', fn () => $reasons->add($admin, '9seal', 'Broken seal', ['adjustment'], 'decrease', false, false, 'bad code'));
        self::refused(400, 'bad_uses', fn () => $reasons->add($admin, 'seal', 'Broken seal', [], 'decrease', false, false, 'no uses'));
        self::refused(400, 'bad_uses', fn () => $reasons->add($admin, 'seal', 'Broken seal', ['gifts'], 'decrease', false, false, 'bad use'));
        self::refused(400, 'bad_direction', fn () => $reasons->add($admin, 'seal', 'Broken seal', ['adjustment'], 'sideways', false, false, 'bad way'));
        self::refused(409, 'reason_exists', fn () => $reasons->add($admin, 'damaged', 'Damaged again', ['adjustment'], 'decrease', false, false, 'twice'));
        $r = $reasons->add($admin, 'SEAL', 'Broken  seal', ['write_off', 'adjustment'], 'decrease', true, false, 'found at the bench');
        self::assertSame(['seal', 'Broken seal', ['adjustment', 'write_off'], 'decrease', true, false, false, true, 1],
            [$r['code'], $r['label'], $r['uses'], $r['direction'], $r['needs_note'], $r['is_gift'], $r['system_only'], $r['is_active'], $r['version']]);
        self::assertLessThan(999, $r['sort_order'], 'before "other"');
        self::assertSame(['changed' => true], array_intersect_key($reasons->rename($reviewer, 'seal', 'Broken seal on the pack', 'clearer name', 1), ['changed' => 1]));
        $a = $this->item('strict', 10);
        $d = $this->draft($sc, [['sku_id' => $a, 'qty' => -1]], ['external_ref' => 'SUP-1', 'reason_code' => 'seal', 'note' => 'seal broken']);
        $reasons->setActive($admin, 'seal', false, 'not used any more', 2);
        self::refused(422, 'reason_inactive', fn () => $this->docs->post($sc, $d->id, $d->version));
        $reasons->setActive($admin, 'seal', true, 'needed again', 3);
        self::assertSame('posted', $this->docs->post($sc, $d->id, $d->version)->status);
        foreach (['opening_rebase', 'review_rejected'] as $own) {
            self::refused(409, 'reason_locked', fn () => $reasons->rename($admin, $own, 'Renamed', 'not allowed'));
            self::refused(409, 'reason_locked', fn () => $reasons->setActive($admin, $own, false, 'not allowed'));
        }
        self::refused(404, 'unknown_reason', fn () => $reasons->rename($admin, 'nothing', 'Renamed', 'no such reason'));
        self::assertSame(['switch_on', 'switch_off', 'rename', 'add'], array_column(ConfigHistory::history(self::$db, 'reason', 'seal'), 'action'));
        self::assertSame([], ConfigInvariants::check(self::$db));
    }

    public function testWarehousesAndTheirOptionalPlaces(): void
    {
        [$admin, $reviewer, $buyer, $sc] = [$this->staffUser('admin'), $this->staffUser('reviewer'), $this->staffUser('buyer'), $this->staffUser('stock_controller')];
        $wh = new Warehouses(self::$db);
        self::refused(403, 'role_not_allowed', fn () => $wh->add($buyer, 'ROOM2', 'Room 2', false, false, 'own', null, null, 'not mine'));
        self::refused(400, 'bad_code', fn () => $wh->add($admin, '2ROOM', 'Room 2', false, false, 'own', null, null, 'bad code'));
        self::refused(422, 'confirm_needed', fn () => $wh->add($admin, 'ROOM2', 'Room 2', true, false, 'own', null, null, 'no tick'));
        self::refused(422, 'other_not_sellable', fn () => $wh->add($admin, 'VPG2', 'VPG 2 room', true, true, 'other', 'VPG 2', null, 'never sold from'));
        self::refused(400, 'bad_owner', fn () => $wh->add($admin, 'VPG2', 'VPG 2 room', false, false, 'other', '', null, 'no owner name'));
        self::refused(409, 'warehouse_exists', fn () => $wh->add($admin, 'MAIN', 'Main again', false, false, 'own', null, null, 'twice'));
        $vpg2 = $wh->add($admin, 'vpg2', 'VPG 2 room', false, false, 'other', 'VPG 2', 'Released by invoice', 'the owner\'s second account');
        self::assertSame(['VPG2', false, true, 'other', 'VPG 2', false], [$vpg2['code'], $vpg2['is_sellable'], $vpg2['is_active'], $vpg2['stock_owner'],
            $vpg2['owner_entity'], $vpg2['is_system']]);
        self::assertSame(3819, self::mysqlError(static fn () => self::$db->exec('UPDATE warehouse SET is_sellable = 1 WHERE id = ?', [$vpg2['id']])),
            'another account\'s stock is never sellable, in SQL too');
        self::refused(422, 'other_not_sellable', fn () => $wh->setSellable($admin, $vpg2['id'], true, true, 'sell it', 1));
        $room = $wh->add($reviewer, 'ROOM2', 'Room 2', false, false, 'own', null, null, 'a second room');
        self::refused(422, 'confirm_needed', fn () => $wh->setSellable($admin, $room['id'], true, false, 'no tick', 1));
        self::assertSame(['changed' => true], $wh->setSellable($admin, $room['id'], true, true, 'sell from it', 1));
        self::refused(409, 'sellable_warehouse', fn () => $wh->setOwner($admin, $room['id'], 'other', 'VPG 2', 'theirs now', 2));
        foreach (['MAIN', 'VERIFY', 'UNSTAMPED'] as $code) {
            $id = self::warehouseId($code);
            self::refused(409, 'system_warehouse', fn () => $wh->setActive($admin, $id, false, 'off'));
            self::refused(409, 'system_warehouse', fn () => $wh->setSellable($admin, $id, $code !== 'MAIN', true, 'flip'));
        }
        // Switched off only when empty: stock, a website, a record waiting.
        $a = $this->item('strict', 0);
        $this->ok($this->book('goods_in', $a, 3, 'ROOM2'));
        $e = self::refused(409, 'warehouse_not_empty', fn () => $wh->setActive($admin, $room['id'], false, 'empty it', 2));
        self::assertSame(['stock'], $e->detail['why']);
        $this->ok($this->book('adjustment', $a, -3, 'ROOM2'));
        self::assertSame(['changed' => true], $wh->setActive($admin, $room['id'], false, 'empty now', 2));
        $d = $this->docs->createDraft($sc, 'ADJ', ['external_ref' => 'SUP-1']);
        self::refused(422, 'warehouse_inactive', fn () => $this->docs->setLines($sc, $d->id, $d->version, [['sku_id' => $a, 'qty' => 1, 'warehouse' => 'ROOM2']]));
        $wh->setActive($admin, $room['id'], true, 'back in use', 3);
        // Places: optional, added, renamed, switched off; key WAREHOUSE/PLACE in the history.
        $p = $wh->addPlace($reviewer, self::warehouseId('MAIN'), 'overflow', 'Overflow room', 'Part of the main stock', 'the owner\'s overflow room');
        self::assertSame('MAIN/OVERFLOW', $p['key']);
        self::refused(409, 'place_exists', fn () => $wh->addPlace($reviewer, self::warehouseId('MAIN'), 'OVERFLOW', 'Again', null, 'twice'));
        self::refused(400, 'bad_code', fn () => $wh->addPlace($reviewer, self::warehouseId('MAIN'), 'over flow', 'Bad', null, 'space'));
        $wh->renamePlace($admin, $p['id'], 'Overflow room (back)', null, 'clearer', 1);
        $wh->setPlaceActive($admin, $p['id'], false, 'not used', 2);
        self::assertSame(['Overflow room (back)', false], [$wh->places(self::warehouseId('MAIN'))[0]['name'], $wh->places(self::warehouseId('MAIN'))[0]['is_active']]);
        self::assertSame(['switch_off', 'rename', 'add'], array_column(ConfigHistory::history(self::$db, 'location', 'MAIN/OVERFLOW'), 'action'));
        self::assertSame(['switch_on', 'switch_off', 'sellable', 'add'], array_column(ConfigHistory::history(self::$db, 'warehouse', 'ROOM2'), 'action'));
        self::assertSame([], ConfigInvariants::check(self::$db));
        $all = array_column($wh->all(), null, 'code');
        self::assertSame([0, 0, 0, 0], array_values($all['ROOM2']['stock']));
        self::assertSame(['all' => 1, 'active' => 0], $all['MAIN']['places']);
    }
}
