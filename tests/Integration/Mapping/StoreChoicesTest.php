<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Mapping\StoreChoices;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\TestDb;

/**
 * A store's choice at Add product (docs/decisions.md SC1-SC12; tracker CW-217): taken from the store once (receive), followed once the
 * variant has arrived (a new item, a pack's link, not stock) as system:store_choice with the store's staff member in the decision, never
 * on a variant someone decided, never making a second item of a barcode or a name the warehouse has: those wait for a person, whose
 * answer goes through DecisionService as that person.
 */
final class StoreChoicesTest extends MappingTestCase
{
    private Caller $shop;
    /** @var array<string, string> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = $this->site('vapeandgo');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        $this->saved[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    private function svc(): StoreChoices
    {
        return new StoreChoices(self::$db, $this->ds);
    }

    /** A variant as the store pushes it: its unmapped listing and its profile. @param list<string> $barcodes */
    private function variant(string $id, string $title, array $barcodes = [], ?Caller $site = null): int
    {
        $lid = $this->listing($site ?? $this->shop, $id, null);
        self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, barcodes) VALUES (?, ?, ?, ?, ?)',
            [$lid, 'Elux Legend Nic Salt E-Liquid 10ml', $title, 'Elux Legend', json_encode($barcodes)]);
        return $lid;
    }

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private static function choice(string $variant, string $choice = 'new', array $over = []): array
    {
        return $over + ['variant_id' => $variant, 'choice' => $choice, 'pack_of' => null, 'units' => null, 'staff' => ['id' => '17', 'name' => 'Aswin'],
            'chosen_at' => '2026-11-03T10:42:00Z'];
    }

    /** @return array<string, mixed> */
    private function row(string $variant): array
    {
        $r = self::$db->one('SELECT CAST(state AS CHAR) AS state, outcome, CAST(detail AS CHAR) AS detail, listing_id, decision_id, settled_by FROM store_choice '
            . 'WHERE channel_id = ? AND external_variant_id = ?', [$this->shop->channelId, $variant]);
        self::assertNotNull($r, "no choice for {$variant}");
        $r['detail'] = $r['detail'] === null ? [] : json_decode((string) $r['detail'], true);
        return $r;
    }

    private function idOf(string $variant): int
    {
        return (int) self::$db->value('SELECT id FROM store_choice WHERE channel_id = ? AND external_variant_id = ?', [$this->shop->channelId, $variant]);
    }

    public function testTheStoreSendsEachVariantsFirstChoiceOnceAndAMalformedCallIsRefusedWhole(): void
    {
        $r = $this->svc()->receive($this->shop, [self::choice('1001'), self::choice('1002', 'none'),
            self::choice('1003', 'pack', ['pack_of' => 'CW-000123', 'units' => 10])]);
        self::assertSame(['accepted' => 3, 'ignored' => []], $r);
        $again = $this->svc()->receive($this->shop, [self::choice('1001', 'none'), self::choice('1004')]);
        self::assertSame(['accepted' => 1, 'ignored' => [['variant_id' => '1001', 'why' => 'already_received']]], $again);
        self::assertSame('new', (string) self::$db->value("SELECT CAST(choice AS CHAR) FROM store_choice WHERE external_variant_id = '1001'"), 'the first choice is kept');
        $p = self::$db->one("SELECT pack_of_code, units, staff_ref, staff_name, chosen_at, CAST(state AS CHAR) AS state FROM store_choice WHERE external_variant_id = '1003'");
        self::assertSame(['CW-000123', 10, '17', 'Aswin', '2026-11-03 10:42:00.000000', 'waiting'], [$p['pack_of_code'], (int) $p['units'], $p['staff_ref'], $p['staff_name'],
            $p['chosen_at'], $p['state']]);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'store_choice.receive'"));

        $before = (int) self::$db->value('SELECT COUNT(*) FROM store_choice');
        foreach ([
            [self::choice('2001', 'pack', ['pack_of' => 'CW-000123'])],                      // a pack says how many
            [self::choice('2002', 'pack', ['pack_of' => 'CW-000123', 'units' => 1])],        // a pack of one is not a pack
            [self::choice('2003', 'new', ['pack_of' => 'CW-000123'])],                       // only a pack names an item
            [self::choice('2004', 'maybe')],
            [self::choice('V-1')],                                                            // the store's variant number is digits
            [self::choice('2005', 'new', ['staff' => ['id' => '17']])],                      // who chose it, by name
            [self::choice('2006', 'new', ['chosen_at' => '3 Nov'])],
            [self::choice('2010', 'new', ['chosen_at' => '2026-02-31T10:00:00Z'])],          // a day that does not exist is not moved to March
            [self::choice('2011', 'new', ['chosen_at' => '9999-12-31T23:59:59-05:00'])],
            [self::choice('2012', 'new', ['chosen_at' => "2026-11-03T10:42:00Z\0"])],
            [self::choice('2013', 'new', ['chosen_at' => '2026-11-03T10:42:00'])],           // a time without its zone
            [self::choice('2007'), self::choice('2007')],
            [self::choice('2008'), self::choice('2009', 'pack', ['pack_of' => 'X', 'units' => 5])], // one bad choice: the whole call is refused
        ] as $i => $bad) {
            self::refused(400, 'bad_request', fn () => $this->svc()->receive($this->shop, $bad));
        }
        self::assertSame($before, (int) self::$db->value('SELECT COUNT(*) FROM store_choice'), 'nothing of a refused call is kept');
        self::refused(403, 'channel_required', fn () => $this->svc()->receive($this->staffUser('mapping_lead'), [self::choice('3001')]));
    }

    public function testANewItemIsMadeFromTheVariantAsTheStoresChoiceWithWhoseChoiceItWas(): void
    {
        $lid = $this->variant('1001', 'Mr Blue Nic Salt E-Liquid by Elux Legend 10ml - 10mg', ['5059000123456']);
        $this->svc()->receive($this->shop, [self::choice('1001'), self::choice('1009')]);
        $dry = $this->svc()->run(false);
        self::assertSame(['made' => 1], $dry['counts']);
        self::assertSame('unmapped', $this->link($lid)['status'], 'a dry run writes nothing');
        self::assertSame('waiting', $this->row('1001')['state']);

        $r = $this->svc()->run(true);
        self::assertSame(1, $r['looked'], 'the variant that has not arrived (1009) waits');
        $link = $this->link($lid);
        self::assertSame(['mapped', 1], [$link['status'], $link['units_per_item']]);
        $sku = self::$db->one('SELECT code, name, origin_listing_id FROM sku WHERE id = ?', [$link['sku_id']]);
        self::assertSame(['Mr Blue Nic Salt E-Liquid by Elux Legend 10ml - 10mg', $lid], [$sku['name'], (int) $sku['origin_listing_id']]);
        $d = self::$db->one("SELECT decided_by, actor, reason, CAST(detail AS CHAR) AS detail FROM match_decision WHERE listing_id = ? AND action = 'new_item'", [$lid]);
        self::assertSame([null, 'system:store_choice'], [$d['decided_by'], $d['actor']]);
        self::assertSame('Aswin (Add product)', (new \CW\Ui\Queries(self::$db))->decisionsOf($lid)[0]['decider']);
        self::assertSame('Chosen at Add product on vapeandgo by Aswin: a new item.', $d['reason']);
        $who = json_decode((string) $d['detail'], true)['store_choice'];
        self::assertSame(['Aswin', '17', 'vapeandgo', $this->idOf('1001')], [$who['staff_name'], $who['staff_ref'], $who['store'], $who['id']]);
        $row = $this->row('1001');
        self::assertSame(['done', 'made', $sku['code'], $lid], [$row['state'], $row['outcome'], $row['detail']['sku'], (int) $row['listing_id']]);
        self::assertSame('waiting', $this->row('1009')['state']);

        // It arrives later: followed then. Its name is the first one's: it waits for a person instead of making a second item.
        $this->variant('1009', 'Mr Blue Nic Salt E-Liquid by Elux Legend 10ml - 10mg');
        $this->svc()->run(true);
        self::assertSame(['to_check', 'same_name', $sku['code']], [$this->row('1009')['state'], $this->row('1009')['outcome'], $this->row('1009')['detail']['sku']]);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'no second item');
    }

    public function testABarcodeOrANameTheWarehouseHasWaitsForAPersonWhoseAnswerIsTheirOwnDecision(): void
    {
        $item = $this->item('legacy', 0, 'Banana Ice Nic Salt E-Liquid by Elux Legend 10ml - 10mg');
        self::$db->exec("INSERT INTO sku_barcode (sku_id, barcode, units_per_scan) VALUES (?, '5059000999999', 1)", [$item]);
        $close = $this->item('legacy', 0, 'Menthol Nic-Salt E Liquid by Elux Legend 10ml (10mg)');
        $byCode = $this->variant('1001', 'Banana Ice / 10mg', ['5059000999999']);
        $byName = $this->variant('1002', 'Menthol Nic Salt E-Liquid by Elux Legend 10ml - 10mg');
        $other = $this->site('electrofag');
        $this->variant('77', 'Something else', ['5059000777777'], $other);
        $byOther = $this->variant('1003', 'Watermelon / 20mg', ['5059000777777']);
        $this->svc()->receive($this->shop, [self::choice('1001'), self::choice('1002'), self::choice('1003')]);
        $this->svc()->run(true);
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$item]);
        self::assertSame(['to_check', 'barcode_on_item', $code, '5059000999999'], [$this->row('1001')['state'], $this->row('1001')['outcome'],
            $this->row('1001')['detail']['sku'], $this->row('1001')['detail']['barcode']]);
        self::assertSame(['to_check', 'close_name', $close], [$this->row('1002')['state'], $this->row('1002')['outcome'], $this->row('1002')['detail']['sku_id']]);
        self::assertSame(['to_check', 'barcode_on_listing', 'electrofag', '77'], [$this->row('1003')['state'], $this->row('1003')['outcome'],
            $this->row('1003')['detail']['store'], $this->row('1003')['detail']['variant']]);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'nothing was made');
        self::assertSame(3, $this->svc()->openCount());
        $list = $this->svc()->list();
        self::assertSame([$item, $close, null], array_map(static fn (array $r): ?int => $r['found'] === null ? null : (int) $r['found']['id'], $list));

        // A person answers: the same item (a link, one sale one item, as that person), a new item, not stock.
        $lead = $this->staffUser('mapping_lead');
        $r = $this->svc()->answer($lead, $this->idOf('1001'), 'same', $this->version($byCode), null);
        self::assertSame('joined', $r['outcome']);
        self::assertSame([$item, 'mapped', 1], [$this->link($byCode)['sku_id'], $this->link($byCode)['status'], $this->link($byCode)['units_per_item']]);
        self::assertSame($lead->staffUserId, (int) self::$db->value("SELECT decided_by FROM match_decision WHERE listing_id = ? AND action = 'link'", [$byCode]));
        self::assertSame(['done', 'joined', $lead->staffUserId], [$this->row('1001')['state'], $this->row('1001')['outcome'], (int) $this->row('1001')['settled_by']]);
        self::assertSame('made_by_person', $this->svc()->answer($lead, $this->idOf('1002'), 'new', $this->version($byName), null)['outcome']);
        self::assertSame('mapped', $this->link($byName)['status']);
        self::assertSame('not_stock_by_person', $this->svc()->answer($lead, $this->idOf('1003'), 'none', $this->version($byOther), null, 'A page only')['outcome']);
        self::assertSame('landing', $this->link($byOther)['status']);
        self::assertSame(0, $this->svc()->openCount());
        self::refused(409, 'already_settled', fn () => $this->svc()->answer($lead, $this->idOf('1003'), 'new', $this->version($byOther), null));
        self::refused(403, 'staff_required', fn () => $this->svc()->answer(Caller::system('test'), $this->idOf('1003'), 'new', 0, null));
    }

    public function testAPersonsAnswerIsRefusedWhenTheVariantChangedAndAPersonWithoutTheRoleCannotAnswer(): void
    {
        $this->item('legacy', 0, 'Grape Ice / 10mg');
        $lid = $this->variant('1001', 'Grape Ice / 10mg');
        $this->svc()->receive($this->shop, [self::choice('1001')]);
        $this->svc()->run(true);
        self::assertSame('same_name', $this->row('1001')['outcome']);
        $seen = $this->version($lid);
        self::$db->exec('UPDATE channel_listing SET map_version = map_version + 1 WHERE id = ?', [$lid]);
        self::refused(409, 'map_version_conflict', fn () => $this->svc()->answer($this->staffUser('mapping_lead'), $this->idOf('1001'), 'same', $seen, null));
        self::assertSame('to_check', $this->row('1001')['state'], 'the refused answer left the row as it was');
        self::refused(403, 'role_not_allowed', fn () => $this->svc()->answer($this->staffUser('viewer'), $this->idOf('1001'), 'same', $this->version($lid), null));
    }

    public function testAnAnswerNeverMovesAVariantDecidedElsewhere(): void
    {
        $item = $this->item('legacy', 0, 'Peach Ice / 10mg');
        $other = $this->item('legacy', 0, 'Something else 10mg');
        $lid = $this->variant('1001', 'Peach Ice / 10mg');
        $this->svc()->receive($this->shop, [self::choice('1001')]);
        $this->svc()->run(true);
        self::assertSame('same_name', $this->row('1001')['outcome']);
        $lead = $this->staffUser('mapping_lead');
        // Linked on its own page meanwhile; a form naming the version as it is now still moves nothing.
        $this->decide($lead, 'link', $lid, ['sku_id' => $other]);
        self::refused(409, 'decided_elsewhere', fn () => $this->svc()->answer($lead, $this->idOf('1001'), 'same', $this->version($lid), null, null, ''));
        self::assertSame([$other, 'mapped'], [$this->link($lid)['sku_id'], $this->link($lid)['status']]);
        self::assertSame('to_check', $this->row('1001')['state'], 'the job settles it (decided_elsewhere)');
        unset($item);
    }

    public function testAPackLinksToItsItemAndWaitsForAMatchingLeadWhileTheRuleIsOn(): void
    {
        $single = $this->item('legacy', 0, 'Nic Nic 10ml 18mg');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$single]);
        $ten = $this->variant('1001', 'Nic Nic 10 x 10ml 18mg');
        $twenty = $this->variant('1002', 'Nic Nic 20 x 10ml 18mg');
        $this->variant('1003', 'Mystery pack');
        $this->svc()->receive($this->shop, [self::choice('1001', 'pack', ['pack_of' => $code, 'units' => 10]),
            self::choice('1002', 'pack', ['pack_of' => $code, 'units' => 20]), self::choice('1003', 'pack', ['pack_of' => 'CW-999999', 'units' => 5])]);
        $this->setting('approvals.match_multiple', 'true');
        $this->svc()->run(true, 1);
        $pending = self::$db->one("SELECT id, decided_by, actor, CAST(state AS CHAR) AS state, units_per_item FROM match_decision WHERE listing_id = ?", [$ten]);
        self::assertSame([null, 'system:store_choice', 'pending_second', 10], [$pending['decided_by'], $pending['actor'], $pending['state'], (int) $pending['units_per_item']]);
        self::assertSame(['done', 'waiting_second'], [$this->row('1001')['state'], $this->row('1001')['outcome']]);
        self::assertSame('unmapped', $this->link($ten)['status'], 'nothing linked until a matching lead says yes');
        $q = new \CW\Ui\Queries(self::$db);
        self::assertSame(['Aswin (Add product)'], array_column($q->pendingDecisions(), 'decider'), 'the screens name the store\'s staff member');
        // One matching lead approves: the staff member chose it, the lead says yes (two people).
        $this->ds->approve($this->staffUser('mapping_lead'), (int) $pending['id']);
        self::assertSame([$single, 'mapped', 10], [$this->link($ten)['sku_id'], $this->link($ten)['status'], $this->link($ten)['units_per_item']]);

        $this->setting('approvals.match_multiple', 'false');
        $this->svc()->run(true);
        self::assertSame([$single, 'mapped', 20], [$this->link($twenty)['sku_id'], $this->link($twenty)['status'], $this->link($twenty)['units_per_item']]);
        self::assertSame(['done', 'linked', $code, 20], [$this->row('1002')['state'], $this->row('1002')['outcome'], $this->row('1002')['detail']['sku'],
            $this->row('1002')['detail']['units']]);
        self::assertSame(['refused', 'no_such_item'], [$this->row('1003')['state'], $this->row('1003')['outcome']]);
        self::assertSame(1, $this->svc()->openCount(), 'a refused choice waits for a person too');
    }

    public function testAPackOfAnItemJoinedIntoAnotherLinksToTheItemItIsNow(): void
    {
        $kept = $this->item('legacy', 0, 'Kept 10mg');
        $gone = $this->item('legacy', 0, 'Gone 10mg');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$kept, $gone]);
        $lid = $this->variant('1001', 'A 5 pack');
        $goneCode = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$gone]);
        $this->setting('approvals.match_multiple', 'false');
        $this->svc()->receive($this->shop, [self::choice('1001', 'pack', ['pack_of' => $goneCode, 'units' => 5])]);
        $this->svc()->run(true);
        self::assertSame($kept, $this->link($lid)['sku_id']);
        self::assertSame($goneCode, $this->row('1001')['detail']['named']);
    }

    public function testNotStockIsTheLandingMarkWithTheStoresChoiceAsWhy(): void
    {
        $lid = $this->variant('1001', 'Elux Legend Nic Salt E-Liquid 10ml');
        $this->propose($lid, "Can't tell");
        $this->svc()->receive($this->shop, [self::choice('1001', 'none')]);
        $this->svc()->run(true);
        self::assertSame('landing', $this->link($lid)['status']);
        $d = self::$db->one("SELECT decided_by, actor, CAST(detail AS CHAR) AS detail FROM match_decision WHERE listing_id = ? AND action = 'landing'", [$lid]);
        $detail = json_decode((string) $d['detail'], true);
        self::assertSame([null, 'system:store_choice', 'store_choice', 'Aswin'], [$d['decided_by'], $d['actor'], $detail['landing']['reason'],
            $detail['store_choice']['staff_name']]);
        self::assertSame(['done', 'not_stock'], [$this->row('1001')['state'], $this->row('1001')['outcome']]);
    }

    public function testAVariantSomeoneDecidedIsNeverTouchedAndOneWithAHistoryWaitsForAPerson(): void
    {
        $item = $this->item('legacy', 0, 'Linked 10mg');
        $linked = $this->variant('1001', 'Linked by hand');
        $this->decide($this->staffUser('mapping_lead'), 'link', $linked, ['sku_id' => $item]);
        $landing = $this->variant('1002', 'A landing page');
        $this->decide($this->staffUser('mapping_lead'), 'landing', $landing);
        $rejected = $this->variant('1003', 'Rejected once');
        $p = $this->propose($rejected, 'Check', $item);
        $this->decide($this->staffUser('mapping_lead'), 'reject', $rejected, ['sku_id' => $item, 'proposal_id' => $p]);
        $suggested = $this->variant('1004', 'Suggested by the check');
        $this->propose($suggested, 'Check', $item);
        $this->svc()->receive($this->shop, [self::choice('1001'), self::choice('1002'), self::choice('1003'), self::choice('1004')]);
        $this->svc()->run(true);
        self::assertSame(['done', 'already_decided'], [$this->row('1001')['state'], $this->row('1001')['outcome']]);
        self::assertSame(['done', 'already_decided', 'landing'], [$this->row('1002')['state'], $this->row('1002')['outcome'], $this->row('1002')['detail']['status']]);
        self::assertSame(['to_check', 'has_history'], [$this->row('1003')['state'], $this->row('1003')['outcome']]);
        self::assertSame(['to_check', 'suggested_match', $item], [$this->row('1004')['state'], $this->row('1004')['outcome'], $this->row('1004')['detail']['sku_id']]);
        self::assertSame([$item, 'mapped'], [$this->link($linked)['sku_id'], $this->link($linked)['status']]);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'nothing was made');

        // The person decides 1004 on its own page: the row leaves the list and the job settles it.
        $open = (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$suggested]);
        $this->decide($this->staffUser('mapping_lead'), 'link', $suggested, ['sku_id' => $item, 'proposal_id' => $open]);
        self::assertSame(1, $this->svc()->openCount(), 'only 1003 now');
        self::assertSame(['decided_elsewhere' => 1], $this->svc()->run(true)['counts']);
        self::assertSame(['done', 'decided_elsewhere'], [$this->row('1004')['state'], $this->row('1004')['outcome']]);
    }

    public function testAConflictAndAPersonsDecisionUnderTheLockStopTheJob(): void
    {
        $item = $this->item('legacy', 0, 'Conflicted 10mg');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$item]);
        $conflict = $this->variant('1001', 'Two items look like it');
        $this->propose($conflict, 'Conflict');
        $pack = $this->variant('1002', 'A conflicted pack');
        $this->propose($pack, 'Conflict');
        $this->setting('approvals.match_multiple', 'false');
        $this->svc()->receive($this->shop, [self::choice('1001'), self::choice('1002', 'pack', ['pack_of' => $code, 'units' => 5])]);
        $this->svc()->run(true);
        self::assertSame(['to_check', 'conflict'], [$this->row('1001')['state'], $this->row('1001')['outcome']]);
        self::assertSame(['to_check', 'conflict'], [$this->row('1002')['state'], $this->row('1002')['outcome']]);
        self::assertSame(['suggested', 'suggested'], [$this->link($conflict)['status'], $this->link($pack)['status']]);

        // The job's own look found nothing; a person's "not this item" (it moves no version) lands before the decision: refused under the lock.
        $lid = $this->variant('1003', 'Rejected meanwhile');
        $p = $this->propose($lid, 'Check', $item);
        $this->decide($this->staffUser('mapping_lead'), 'reject', $lid, ['sku_id' => $item, 'proposal_id' => $p]);
        $who = ['id' => 9, 'store' => 'vapeandgo', 'staff_ref' => '17', 'staff_name' => 'Aswin', 'chosen_at' => '2026-11-03 10:42:00.000000'];
        $open = self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$lid]);
        self::refused(409, 'has_history', fn () => $this->decide(Caller::system(StoreChoices::JOB), 'new_item', $lid,
            ['store_choice' => $who, 'proposal_id' => $open === null ? null : (int) $open]));
        $c = self::refused(403, 'lead_required', fn () => $this->decide(Caller::system(StoreChoices::JOB), 'new_item', $conflict,
            ['store_choice' => $who, 'proposal_id' => (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$conflict])]));
        self::assertStringContainsString('Conflict', $c->getMessage());
    }

    public function testOnlyTheStoreChoiceJobMakesADecisionWithNoPersonAndOnlyOnAVariantNobodyDecided(): void
    {
        $lid = $this->variant('1001', 'Grape 10mg');
        $who = ['id' => 1, 'store' => 'vapeandgo', 'staff_ref' => '17', 'staff_name' => 'Aswin', 'chosen_at' => '2026-11-03 10:42:00.000000'];
        self::refused(403, 'staff_required', fn () => $this->decide(Caller::system('landing_pages'), 'new_item', $lid));
        self::refused(400, 'bad_request', fn () => $this->decide(Caller::system(StoreChoices::JOB), 'new_item', $lid));
        self::refused(400, 'bad_request', fn () => $this->decide($this->staffUser('mapping_lead'), 'new_item', $lid, ['store_choice' => $who]));
        self::refused(400, 'bad_request', fn () => $this->decide(Caller::system(StoreChoices::JOB), 'new_item', $lid, ['store_choice' => $who + ['extra' => 1]]));
        self::refused(403, 'staff_required', fn () => $this->decide(Caller::system(StoreChoices::JOB), 'ignore', $lid, ['store_choice' => $who]));
        $item = $this->item('legacy', 0, 'Grape 10mg item');
        $this->decide($this->staffUser('mapping_lead'), 'link', $lid, ['sku_id' => $item]);
        self::refused(409, 'not_open', fn () => $this->decide(Caller::system(StoreChoices::JOB), 'new_item', $lid, ['store_choice' => $who]));
        // The database's own rule: a decision with no person is a suggestion, a landing mark, or the store choice job's new item or link.
        try {
            self::$db->exec("INSERT INTO match_decision (listing_id, action, sku_id, units_per_item, decided_by, actor, state, expected_map_version, applied_at) "
                . "VALUES (?, 'link', ?, 1, NULL, 'system:other', 'applied', 0, NOW(6))", [$lid, $item]);
            self::fail('the CHECK lets a decision with no person through');
        } catch (\PDOException $e) {
            self::assertStringContainsString('ck_match_decision_decider', $e->getMessage());
        }
    }

    public function testTheJobLooksAgainUnderTheLockAndNamesTheSuggestionOpenThen(): void
    {
        $lid = $this->variant('1001', 'Strawberry 10mg');
        $this->svc()->receive($this->shop, [self::choice('1001')]);
        $p = $this->propose($lid, "Can't tell", null, [], true, 'first');
        self::assertSame('made', $this->svc()->plan($this->idOf('1001'))['outcome']);
        // Another suggestion replaces the first before the job runs: the job's own look, under the row's lock, names the one open then.
        self::$db->exec("UPDATE match_proposal SET status = 'superseded' WHERE id = ?", [$p]);
        $second = $this->propose($lid, "Can't tell", null, [], true, 'second');
        self::assertSame(['made' => 1], $this->svc()->run(true)['counts']);
        self::assertSame('mapped', $this->link($lid)['status']);
        self::assertSame('decided', (string) self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$second]));
    }

    public function testTheToolsDryRunWritesNothingAndApplyFollows(): void
    {
        $lid = $this->variant('1001', 'Cola Ice 10mg');
        $this->svc()->receive($this->shop, [self::choice('1001')]);
        $dry = self::tool('apply_store_choices.php', '--list');
        self::assertSame(0, $dry['code'], $dry['err']);
        self::assertStringContainsString('DRY RUN (nothing written) looked=1 made=1', $dry['err'] . $dry['out']);
        self::assertSame('unmapped', $this->link($lid)['status']);
        $one = self::tool('apply_store_choices.php', '--choice=' . $this->idOf('1001'));
        self::assertStringContainsString('"outcome":"made"', $one['err'] . $one['out']);
        $run = self::tool('apply_store_choices.php', '--apply');
        self::assertSame(0, $run['code'], $run['err']);
        self::assertSame('mapped', $this->link($lid)['status']);
        self::assertSame(2, self::tool('apply_store_choices.php', '--choice=1', '--apply')['code'], '--choice is always dry');
    }

    /** @return array{code: int, out: string, err: string} */
    private static function tool(string $script, string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/{$script}", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
