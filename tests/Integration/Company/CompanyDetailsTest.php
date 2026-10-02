<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Company;

use CW\Caller;
use CW\Company\CompanyDetails;
use CW\Company\CompanyInvariants;
use CW\CwException;
use CW\Db;
use CW\Invariants;
use CW\PurchaseOrders\PurchaseOrderPdf;
use CW\Settings;
use CW\Tests\Integration\PurchaseOrders\PurchaseOrderTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW\Company\CompanyDetails (I90-I99) against the test schema: a save adds an unconfirmed version (audited company.change
 * with before/after of the fields changed), nothing changed writes nothing; a stale version and a save racing another of the
 * same version (the PRIMARY KEY) are 409 with nothing written; confirming needs every required detail, adds a `confirm`
 * version and is undone by the next change; a person who confirms their own change of a watched field, compared with the
 * last confirmed details that carry nothing rejected (however many saves it took), opens a check for another reviewer who was
 * not involved; a change confirmed by someone else needs none; a rejected change unconfirms the details in use that carry it,
 * cannot be confirmed again by the people involved, and flags the approved orders that carry it until another reviewer
 * confirms it after all; who may do what (reviewer, never admin, staff only); the history; a posted PO keeps the details it
 * was approved with while a draft prints the details in use; the app login adds versions but never rewrites them, and a
 * version it makes up is found by the invariants (C1-C4). Every test ends with the full invariant check (StockTestCase).
 */
final class CompanyDetailsTest extends PurchaseOrderTestCase
{
    /** @return array<string, string> */
    private static function details(array $over = []): array
    {
        return $over + ['legal_name' => 'Example Vapes Ltd', 'trading_name' => 'Vape and Go', 'company_number' => '01234567', 'address' => "1 High Street\nLeeds\nLS1 1AA",
            'vat_registered' => 'yes', 'vat_number' => 'GB 123 4567 82', 'phone' => '0113 496 0000', 'email' => 'buying@example.co.uk',
            'delivery_address' => "Unit 4, Example Park\nLeeds LS2 2BB"];
    }

    /** @return list<array<string, mixed>> the audit rows of the company details, oldest first, detail decoded */
    private static function companyAudits(string $action): array
    {
        return array_map(static fn (array $r): array => ['entity_id' => $r['entity_id'], 'actor' => $r['actor']] + (array) json_decode((string) $r['detail'], true),
            self::$db->all("SELECT entity_id, actor, detail FROM audit_log WHERE action = ? ORDER BY id", [$action]));
    }

    private static function openChecks(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM review_task WHERE subject_type = 'company' AND state = 'open'");
    }

    public function testSaveConfirmChangeAndTheHistory(): void
    {
        $svc = new CompanyDetails(self::$db);
        $owner = $this->staffUser(['reviewer', 'mapping_lead']);
        self::assertSame(0, $svc->current()['version'], 'TestDb::clean empties company_profile');

        // A first save: version 1, unconfirmed; tidied values; audited with every field changed.
        $r = $svc->save($owner, 0, self::details(['legal_name' => 'Example Vapes']), 'first go');
        self::assertSame(['result' => 'saved', 'version' => 1, 'changed' => ['legal_name', 'trading_name', 'company_number', 'address', 'vat_registered', 'vat_number',
            'phone', 'email', 'delivery_address'], 'was_confirmed' => false], $r);
        $p = $svc->current();
        self::assertSame([1, 'change', 'GB123456782', true, false, $owner->staffUserId, 'first go'], [$p['version'], $p['kind'], $p['vat_number'], $p['vat_registered'],
            $p['confirmed'], (int) $p['saved_by'], $p['reason']]);
        $a = self::companyAudits('company.change');
        self::assertCount(1, $a);
        self::assertSame(['1', "staff:{$owner->staffUserId}", 1, 'first go', false], [$a[0]['entity_id'], $a[0]['actor'], $a[0]['version'], $a[0]['reason'],
            $a[0]['was_confirmed']]);
        self::assertEquals(['legal_name' => '', 'trading_name' => '', 'company_number' => '', 'address' => '', 'vat_registered' => null, 'vat_number' => '', 'phone' => '',
            'email' => '', 'delivery_address' => ''], $a[0]['before']);
        self::assertSame('GB123456782', $a[0]['after']['vat_number']);

        // The same details again: nothing written.
        self::assertSame('unchanged', $svc->save($owner, 1, self::details(['legal_name' => 'Example Vapes', 'vat_number' => 'gb123456782']))['result']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM company_profile'));

        // A change: version 2, only that field in the audit.
        self::assertSame(['legal_name'], $svc->save($owner, 1, self::details(), null)['changed']);
        $a = self::companyAudits('company.change')[1];
        self::assertEquals([['legal_name' => 'Example Vapes'], ['legal_name' => 'Example Vapes Ltd'], null], [$a['before'], $a['after'], $a['reason']]);

        // Confirm what the page showed: version 3 (kind confirm, same details). The first confirmation has nothing to be
        // compared with: no check. Again: "already", nothing written.
        self::assertSame(['result' => 'confirmed', 'version' => 3, 'review_task' => null, 'baseline_version' => null, 'watched_changed' => []], $svc->confirm($owner, 2));
        $p = $svc->current();
        self::assertSame([3, 'confirm', true, $owner->staffUserId, "staff:{$owner->staffUserId}", null], [$p['version'], $p['kind'], $p['confirmed'], (int) $p['confirmed_by'],
            $p['confirmed_actor'], $p['baseline_version']]);
        self::assertNotNull($p['confirmed_at']);
        self::assertSame('already', $svc->confirm($owner, 3)['result']);
        $c = self::companyAudits('company.confirm');
        self::assertCount(1, $c);
        self::assertSame([3, 2, null, [], null, 'Example Vapes Ltd'], [$c[0]['version'], $c[0]['confirms_version'], $c[0]['baseline_version'], $c[0]['watched_changed'],
            $c[0]['review_task'], $c[0]['details']['legal_name']]);
        self::assertSame(['Example Vapes Ltd', true, 3], [(new Settings(self::$db))->company()['legal_name'], (new Settings(self::$db))->company()['confirmed'],
            (new Settings(self::$db))->company()['version']]);

        // A change of a field nobody watches (the phone) makes them unconfirmed again; confirming it asks nobody.
        $r = $svc->save($owner, 3, self::details(['phone' => '0113 496 0001']), 'new number');
        self::assertSame([4, ['phone'], true], [$r['version'], $r['changed'], $r['was_confirmed']]);
        self::assertFalse($svc->company()['confirmed']);
        self::assertSame(['result' => 'confirmed', 'version' => 5, 'review_task' => null, 'baseline_version' => 3, 'watched_changed' => []], $svc->confirm($owner, 4));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM review_task WHERE subject_type = 'company'"));

        // The history: newest first, each with what it changed against the one before.
        $h = $svc->history();
        self::assertSame([5, 4, 3, 2, 1], array_column($h, 'version'));
        self::assertSame(['confirm', 'change', 'confirm', 'change', 'change'], array_column($h, 'kind'));
        self::assertSame([['field' => 'phone', 'label' => 'phone', 'before' => '0113 496 0000', 'after' => '0113 496 0001']], $h[1]['changes']);
        self::assertSame([], $h[0]['changes'], 'a confirmation changes nothing');
        self::assertNull($h[0]['review']);
        self::assertCount(9, $h[4]['changes']);
        self::assertSame(['vat_registered', 'not known yet', 'VAT registered'], [$h[4]['changes'][4]['field'], $h[4]['changes'][4]['before'], $h[4]['changes'][4]['after']]);
        self::assertSame('GB 123 4567 82', $h[4]['changes'][5]['after'], 'VAT numbers as people read them');
        self::assertSame([['field' => 'phone', 'label' => 'phone', 'before' => '0113 496 0000', 'after' => '0113 496 0001']], $svc->changesSince(3));
        self::assertSame([], $svc->changesSince(5));
        self::assertSame([], CompanyInvariants::check(self::$db));
    }

    public function testStaleVersionsAndARaceOfTheSameVersionWriteNothing(): void
    {
        $owner = $this->staffUser('reviewer');
        $svc = new CompanyDetails(self::$db);
        $svc->save($owner, 0, self::details());
        $count = static fn (): array => [(int) self::$db->value('SELECT COUNT(*) FROM company_profile'), (int) self::$db->value("SELECT COUNT(*) FROM audit_log "
            . "WHERE action LIKE 'company.%'"), (int) self::$db->value('SELECT COUNT(*) FROM review_task')];
        $before = $count();

        // The form was drawn at version 0, someone saved version 1 meanwhile: 409, nothing written; the same for a confirmation.
        $e = self::refused(409, 'company_changed', fn () => $svc->save($owner, 0, self::details(['phone' => '0113 000 0000'])));
        self::assertStringStartsWith("Someone changed the company details while you had them open (reviewer 1 saved version 1 at ", $e->getMessage());
        self::assertStringEndsWith(' UTC): nothing was saved.', $e->getMessage());
        self::assertSame(['version' => 1, 'expected_version' => 0], $e->detail);
        self::assertStringEndsWith(' UTC): nothing was confirmed.', self::refused(409, 'company_changed', fn () => $svc->confirm($owner, 0))->getMessage());
        self::refused(409, 'company_changed', fn () => $svc->save($owner, 7, self::details()));
        self::assertSame($before, $count());

        // Two people saving version 1 at the same moment: both read version 1, the other one's save (row and audit) lands first;
        // the PRIMARY KEY turns this one away (409) and its transaction, audit row included, rolls back.
        $other = TestDb::connect();
        $racing = new CompanyDetails(self::$db, static function () use ($other, $owner): \DateTimeImmutable {
            static $done = false;
            if (!$done) {
                $done = true;
                $other->exec("INSERT INTO company_profile (version, kind, legal_name, saved_by, saved_actor) VALUES (2, 'change', 'The other save', ?, ?)",
                    [$owner->staffUserId, $owner->actor]);
                $other->exec("INSERT INTO audit_log (actor, staff_user_id, action, entity_type, entity_id, detail) VALUES (?, ?, 'company.change', 'company_profile', '2', '{}')",
                    [$owner->actor, $owner->staffUserId]);
            }
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        });
        $e = self::refused(409, 'company_changed', fn () => $racing->save($owner, 1, self::details(['phone' => '0113 000 0000'])));
        self::assertSame('Someone else saved the company details at the same moment: nothing was saved.', $e->getMessage());
        self::assertSame([$before[0] + 1, $before[1] + 1, $before[2]], $count(), "only the other save's row and audit row");
        self::assertSame('The other save', $svc->current()['legal_name']);
    }

    public function testConfirmingNeedsTheRequiredDetails(): void
    {
        $owner = $this->staffUser('reviewer');
        $svc = new CompanyDetails(self::$db);
        $e = self::refused(422, 'company_incomplete', fn () => $svc->confirm($owner, 0));
        self::assertSame(['legal name', 'company number', 'registered address', 'VAT number (or "not VAT registered")', 'purchasing e-mail', 'delivery address'],
            $e->detail['missing']);
        self::assertSame('Before the details can be confirmed, fill in: legal name, company number, registered address, VAT number (or "not VAT registered"), '
            . 'purchasing e-mail, delivery address.', $e->getMessage());
        $svc->save($owner, 0, self::details(['vat_registered' => '', 'vat_number' => '', 'trading_name' => '', 'phone' => '']));
        self::assertSame(['VAT number (or "not VAT registered")'], self::refused(422, 'company_incomplete', fn () => $svc->confirm($owner, 1))->detail['missing']);
        $svc->save($owner, 1, self::details(['vat_registered' => 'no', 'vat_number' => '', 'trading_name' => '', 'phone' => '']));
        self::assertSame('confirmed', $svc->confirm($owner, 2)['result'], 'not VAT registered, no trading name, no phone: complete');
        self::assertSame([false, ''], [$svc->company()['vat_registered'], $svc->company()['vat_number']]);

        // A save with a problem writes nothing and lists every problem.
        $e = self::refused(422, 'company_invalid', fn () => $svc->save($owner, 3, self::details(['company_number' => '123', 'email' => 'x'])));
        self::assertSame(['company_number', 'email'], array_keys($e->detail['errors']));
        self::assertSame(3, $svc->current()['version']);

        // A seed copied from the old settings that breaks today's rules (or is not tidy yet) is corrected by a save before it is
        // confirmed (the seed is written as 0013 writes it: its own audit row).
        self::$db->exec('DELETE FROM company_profile');
        self::$db->exec("DELETE FROM audit_log WHERE entity_type = 'company_profile'");
        self::$db->exec("INSERT INTO company_profile (version, kind, legal_name, company_number, vat_registered, vat_number, address, email, delivery_address, saved_actor) "
            . "VALUES (1, 'seed', 'Seeded Ltd', 'sc 123456', 1, 'GB123456782', 'x', 'a@example.co.uk', 'y', 'system:migrate')");
        self::$db->exec("INSERT INTO audit_log (actor, action, entity_type, entity_id, detail) VALUES ('system:migrate', 'company.change', 'company_profile', '1', '{}')");
        self::assertSame([], CompanyDetails::problems($svc->current()), 'valid once tidied ...');
        self::assertTrue(CompanyDetails::unsaved($svc->current()));
        self::assertStringContainsString('save them once', self::refused(422, 'company_unsaved', fn () => $svc->confirm($owner, 1))->getMessage(), '... but not tidy yet');
        self::$db->exec("UPDATE company_profile SET company_number = 'not a number'");
        self::assertFalse(CompanyDetails::unsaved($svc->current()), 'a problem, not "unsaved"');
        self::refused(422, 'company_invalid', fn () => $svc->confirm($owner, 1));
    }

    /**
     * I94: the owner may change and confirm alone; confirming their own change of a watched field asks another reviewer,
     * compared with the last confirmed details, however many saves the change took (review finding: a phone change first, or
     * two watched changes before one confirmation, no longer skip the check).
     */
    public function testConfirmingYourOwnChangeOfAWatchedFieldIsCheckedByAnotherReviewer(): void
    {
        $owner = $this->staffUser('reviewer');
        $second = $this->staffUser('reviewer');
        $buyer = $this->staffUser('buyer');
        $admin = $this->staffUser(['admin', 'auditor']);
        $svc = new CompanyDetails(self::$db);
        $svc->save($owner, 0, self::details());
        $svc->confirm($owner, 1);

        // A save opens nothing (the details are unconfirmed, every PDF says "do not send"); confirming it does.
        $svc->save($owner, 2, self::details(['delivery_address' => "Unit 9, Elsewhere\nBradford BD1 1AA"]), 'moved warehouse');
        self::assertSame(0, self::openChecks());
        $c = $svc->confirm($owner, 3);
        self::assertSame(['confirmed', 4, 2, ['delivery_address']], [$c['result'], $c['version'], $c['baseline_version'], $c['watched_changed']]);
        self::assertNotNull($c['review_task']);
        $task = (array) self::$db->one('SELECT * FROM review_task WHERE id = ?', [$c['review_task']]);
        self::assertSame(['company', 4, 'review', 'company_changed', 'open', $owner->staffUserId, 'company:4:review'], [$task['subject_type'], (int) $task['subject_id'],
            $task['kind'], $task['reason'], $task['state'], (int) $task['opened_by'], $task['open_key']]);
        self::assertSame(7 * 86400, strtotime((string) $task['due_at']) - strtotime((string) $task['opened_at']));
        self::assertSame([$c['review_task'], 2, ['delivery_address']], [self::companyAudits('company.confirm')[1]['review_task'],
            self::companyAudits('company.confirm')[1]['baseline_version'], self::companyAudits('company.confirm')[1]['watched_changed']]);
        self::assertTrue($svc->company()['confirmed'], 'one person may change and confirm (I94): the check stops nothing');

        // What the check shows: the change against the last confirmed details; who may decide it.
        $card = $svc->reviews()[0];
        self::assertSame([2, 1], [$card['baseline_version'], $card['others']]);
        self::assertSame([['field' => 'delivery_address', 'label' => 'delivery address', 'before' => "Unit 4, Example Park\nLeeds LS2 2BB",
            'after' => "Unit 9, Elsewhere\nBradford BD1 1AA", 'watched' => true]], $card['changes']);
        self::assertSame(0, $svc->decidableCount($owner->staffUserId, ['reviewer']), 'not the person who made the change');
        self::assertSame(1, $svc->decidableCount($second->staffUserId, ['reviewer']));
        self::assertSame(0, $svc->decidableCount($buyer->staffUserId, ['buyer']));
        self::assertSame(0, $svc->decidableCount($admin->staffUserId, ['admin', 'auditor']));
        self::assertSame('You made or confirmed this change: another reviewer must check it.',
            self::refused(403, 'own_change', fn () => $svc->decideReview($owner, $c['review_task'], true, null))->getMessage());
        self::refused(403, 'role_not_allowed', fn () => $svc->decideReview($buyer, $c['review_task'], true, null));
        self::refused(403, 'admin_cannot_review', fn () => $svc->decideReview($admin, $c['review_task'], true, null));
        self::refused(400, 'bad_note', fn () => $svc->decideReview($second, $c['review_task'], false, ' x '));
        self::assertSame(3819, self::mysqlError(static fn () => self::$db->exec("UPDATE review_task SET state = 'approved', decided_by = opened_by, decided_at = NOW(6) WHERE id = ?",
            [$c['review_task']])), 'ck_review_task_not_own holds it in SQL too');

        // Approving records the check and changes nothing else.
        self::assertSame(['decision' => 'approved', 'unconfirmed_version' => null, 'orders' => 0], $svc->decideReview($second, $c['review_task'], true, 'rang the warehouse'));
        self::assertSame(['approved', $second->staffUserId, 'rang the warehouse'], array_values(array_intersect_key((array) self::$db->one('SELECT state, decided_by, '
            . 'decision_note FROM review_task WHERE id = ?', [$c['review_task']]), ['state' => 1, 'decided_by' => 1, 'decision_note' => 1])));
        self::assertTrue($svc->company()['confirmed']);
        self::refused(409, 'task_closed', fn () => $svc->decideReview($second, $c['review_task'], true, null));

        // Split saves: the phone first (unconfirmed), then the delivery address and the e-mail in two more saves, one
        // confirmation: compared with version 4 (the last confirmed), both watched changes, one check.
        $svc->save($owner, 4, self::details(['delivery_address' => "Unit 9, Elsewhere\nBradford BD1 1AA", 'phone' => '0113 496 0001']));
        $svc->save($owner, 5, self::details(['delivery_address' => "Unit 99, Diversion Lane\nHull HU9 9ZZ", 'phone' => '0113 496 0001']));
        $svc->save($owner, 6, self::details(['delivery_address' => "Unit 99, Diversion Lane\nHull HU9 9ZZ", 'phone' => '0113 496 0001', 'email' => 'buying@divert.example']));
        $c = $svc->confirm($owner, 7);
        self::assertSame([8, 4, ['email', 'delivery_address']], [$c['version'], $c['baseline_version'], $c['watched_changed']]);
        self::assertNotNull($c['review_task']);
        $card = $svc->reviews()[0];
        self::assertSame([(int) $c['review_task'], 4], [(int) $card['id'], $card['baseline_version']]);
        self::assertSame(['phone' => false, 'email' => true, 'delivery_address' => true], array_column($card['changes'], 'watched', 'field'),
            'every change since version 4 is shown, the watched ones marked');

        // A change confirmed by another person had its second person: no check. Someone who saved a change since the last
        // confirmed details may not decide its check either.
        $third = $this->staffUser('reviewer');
        $svc->save($third, 8, self::details(['delivery_address' => "Unit 99, Diversion Lane\nHull HU9 9ZZ", 'phone' => '0113 496 0001', 'email' => 'buying@divert.example',
            'legal_name' => 'Example Vapes Limited']));
        $byOther = $svc->confirm($second, 9);
        self::assertSame([['legal_name'], null], [$byOther['watched_changed'], $byOther['review_task']]);
        self::assertSame(1, self::openChecks(), 'only the one of version 8');
        $svc->save($second, 10, self::details(['legal_name' => 'Example Vapes Ltd', 'delivery_address' => "Unit 99, Diversion Lane\nHull HU9 9ZZ", 'phone' => '0113 496 0001',
            'email' => 'buying@divert.example']));
        $own = $svc->confirm($second, 11);
        self::assertNotNull($own['review_task'], 'the second reviewer confirms their own change: checked');
        self::assertSame(10, $own['baseline_version']);
        self::refused(403, 'own_change', fn () => $svc->decideReview($second, (int) $own['review_task'], true, null));
        self::assertNull($svc->refusal($owner->staffUserId, ['reviewer'], ['opened_by' => $second->staffUserId, 'subject_id' => 12]), 'the owner was not involved in it');
        self::assertNull($svc->refusal($third->staffUserId, ['reviewer'], (array) self::$db->one('SELECT * FROM review_task WHERE id = ?', [$c['review_task']])),
            'the third reviewer saved nothing between versions 4 and 8: they may check it');
        self::assertSame('own_change', $svc->refusal($third->staffUserId, ['reviewer'], ['opened_by' => $second->staffUserId, 'subject_id' => 10])['code'] ?? null,
            'a person who saved a change since the baseline may not check its confirmation');
        self::assertSame([$owner->staffUserId], $svc->involved(8));
        self::assertSame([$second->staffUserId], $svc->involved(12));
        self::assertSame([$third->staffUserId, $second->staffUserId], $svc->involved(10));

        // The documents' decide paths refuse a company task.
        self::refused(409, 'company_task', fn () => $this->docs->approve($second, (int) $c['review_task'], null));
    }

    /**
     * I98: a rejected change unconfirms the details in use that carry it; the people involved cannot confirm it again (another
     * reviewer can, which lifts the rejection); approved orders that carry it are flagged in sendWarnings, their PDF and the
     * Company details page.
     */
    public function testARejectedChangeCannotBeConfirmedAgainByItsPeopleAndFlagsItsOrders(): void
    {
        $owner = $this->staffUser('reviewer');
        $second = $this->staffUser('reviewer');
        $buyer = $this->staffUser('buyer');
        $svc = new CompanyDetails(self::$db);
        $svc->save($owner, 0, self::details());
        $svc->confirm($owner, 1);
        $good = $this->approvedPo($buyer)['doc'];

        // The owner diverts the delivery address and confirms it; an order is approved with it before anyone checks.
        $svc->save($owner, 2, self::details(['delivery_address' => "Unit 66, Somewhere Odd\nHull HU1 1AA", 'email' => 'buying@elsewhere.example']));
        $c = $svc->confirm($owner, 3);
        self::assertNotNull($c['review_task']);
        $diverted = $this->approvedPo($buyer)['doc'];
        self::assertSame([], $this->pos->sendWarnings($diverted->id), 'nothing known yet');

        // Rejected: the details in use carry it, so they are unconfirmed (version kind unconfirm); the order is flagged.
        self::assertSame(['decision' => 'rejected', 'unconfirmed_version' => 5, 'orders' => 1], $svc->decideReview($second, (int) $c['review_task'], false, 'not our warehouse'));
        $p = $svc->current();
        self::assertSame([5, 'unconfirm', false, "Unit 66, Somewhere Odd\nHull HU1 1AA"], [$p['version'], $p['kind'], $p['confirmed'], $p['delivery_address']]);
        self::assertSame('a reviewer rejected the change confirmed in version 4: not our warehouse', $p['reason']);
        self::assertSame([[(int) $c['review_task'], 4, 'rejected', 'not our warehouse', 5]], array_map(static fn (array $a): array => [$a['task'], $a['version'],
            $a['decision'], $a['note'], $a['unconfirmed_version']], self::companyAudits('company.review')));
        $orders = $svc->ordersWithRejectedDetails();
        self::assertSame([[$diverted->id, (int) $c['review_task']]], array_map(static fn (array $o): array => [$o['document_id'], $o['task']], $orders));
        self::assertSame(['email' => 'buying@elsewhere.example', 'delivery_address' => "Unit 66, Somewhere Odd\nHull HU1 1AA"],
            $svc->rejectedIn(json_decode((string) $this->poRow($diverted->id)['company_snapshot'], true))['values'] ?? null);
        self::assertNull($svc->rejectedIn(json_decode((string) $this->poRow($good->id)['company_snapshot'], true)));
        self::assertContains('The company details it was approved with include a change a reviewer rejected (see Company details): cancel or amend it rather than send it.',
            $this->pos->sendWarnings($diverted->id));
        self::assertSame([], $this->pos->sendWarnings($good->id));
        $data = $this->pos->pdfData($diverted->id);
        self::assertTrue($data['company_rejected']);
        self::assertStringContainsString("(COMPANY DETAILS REJECTED AT REVIEW \x97 DO NOT SEND) Tj", (new PurchaseOrderPdf(false))->render($data));
        self::assertFalse($this->pos->pdfData($good->id)['company_rejected']);

        // The person who made the change cannot confirm it again (not even after another unrelated change) ...
        $count = static fn (): int => (int) self::$db->value('SELECT COUNT(*) FROM company_profile');
        $before = $count();
        $e = self::refused(403, 'rejected_change', fn () => $svc->confirm($owner, 5));
        self::assertStringContainsString('A reviewer rejected this change of the purchasing e-mail and delivery address (reviewer 2: "not our warehouse")', $e->getMessage());
        $svc->save($owner, 5, self::details(['delivery_address' => "Unit 66, Somewhere Odd\nHull HU1 1AA", 'email' => 'buying@example.co.uk']));
        self::refused(403, 'rejected_change', fn () => $svc->confirm($owner, 6), 'the rejected delivery address alone');
        self::assertSame($before + 1, $count());
        // ... but may confirm the details once the rejected values are gone: compared with version 2 (the last confirmed
        // details that carry nothing rejected), nothing watched changed, no check.
        $svc->save($owner, 6, self::details());
        $back = $svc->confirm($owner, 7);
        self::assertSame([2, [], null], [$back['baseline_version'], $back['watched_changed'], $back['review_task']]);
        self::assertSame(1, count($svc->ordersWithRejectedDetails()), 'the order still carries it: cancel or amend it');

        // Another reviewer may confirm the rejected values after all (they decided it is right): that lifts the rejection.
        $svc->save($owner, 8, self::details(['delivery_address' => "Unit 66, Somewhere Odd\nHull HU1 1AA", 'email' => 'buying@elsewhere.example']));
        self::refused(403, 'rejected_change', fn () => $svc->confirm($owner, 9));
        $lift = $svc->confirm($second, 9);
        self::assertSame(['confirmed', null], [$lift['result'], $lift['review_task']], 'the owner made it, the second reviewer confirmed it: two people');
        self::assertSame([], $svc->ordersWithRejectedDetails());
        self::assertSame([], $this->pos->sendWarnings($diverted->id));

        // A rejection whose change the details in use no longer carry changes nothing else.
        $svc->save($owner, 10, self::details(['delivery_address' => "Unit 66, Somewhere Odd\nHull HU1 1AA", 'email' => 'orders@example.co.uk']));
        $c = $svc->confirm($owner, 11);
        self::assertSame([12, 10, ['email']], [$c['version'], $c['baseline_version'], $c['watched_changed']]);
        $svc->save($owner, 12, self::details(['delivery_address' => "Unit 66, Somewhere Odd\nHull HU1 1AA", 'email' => 'buying@elsewhere.example']));
        self::assertNull($svc->confirm($second, 13)['review_task']);
        self::assertSame(['decision' => 'rejected', 'unconfirmed_version' => null, 'orders' => 0], $svc->decideReview($second, (int) $c['review_task'], false, 'wrong e-mail'));
        self::assertTrue($svc->company()['confirmed']);
        self::assertSame(14, $svc->current()['version']);
    }

    public function testWhoMayChangeAndConfirm(): void
    {
        $svc = new CompanyDetails(self::$db);
        foreach ([[$this->staffUser('buyer'), 'role_not_allowed', 'Your role (buyer) cannot change the company details.'],
            [$this->staffUser(['purchasing_manager', 'auditor']), 'role_not_allowed', 'Your roles (auditor, purchasing_manager) cannot change the company details.'],
            [$this->staffUser(['admin', 'viewer']), 'admin_cannot_edit', 'Admin manages people and roles only: a reviewer adds, changes and confirms the company details.'],
            [$this->staffUser(['admin', 'reviewer']), 'admin_cannot_edit', null], // only admin SQL writes such a set: read fail-closed
            [$this->staffUser('reviewer', false), 'staff_not_allowed', null],
            [Caller::system('test'), 'staff_required', 'The company details are changed by staff on the Company details screen.']] as [$who, $code, $message]) {
            $e = self::refused(403, $code, fn () => $svc->save($who, 0, self::details()));
            if ($message !== null) {
                self::assertSame($message, $e->getMessage());
            }
            self::refused(403, $code, fn () => $svc->confirm($who, 0));
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM company_profile'));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'company.%'"));
    }

    public function testAPostedOrderKeepsTheDetailsItWasApprovedWith(): void
    {
        $buyer = $this->staffUser('buyer');
        $owner = $this->staffUser('reviewer');
        $svc = new CompanyDetails(self::$db);
        $svc->save($owner, 0, self::details());

        $unconfirmed = $this->approvedPo($buyer)['doc'];
        $svc->confirm($owner, 1);
        $confirmed = $this->approvedPo($buyer);
        $draft = $this->draftPo($buyer, (int) $confirmed['supplier']['id'], [['supplier_item_id' => (int) $confirmed['si']['id'], 'packs' => 1]]);
        $snap = json_decode((string) $this->poRow($confirmed['doc']->id)['company_snapshot'], true);
        self::assertSame(['Example Vapes Ltd', 'GB123456782', true, true, 2], [$snap['legal_name'], $snap['vat_number'], $snap['vat_registered'], $snap['confirmed'],
            $snap['version']]);
        self::assertFalse(json_decode((string) $this->poRow($unconfirmed->id)['company_snapshot'], true)['confirmed']);
        self::assertSame([], $this->pos->sendWarnings($confirmed['doc']->id));
        self::assertSame(['The company details it was approved with are not confirmed: its PDF says "company details not confirmed - do not send".'],
            $this->pos->sendWarnings($unconfirmed->id));

        // The details change (unconfirmed again): the approved order keeps what it was approved with; the draft prints the new ones.
        $svc->save($owner, 2, self::details(['legal_name' => 'Renamed Vapes Ltd']));
        $posted = $this->pos->pdfData($confirmed['doc']->id);
        self::assertSame(['Example Vapes Ltd', true, false], [$posted['company']['legal_name'], $posted['company']['confirmed'], $posted['company_rejected']]);
        $d = $this->pos->pdfData($draft->id);
        self::assertSame(['Renamed Vapes Ltd', false, 3], [$d['company']['legal_name'], $d['company']['confirmed'], $d['company']['version']]);
        $pdf = (new PurchaseOrderPdf(false))->render($d);
        self::assertStringContainsString('(Renamed Vapes Ltd) Tj', $pdf);
        self::assertStringContainsString('(VAT no. GB 123 4567 82) Tj', $pdf);
        self::assertStringContainsString("(COMPANY DETAILS NOT CONFIRMED \x97 DO NOT SEND) Tj", $pdf);
        self::assertStringNotContainsString('NOT CONFIRMED', (new PurchaseOrderPdf(false))->render($posted));
    }

    /**
     * The app login can add versions and read them, never rewrite or remove one (Grants::APPEND_ONLY). A version it makes up
     * (a forged confirmation, a jump to the top of the version range) is found by the invariants C1-C4; the jump also stops
     * the next save with a plain message instead of a database error.
     */
    public function testTheAppLoginAddsVersionsButNeverRewritesThemAndAMadeUpOneIsFound(): void
    {
        $config = TestDb::config();
        $user = $config->appDbUser();
        self::assertNotNull($user);
        \CW\Schema\Grants::apply(self::$db, TestDb::name(), $user);
        $app = Db::connect($config->dbApp()->withDatabase(TestDb::name()));
        $owner = $this->staffUser('reviewer');
        $second = $this->staffUser('reviewer');
        $buyer = $this->staffUser('buyer');
        $svc = new CompanyDetails($app);
        $svc->save($owner, 0, self::details());
        $svc->confirm($owner, 1);
        $svc->save($owner, 2, self::details(['vat_registered' => 'no', 'vat_number' => '']));
        $c = $svc->confirm($owner, 3);
        $svc->decideReview($second, (int) $c['review_task'], false, 'we are VAT registered');
        self::assertSame([5, false], [$svc->current()['version'], $svc->current()['confirmed']]);
        foreach (["UPDATE company_profile SET confirmed = 1", "UPDATE company_profile SET legal_name = 'x' WHERE version = 1", 'DELETE FROM company_profile',
            'DELETE FROM company_profile WHERE version = 5'] as $sql) {
            self::assertSame(1142, self::mysqlError(static fn () => $app->exec($sql)), $sql);
        }
        self::assertSame(5, (int) self::$db->value('SELECT COUNT(*) FROM company_profile'));
        self::assertSame([], Invariants::check(self::$db));

        // A made-up confirmation added with the app login: other details, confirmed in the second reviewer's name, saved by a
        // buyer, no audit row. Every check names it.
        $app->exec("INSERT INTO company_profile (version, kind, legal_name, company_number, vat_registered, vat_number, address, email, delivery_address, confirmed, "
            . "confirmed_by, confirmed_actor, confirmed_at, saved_by, saved_actor) VALUES (6, 'confirm', 'Example Vapes Ltd', '01234567', 1, 'GB123456782', 'x', "
            . "'buying@attacker.example', 'Lock-up 7, Nowhere Road', 1, ?, ?, NOW(6), ?, ?)", [$second->staffUserId, $second->actor, $buyer->staffUserId, $buyer->actor]);
        $v = CompanyInvariants::check(self::$db);
        foreach (['company details version 6 (confirm) differs from version 5 in', 'company details version 6 was confirmed by ' . $second->actor . ' but saved by '
            . $buyer->actor, 'company details version 6 (confirm) has 0 company.confirm audit rows (expected 1)', "company details version 6 (confirm) was saved by staff "
            . $buyer->staffUserId . ', who did not hold company.confirm'] as $expected) {
            self::assertNotEmpty(array_filter($v, static fn (string $x): bool => str_starts_with($x, $expected)), $expected . ' in ' . implode(' | ', $v));
        }
        self::$db->exec('DELETE FROM company_profile WHERE version = 6');

        // A jump to the top of the range: the next save is refused with a message, not a database error; C1 names the gap.
        $app->exec("INSERT INTO company_profile (version, kind, saved_by, saved_actor) VALUES (4294967295, 'change', ?, ?)", [$owner->staffUserId, $owner->actor]);
        self::assertContains('company details: versions 1..4294967295 in 6 rows (expected 1..6, no gap)', CompanyInvariants::check(self::$db));
        self::refused(500, 'company_versions_broken', fn () => $svc->save($owner, 4294967295, self::details()));
        self::$db->exec('DELETE FROM company_profile WHERE version = 4294967295');
        self::assertSame([], CompanyInvariants::check(self::$db));
    }
}
