<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\KeySample;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The matching pages in plain words (plan §6.9-6.12, 6.14-6.18, rules 2, 4, 10, 11, 14): the lists, one website product, the
 * second OK, the spot checks and one spot check, possible duplicates and one group, Find a product and the product page's
 * matching and stock parts, for a Matcher, a Matching lead, a look-only job and the owner's account with Admin. Each has its
 * title and its one-sentence intro (for a person who can only look: who changes it), no code, no "UTC", no phase code, no
 * server command, no "Your role (…)", no "proposal" or "Key queue", and every table turns into cards on a phone (a comparison
 * that keeps its columns scrolls in its own box). Codes kept for the team are only in "Technical details" and <code>.
 */
final class MatchingWordsTest extends KernelUiTestCase
{
    use KeyFixtures;

    public function testTheMatchingPagesSpeakPlainWordsAndTurnIntoCardsOnAPhone(): void
    {
        $this->keySetup();
        $owner = $this->uiUser(['mapping_lead', 'reviewer']);
        self::$db->exec('UPDATE staff_user SET display_name = ? WHERE id = ?', ['Olga Owner', $owner['id']]);
        $skus = [];
        for ($i = 0; $i < 22; $i++) {
            $skus[] = $this->firstMatch($this->vpgItem("Acme Bar {$i} 20mg"), 90 + $i % 10);
        }
        $s = (new KeySample(self::$db))->create(Caller::staff($owner['id']), 'words-1', 20, true);
        $member = $s['members'][0];
        $check = $this->firstMatch($this->vpgItem('Acme Pod Mango 10mg'), 87, [], ['fields_not_agree' => ['flavour' => 'conflict']], 'Check');
        // A decision that waits for a second OK (1 sale is not 1 product).
        $this->ds->decide($this->staffUser('mapper'), ['action' => 'link', 'listing_id' => $check['listing'], 'sku_id' => $check['sku'], 'units_per_item' => 2,
            'proposal_id' => $check['proposal'], 'expected_map_version' => $this->version($check['listing'])]);
        // A group of possible duplicates on Vape and Go (M34).
        $a = $this->vpgItem('Acme Cola 10ml 10mg');
        $b = $this->vpgItem('Acme Cola 10ml 20mg');
        $la = self::vpgListingOf($a);
        $lb = self::vpgListingOf($b);
        $va = (string) self::$db->value('SELECT external_variant_id FROM channel_listing WHERE id = ?', [$la]);
        $vb = (string) self::$db->value('SELECT external_variant_id FROM channel_listing WHERE id = ?', [$lb]);
        $group = $this->propose($lb, 'Manual', $a, ['lane' => 'vpg_duplicate', 'evidence' => ['group' => 4, 'kind' => 'identity_key',
            'keeper' => ['vpg_variant_id' => $va, 'sku_id' => $a], 'members' => [['vpg_variant_id' => $va], ['vpg_variant_id' => $vb]]]], false, 'dups');

        $mapper = $this->uiUser('mapper');
        $viewer = $this->uiUser('viewer');
        $admin = $this->uiUser(['mapping_lead', 'reviewer']);
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'admin')", [$admin['id']]); // the owner's account on staging
        // Test accounts are named after their role codes; a person on staging has a name, not a code.
        self::$db->exec("UPDATE staff_user SET display_name = CONCAT('Person ', id) WHERE display_name LIKE '%\\_%'");
        $title = static fn (int $listing): string => (string) self::$db->value('SELECT product_title FROM listing_profile WHERE listing_id = ?', [$listing]);

        // A website product in someone else's spot check says it is for looking (R2 review, 7 Oct); the draw decides whether this one is.
        $spotIntro = in_array((int) $skus[21]['listing'], array_map('intval', array_column($s['members'], 'listing_id')), true) ? 'listing_spot' : 'listing';
        $pages = [
            [$mapper, '/ui/review?queue=Key', Words::BAND_TITLE['Key'], 'queue_Key'],
            [$mapper, '/ui/review?queue=Check', Words::BAND_TITLE['Check'], 'queue_Check'],
            [$mapper, '/ui/review?queue=Conflict', Words::BAND_TITLE['Conflict'], 'queue_Conflict'],
            [$mapper, '/ui/review/listing/' . $skus[21]['listing'] . '?queue=Key', $title($skus[21]['listing']), $spotIntro],
            [$mapper, '/ui/review/listing/' . $check['listing'], $title($check['listing']), 'listing'],
            [$mapper, '/ui/review?queue=pending', Words::PAGE_TITLE['pending'], 'pending'],
            [$mapper, '/ui/review/samples', Words::PAGE_TITLE['samples'], 'samples'],
            [$mapper, '/ui/search?q=Acme', Words::PAGE_TITLE['search'], 'search'],
            [$mapper, '/ui/items/' . $a, 'Acme Cola 10ml 10mg (' . self::$db->value('SELECT code FROM sku WHERE id = ?', [$a]) . ')', 'item'],
            [$owner, '/ui/review/samples/' . $s['sample_id'], Words::say('SAMPLE', 'title', 'words-1'), 'sample'],
            [$owner, '/ui/review/listing/' . $member['listing_id'] . '?sample=' . $s['sample_id'], $title($member['listing_id']), 'listing'],
            [$owner, '/ui/review/duplicates', Words::MENU['duplicates'], 'duplicates'],
            [$owner, '/ui/review/duplicates/' . $group, Words::say('DUPS', 'title', 2), 'duplicate_group'],
            [$owner, '/ui/review?queue=pending', Words::PAGE_TITLE['pending'], 'pending'],
            [$viewer, '/ui/review?queue=Key', Words::BAND_TITLE['Key'], null],
            [$viewer, '/ui/review/listing/' . $skus[21]['listing'], $title($skus[21]['listing']), null],
            [$viewer, '/ui/review/duplicates/' . $group, Words::say('DUPS', 'title', 2), null],
            [$admin, '/ui/review/listing/' . $check['listing'], $title($check['listing']), null],
            [$admin, '/ui/review/duplicates', Words::MENU['duplicates'], null],
        ];
        $browsers = [];
        foreach ($pages as $n => [$user, $path, $h1, $intro]) {
            $web = $browsers[$user['id']] ??= $this->signIn($user, $this->browser('198.51.100.' . (40 + count($browsers))));
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $page = $web->get((string) parse_url($path, PHP_URL_PATH), array_map('strval', $query));
            $where = "{$path} as " . implode('+', $user['roles']) . " (#{$n})";
            self::assertSame(200, $page->status, $where . ': ' . $page->describe());
            self::check($page, $where, $h1, $intro);
        }
    }

    /** One page: its title, its intro, no codes or technical words, tables as cards. */
    private static function check(UiResponse $page, string $where, string $title, ?string $intro): void
    {
        $xp = new \DOMXPath($page->dom());
        self::assertSame($title, trim((string) preg_replace('/\s+/u', ' ', (string) $xp->evaluate('string(//main//h1)'))), "{$where}: the title");
        $lede = trim((string) $xp->evaluate('string(//main//p[@class="lede"])'));
        if ($intro !== null) {
            self::assertStringStartsWith(Words::PAGE_INTRO[$intro][0], $lede, "{$where}: the intro (plan §4)");
        } else {
            self::assertStringContainsString('You can look;', $lede, "{$where}: who changes it, for a person who can only look");
        }
        foreach ($xp->query('//main//table') ?: [] as $table) {
            /** @var \DOMElement $table */
            $inScroll = $table->parentNode instanceof \DOMElement && str_contains(' ' . $table->parentNode->getAttribute('class') . ' ', ' scroll ');
            $mine = preg_match('/\bitem-suppliers\b|\bproposals\b|\bbarcodes\b/', $table->getAttribute('class')) !== 1; // the product card's and buying's tables
            self::assertTrue(!$mine || $inScroll || preg_match('/\bstack\b/', $table->getAttribute('class')) === 1, "{$where}: a table that turns into cards on a phone");
        }
        // What a person reads: codes kept on purpose are in <code> or "Technical details"; the product card block is IM3's.
        foreach (iterator_to_array($xp->query('//main//code | //main//details[contains(@class, "tech-details")] | //main//section[@id="card"] '
            . '| //main//section[@id="barcodes"] | //main//section[@aria-labelledby="suppliers-h"]') ?: []) as $kept) {
            $kept->parentNode?->removeChild($kept);
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $xp->query('//main')->item(0)?->textContent));
        self::assertDoesNotMatchRegularExpression('/\b[a-z]+_[a-z_]+\b/', $text, "{$where}: a code on the screen (rule 2)");
        foreach (['UTC', 'Phase I-', 'bin/', 'docs/', 'Your role', 'Your roles', 'proposal', 'Proposal', 'Key queue', 'spot-check', 'mapping lead', 'Second approval',
            'per item', 'Relabel', 'veto', 'judge', 'Sell policy', 'Identity card'] as $word) {
            self::assertStringNotContainsString($word, $text, "{$where}: \"{$word}\"");
        }
    }
}
