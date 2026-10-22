<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Mapping\LandingRule;
use CW\Mapping\SiteState;
use PHPUnit\Framework\TestCase;

/**
 * The landing-page rule (docs/decisions.md LP2-LP3; CW\Mapping\LandingRule), pure: the website's own flag first (yes and no), then
 * the rule, which must be CERTAIN, and which never calls a real product with one option a landing page. The rows are the shops' own
 * shapes (Electrofag's "Blue Razz Cherry Elux Legend Nic Salt 10ml", options 958 / 959 and the landing page 960; the prompts of Vape
 * and Go's landing rows).
 */
final class LandingRuleTest extends TestCase
{
    private const TITLE = 'Blue Razz Cherry Elux Legend Nic Salt 10ml';

    /** @return array<string, mixed> one profile as listing_profile holds it @param array<string, mixed> $over */
    private static function row(int $id, ?string $option, array $over = []): array
    {
        $attrs = [['attr_id' => 15, 'name' => 'VG/PG', 'value' => '50/50', 'is_variable' => 0], ['attr_id' => 28, 'name' => 'Bottle Size', 'value' => '10ml', 'is_variable' => 0]];
        if ($option !== null) {
            $attrs[] = ['attr_id' => 4, 'name' => 'Strength', 'value' => $option, 'is_variable' => 1];
        }
        return $over + ['listing_id' => $id, 'product_title' => self::TITLE, 'variant_title' => $option === null ? self::TITLE : self::TITLE . ' - ' . $option,
            'attributes' => json_encode(['items' => $attrs]), 'barcodes' => $option === null ? '[]' : '["5061026754150"]',
            'perma_link' => 'blue-razz-cherry-elux-legend-nic-salt-10ml' . ($option === null ? '' : '/' . strtolower($option)), 'site_product_id' => null,
            'website' => [], 'state_fresh' => false];
    }

    /** @param array<string, mixed> $over @return array<string, mixed> a stored state that says $landing */
    private static function stated(array $row, bool $landing, bool $fresh = true): array
    {
        return ['website' => ['source' => SiteState::STORED, 'as_of' => '2026-10-22 09:00:00.000000', 'is_landing' => $landing, 'product_id' => '206'],
            'state_fresh' => $fresh] + $row;
    }

    public function testTheLandingPageOfAPageWithOptionsIsOneAndItsOptionsAreNot(): void
    {
        [$mg10, $mg20, $page] = [self::row(958, '10mg'), self::row(959, '20mg'), self::row(960, null)];
        $d = LandingRule::decide($page, [$mg10, $mg20]);
        self::assertTrue($d['landing']);
        self::assertSame(LandingRule::SAME_PAGE, $d['reason']);
        self::assertSame(['rule' => 'lp1', 'options' => [958, 959], 'options_count' => 2, 'page' => 'web_address'], $d['evidence']);
        self::assertSame(['has_barcode', 'option_title', 'option_value'], LandingRule::decide($mg10, [$mg20, $page])['not']);
        self::assertSame('product_id', LandingRule::decide(['site_product_id' => '206'] + $page, [$mg10, $mg20])['evidence']['page']);
    }

    public function testARealProductWithOneOptionIsNeverALandingPage(): void
    {
        // no other option on its page: a simple product, its title its product's, no option value, no barcode
        $single = self::row(10, null, ['product_title' => 'Voopoo Doric Galaxy Replacement Pods 2ml', 'variant_title' => 'Voopoo Doric Galaxy Replacement Pods 2ml',
            'perma_link' => 'voopoo-doric-galaxy-replacement-pods-2ml-2-pack/', 'attributes' => null]);
        self::assertSame(['no_option_sibling'], LandingRule::decide($single, [])['not']);
        // a product of ANOTHER title whose address hangs under it is not one of its options (Vape and Go's measured false positive)
        $other = self::row(11, 'Blue', ['product_title' => 'VooPoo Doric Galaxy Pods 2 Pack', 'variant_title' => 'VooPoo Doric Galaxy Pods 2 Pack - Blue',
            'perma_link' => 'voopoo-doric-galaxy-replacement-pods-2ml-2-pack/blue']);
        self::assertFalse(LandingRule::decide($single, [$other])['landing']);
        // other rows of the page that are not options (no option of their own) do not make it one either
        self::assertFalse(LandingRule::decide(self::row(960, null), [self::row(961, null, ['barcodes' => '[]'])])['landing']);
        // its own barcode, its own option words, a real option value: each alone keeps it a product
        $page = self::row(960, null);
        $opts = [self::row(958, '10mg')];
        self::assertSame(['has_barcode'], LandingRule::decide(['barcodes' => '["5061026754150"]'] + $page, $opts)['not']);
        self::assertSame(['option_title'], LandingRule::decide(['variant_title' => self::TITLE . ' - 10mg'] + $page, $opts)['not']);
        self::assertSame(['option_value'], LandingRule::decide(['attributes' => ['items' => [['name' => 'Strength', 'value' => '20mg', 'is_variable' => 1]]]] + $page, $opts)['not']);
    }

    public function testThePagesPromptIsNoValueAndUnmarkedAttributesLeaveItUndecided(): void
    {
        $page = self::row(7222, null, ['attributes' => ['items' => [['name' => 'Flavour', 'value' => 'Choose a Flavour', 'is_variable' => 1],
            ['name' => 'Nicotine Strength', 'value' => '20mg', 'is_variable' => 0]]]]);
        self::assertTrue(LandingRule::decide($page, [self::row(958, '10mg')])['landing'], '"Choose a Flavour" is the page\'s prompt, not a value');
        foreach (['Choose an Option', 'Choose A Colour', 'choose an option', 'Select', 'Please select', '-', ''] as $prompt) {
            self::assertTrue(LandingRule::prompt($prompt), $prompt);
        }
        foreach (['10mg', 'Blue Razz', 'Chosen Berry', 'Selection Box'] as $value) {
            self::assertFalse(LandingRule::prompt($value), $value);
        }
        // attributes of another shape (name => value, no mark of which one the customer chooses): the rule cannot tell
        self::assertSame(['attributes_unmarked'], LandingRule::decide(['attributes' => ['Strength' => '']] + self::row(960, null), [self::row(958, '10mg')])['not']);
    }

    public function testTheWebsitesOwnWordComesFirstWhenItIsFresh(): void
    {
        $page = self::row(960, null);
        $opts = [self::row(958, '10mg'), self::row(959, '20mg')];
        $flag = LandingRule::decide(self::stated(['barcodes' => '["1"]'] + $page, true), []);
        self::assertSame([true, LandingRule::WEBSITE_FLAG], [$flag['landing'], $flag['reason']], 'the website says so: no rule is asked');
        self::assertSame(['landing' => false, 'reason' => null, 'not' => ['website_says_no'], 'evidence' => []], LandingRule::decide(self::stated($page, false), $opts),
            'a fresh "not a landing row" is the website\'s word too');
        self::assertSame(LandingRule::SAME_PAGE, LandingRule::decide(self::stated($page, false, false), $opts)['reason'], 'an old state is not known: the rule decides');
        self::assertFalse(LandingRule::decide(self::stated(['barcodes' => '["1"]'] + $page, true, false), $opts)['landing'], 'an old flag is not relied on');
    }

    public function testTheAddresses(): void
    {
        self::assertSame('brand-product', LandingRule::parentAddress(' Brand-Product/10MG/ '));
        self::assertNull(LandingRule::parentAddress('brand-product/'));
        self::assertNull(LandingRule::parentAddress(null));
        self::assertSame('brand-product', LandingRule::address('Brand-Product/'));
        self::assertNull(LandingRule::address('  '));
    }
}
