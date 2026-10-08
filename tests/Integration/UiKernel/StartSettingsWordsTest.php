<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Company\CompanyDetails;
use CW\Documents\Documents;
use CW\Tests\Support\Documents\FixtureAdjustmentHandler;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The start and settings pages in plain words (plan §6.3-6.8, 6.13, 6.31-6.36, rules 2, 4, 10, 11, 14): sign-in, password,
 * Things to check, All records, one record, Company details, Settings and lists, the two lists, Staff and access and one staff
 * member, for the jobs that open them. Each has its title and its one-sentence intro, no code, no "UTC", no phase code, no
 * server command or docs path, no "Your role (…)", and every table turns into cards on a phone.
 */
final class StartSettingsWordsTest extends KernelUiTestCase
{
    /** @param array<string, mixed> $user @return array<string, mixed> the user with a person's name instead of the role codes */
    private function plainNames(array $user, string $name): array
    {
        // Test accounts are named after their role codes; a person on staging has a name, not a code.
        $user['email'] = strtolower(str_replace(' ', '.', $name)) . '@test.example';
        self::$db->exec('UPDATE staff_user SET display_name = ?, email = ? WHERE id = ?', [$name, $user['email'], $user['id']]);
        return $user;
    }

    public function testThePagesSpeakPlainWordsAndTurnIntoCardsOnAPhone(): void
    {
        $poster = $this->uiUser(['stock_controller', 'reviewer']);
        $poster = $this->plainNames($poster, 'Sam Poster');
        $docs = new Documents(self::$db, ['ADJ' => new FixtureAdjustmentHandler(self::$db)]);
        $who = Caller::staff($poster['id']);
        $sku = self::makeSku('Plain item');
        $d = $docs->createDraft($who, 'ADJ', ['external_ref' => 'SUP-1']);
        $d = $docs->setLines($who, $d->id, $d->version, [['sku_id' => $sku, 'qty' => 3, 'unit_cost' => '1.875', 'reason_code' => 'found']]);
        $doc = $docs->post($who, $d->id, $d->version);
        $held = $docs->createDraft($who, 'ADJ', []);
        $held = $docs->post($who, $held->id, $docs->setLines($who, $held->id, $held->version, [['sku_id' => $sku, 'qty' => 25]])->version);
        $reviewer = $this->uiUser('reviewer');
        $reviewer = $this->plainNames($reviewer, 'Rita Reviewer');
        (new CompanyDetails(self::$db))->save(Caller::staff($reviewer['id']), 0, ['legal_name' => 'Example Vapes Ltd', 'trading_name' => '', 'company_number' => '01234567',
            'address' => "1 High Street\nLeeds", 'vat_registered' => 'yes', 'vat_number' => 'GB 123 4567 82', 'phone' => '', 'email' => 'b@example.co.uk',
            'delivery_address' => "Unit 4\nLeeds"]);
        $admin = $this->uiUser('admin');
        $admin = $this->plainNames($admin, 'Fazil Admin');
        $owner = $this->uiUser(['reviewer', 'mapping_lead']);
        $owner = $this->plainNames($owner, 'Olga Owner');
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'admin')", [$owner['id']]); // as on staging
        $auditor = $this->uiUser('auditor');
        $auditor = $this->plainNames($auditor, 'Ann Auditor');
        $buyer = $this->uiUser('buyer');
        $buyer = $this->plainNames($buyer, 'Ben Buyer');

        $pages = [
            [$reviewer, '/ui/documents/reviews', 'Waiting for me', 'reviews'],
            [$reviewer, '/ui/documents', 'History', 'documents'],
            [$reviewer, '/ui/documents/' . $doc->id, 'Stock correction ADJ-000001', 'document'],
            [$reviewer, '/ui/documents/' . $held->id, 'Stock correction (no number yet)', 'document'],
            [$reviewer, '/ui/reference/company', 'Company', 'company'],
            [$reviewer, '/ui/reference/company/edit', 'Change the company details', 'company_edit'],
            [$reviewer, '/ui/reference/settings', 'Settings', 'settings'],
            [$reviewer, '/ui/reference/reasons', 'Reasons', 'reasons'],
            [$reviewer, '/ui/reference/series', 'Numbering', 'series'],
            [$reviewer, '/ui/password', 'Change password', 'password'],
            [$buyer, '/ui/reference/company', 'Company', 'company'],
            [$buyer, '/ui/documents', 'History', 'documents'],
            [$owner, '/ui/reference/company', 'Company', 'company'],
            [$admin, '/ui/people', 'Users', 'people'],
            [$admin, '/ui/people/' . $owner['id'], 'Olga Owner', 'person'],
            [$admin, '/ui/people/' . $buyer['id'], 'Ben Buyer', 'person'],
            [$auditor, '/ui/people', 'Users', null],
            [$auditor, '/ui/people/' . $buyer['id'], 'Ben Buyer', null],
            [$auditor, '/ui/documents', 'History', 'documents'],
        ];
        $browsers = [];
        foreach ($pages as [$user, $path, $title, $intro]) {
            $web = $browsers[$user['id']] ??= $this->signIn($user);
            $page = $web->get($path);
            $where = "{$path} as " . implode('+', $user['roles']);
            self::assertSame(200, $page->status, $where . ': ' . $page->describe());
            self::check($page, $where, $title, $intro);
        }
        $login = $this->browser()->get('/ui/login');
        self::check($login, '/ui/login', Words::UI['brand'], 'login');
    }

    /** One page: its title, its intro, no codes or technical words, tables as cards. */
    private static function check(UiResponse $page, string $where, string $title, ?string $intro): void
    {
        $dom = $page->dom();
        $xp = new \DOMXPath($dom);
        self::assertSame($title, trim((string) $xp->evaluate('string(//main//h1)')), "{$where}: the title");
        if ($intro !== null) {
            self::assertStringStartsWith(Words::PAGE_INTRO[$intro][0], trim((string) $xp->evaluate('string(//main//p[@class="lede"])')), "{$where}: the intro (plan §4)");
        } else {
            self::assertNotSame('', trim((string) $xp->evaluate('string(//main//p[@class="lede"])')), "{$where}: an intro for a person who can only look");
        }
        foreach ($xp->query('//main//table') ?: [] as $table) {
            /** @var \DOMElement $table */
            self::assertMatchesRegularExpression('/\bstack\b/', $table->getAttribute('class'), "{$where}: a table that turns into cards on a phone");
        }
        // What a person reads: codes kept on purpose for the developer or the files are in <code> (plan §1.9), e-mails are data.
        foreach (iterator_to_array($xp->query('//main//code | //main//*[contains(@class, "role-code")]') ?: []) as $kept) {
            $kept->parentNode?->removeChild($kept);
        }
        $main = $xp->query('//main')->item(0);
        $text = (string) preg_replace('/\S+@\S+/', '', trim((string) preg_replace('/\s+/u', ' ', (string) $main?->textContent)));
        self::assertDoesNotMatchRegularExpression('/\b[a-z]+_[a-z_]+\b/', $text, "{$where}: a code on the screen (rule 2)");
        foreach (['UTC', 'Phase I-', 'bin/', 'docs/', 'decision ', 'Your role', 'Your roles', 'provisional', 'posted', 'second account'] as $word) {
            self::assertStringNotContainsString($word, $text, "{$where}: \"{$word}\"");
        }
    }
}
