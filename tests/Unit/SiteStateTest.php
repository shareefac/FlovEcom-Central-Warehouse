<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Clock;
use CW\Mapping\SiteState;
use CW\Matching\Normalizer;
use PHPUnit\Framework\TestCase;

/**
 * The website state of a variant (docs/decisions.md M66; CW\Mapping\SiteState), the parts that need no database: what an export
 * line and a pushed `website` object give (read leniently: a wrong type is ignored and counted, never refused), the order of the
 * readers (the stored columns, then the features, then a suggestion's record), the age against the setting, and the page heading,
 * which is the Normalizer's own answer and "not known" whenever one of its inputs is missing. "Not known" is a state of its own
 * everywhere: nothing here turns a missing value into "on sale" or into "not a page heading". And what the features and a
 * suggestion's record say AGAINST a variant beside its stored state (the review of build 2a, F1).
 */
final class SiteStateTest extends TestCase
{
    private const AT = '2026-09-26 09:41:52.000000';

    /** A profile row's state columns, as a full and plain state of a variant that is on sale. @param array<string, mixed> $over @return array<string, mixed> */
    private static function row(array $over = []): array
    {
        return $over + ['site_variant_status' => 'Published', 'site_product_status' => 'Published', 'site_product_type' => 'simple', 'site_product_id' => '77',
            'site_is_landing' => 0, 'site_is_default' => 1, 'site_product_link' => 'bar-juice-5000/', 'site_state_at' => self::AT];
    }

    public function testAnExportLineGivesTheStateItCarriesUnderTheNamesOfThePush(): void
    {
        $line = ['site' => 'electrofag', 'variant_id' => 5894, 'product_id' => 4410, 'product_title' => 'Bar Juice 5000', 'variant_title' => 'Blue Razz 10mg',
            'product_type' => 'variable', 'variant_status' => 'Published', 'product_status' => 'Draft', 'is_landing' => 0, 'is_default' => 1,
            'permalink' => 'bar-juice-5000/blue-razz-10mg', 'product_permalink' => 'bar-juice-5000', 'units_30d' => 3];
        $website = SiteState::fromExport($line);
        self::assertSame(['variant_status' => 'Published', 'product_status' => 'Draft', 'product_type' => 'variable', 'product_id' => 4410, 'is_landing' => 0, 'is_default' => 1,
            'product_link' => 'bar-juice-5000'], $website);
        self::assertSame(array_keys(SiteState::COLUMNS), array_keys($website), 'the seven values, under the names a push uses');
        self::assertSame(['state' => ['variant_status' => 'Published', 'product_status' => 'Draft', 'product_type' => 'variable', 'product_id' => '4410', 'is_landing' => false,
            'is_default' => true, 'product_link' => 'bar-juice-5000'], 'ignored' => 0], SiteState::parse($website));
        // A line of another kind of file, or a variant with no product row on the website: what is not there is not known.
        self::assertSame(array_fill_keys(array_keys(SiteState::COLUMNS), null), SiteState::fromExport(['variant_id' => 1, 'permalink' => 'x/']));
        self::assertSame(['state' => null, 'ignored' => 0], SiteState::parse(SiteState::fromExport(['variant_id' => 1])), 'a line that carries no state supplies none');
        self::assertSame('website', SiteState::KEY, 'not `site`: an export line uses that word for its store\'s code');
    }

    public function testThePushedObjectIsReadLenientlyAWrongTypeIsIgnoredAndCounted(): void
    {
        // Nothing sent, and things that are no object: no state. What is not an object is ignored whole and counted once.
        self::assertSame(['state' => null, 'ignored' => 0], SiteState::parse(null));
        self::assertSame(['state' => null, 'ignored' => 0], SiteState::parse([]), 'an empty object says nothing');
        foreach (['Published', 7, true, ['Published', 'Draft'], 1.5] as $notAnObject) {
            self::assertSame(['state' => null, 'ignored' => 1], SiteState::parse($notAnObject), json_encode($notAnObject));
        }
        // Everything as it should be: texts trimmed, the product's number as a text, yes and no in any of their three spellings.
        $full = ['variant_status' => ' Published ', 'product_status' => 'Draft', 'product_type' => 'variable', 'product_id' => 4410, 'is_landing' => false, 'is_default' => '1',
            'product_link' => ' bar-juice-5000/ '];
        self::assertSame(['state' => ['variant_status' => 'Published', 'product_status' => 'Draft', 'product_type' => 'variable', 'product_id' => '4410', 'is_landing' => false,
            'is_default' => true, 'product_link' => 'bar-juice-5000/'], 'ignored' => 0], SiteState::parse($full));
        self::assertSame([true, false, true, false, true, false], array_map(static fn (mixed $v): ?bool => SiteState::parse(['is_landing' => $v])['state']['is_landing'] ?? null,
            [1, 0, true, false, '1', '0']));
        self::assertSame('P-77/a', SiteState::parse(['product_id' => 'P-77/a'])['state']['product_id'] ?? null, 'a product number that is a text is kept as it is');
        // A wrong type, a value that cannot be one, a text too long for a status: each ignored and counted; the rest is kept.
        $r = SiteState::parse(['variant_status' => 5, 'product_status' => 'Published', 'product_type' => ['variable'], 'product_id' => 4.5, 'is_landing' => 2, 'is_default' => 'yes',
            'product_link' => 9]);
        self::assertSame(6, $r['ignored']);
        self::assertSame(['variant_status' => null, 'product_status' => 'Published', 'product_type' => null, 'product_id' => null, 'is_landing' => null, 'is_default' => null,
            'product_link' => null], $r['state'], 'what was ignored is not known; nothing is guessed from it');
        self::assertSame(['state' => null, 'ignored' => 2], SiteState::parse(['variant_status' => str_repeat('x', 33), 'product_id' => str_repeat('9', 65)]),
            'a status no column can hold is no status; with nothing else in it the object supplies no state');
        self::assertSame(['state' => null, 'ignored' => 1], SiteState::parse(['product_id' => "7\n7"]), 'a product number is printable, as a variant number is');
        self::assertSame(['state' => null, 'ignored' => 1], SiteState::parse(['product_id' => -4]));
        // Not known is not wrong: null and an empty text are not counted, and a key this class does not know is left alone.
        self::assertSame(['state' => null, 'ignored' => 0], SiteState::parse(['variant_status' => null, 'product_status' => '  ', 'stock' => 4, 'is_landing' => null]));
        self::assertSame(512, mb_strlen((string) (SiteState::parse(['product_link' => str_repeat('é', 600)])['state']['product_link'] ?? '')), 'an address is cut, as a variant\'s is');
        // A state is whole: what a supplied object leaves out is not known from then on (it is never kept from an older state).
        self::assertSame(['variant_status' => 'Bin', 'product_status' => null, 'product_type' => null, 'product_id' => null, 'is_landing' => null, 'is_default' => null,
            'product_link' => null], SiteState::parse(['variant_status' => 'Bin'])['state']);
        // What is written to the seven columns, in their order: yes and no as 1 and 0.
        self::assertSame(['Published', 'Draft', 'variable', '4410', 0, 1, 'bar-juice-5000/'], SiteState::values((array) SiteState::parse($full)['state']));
        self::assertSame(['site_variant_status', 'site_product_status', 'site_product_type', 'site_product_id', 'site_is_landing', 'site_is_default', 'site_product_link'],
            array_values(SiteState::COLUMNS));
    }

    public function testAStateChangedWhenSomethingTheReadersUseChanged(): void
    {
        $old = SiteState::stored(self::row());
        self::assertNotNull($old);
        $same = SiteState::parse(['variant_status' => 'Published', 'product_status' => 'Published', 'product_type' => 'simple', 'product_id' => 77, 'is_landing' => 0,
            'is_default' => true, 'product_link' => 'bar-juice-5000/'])['state'];
        self::assertFalse(SiteState::changed($old, (array) $same), 'the same state sent again (a number as a text, a yes as 1) is no change');
        foreach (['variant_status' => 'Bin', 'product_status' => 'Draft', 'product_type' => 'variable', 'product_id' => '78', 'is_landing' => true, 'is_default' => false] as $key => $value) {
            self::assertTrue(SiteState::changed($old, [$key => $value] + (array) $same), $key);
        }
        self::assertTrue(SiteState::changed($old, ['variant_status' => null] + (array) $same), 'known before and not known now is a change');
        self::assertFalse(SiteState::changed($old, ['product_link' => 'moved/'] + (array) $same), 'the product\'s address is stored and read by nothing: no change of the state');
        self::assertSame(['variant_status', 'product_status', 'product_type', 'product_id', 'is_landing', 'is_default'], SiteState::READ);
    }

    public function testTheReadersComeInOrderTheStoredColumnsThenTheFeaturesThenASuggestionsRecord(): void
    {
        $features = ['variant_status' => 'Draft', 'is_placeholder' => true];
        $evidence = ['variant_status' => 'Bin'];
        // 1. A stored state (it has a date) is THE state, whole: nothing of the other two is mixed into it.
        $s = SiteState::read(self::row(), $features, $evidence);
        self::assertSame([SiteState::STORED, self::AT, 'Published', 'Published', 'simple', '77', false, true, null],
            [$s['source'], $s['as_of'], $s['variant_status'], $s['product_status'], $s['product_type'], $s['product_id'], $s['is_landing'], $s['is_default'], $s['heading']]);
        $s = SiteState::read(self::row(['site_variant_status' => null, 'site_product_status' => null]), $features, $evidence);
        self::assertSame([SiteState::STORED, null, null], [$s['source'], $s['variant_status'], $s['product_status']],
            'a stored state that does not know the status is "not known": the features\' older word is not put in its place');
        // 2. No stored state (no date: the columns were never written): what the features say, with the heading they recorded.
        $never = array_fill_keys([...array_values(SiteState::COLUMNS), SiteState::DATE], null);
        $s = SiteState::read($never, $features, $evidence);
        self::assertSame([SiteState::FEATURES, null, 'Draft', null, true], [$s['source'], $s['as_of'], $s['variant_status'], $s['product_status'], $s['heading']]);
        self::assertSame(false, SiteState::read($never, ['variant_status' => 'Published', 'is_placeholder' => false], null)['heading']);
        self::assertNull(SiteState::read($never, ['variant_status' => 'Published'], null)['heading'], 'features that say nothing of a heading: not known');
        self::assertSame(SiteState::FEATURES, SiteState::read($never, ['is_placeholder' => 1], $evidence)['source'] ?? null, 'a heading the features recorded is a state of theirs');
        // 3. Neither: what the open suggestion recorded when the computer checked the variant.
        $s = SiteState::read($never, ['variant_status' => 'null'], $evidence);
        self::assertSame([SiteState::EVIDENCE, null, 'Bin', null, null], [$s['source'], $s['as_of'], $s['variant_status'], $s['product_status'], $s['heading']]);
        self::assertSame(SiteState::EVIDENCE, SiteState::read([], null, ['variant_status' => ' Published '])['source']);
        // 4. None of the three: not known, which is a state of its own. A value that is no text says nothing.
        foreach ([[null, null], [[], []], [['variant_status' => ''], ['variant_status' => 7]], [['variant_status' => null, 'is_placeholder' => false], ['variant_status' => ['x']]]] as [$f, $e]) {
            $s = SiteState::read($never, $f, $e);
            self::assertSame([null, null, null, null], [$s['source'], $s['variant_status'], $s['product_status'], $s['as_of']], json_encode([$f, $e]));
        }
        // On sale or not, as each reader can say it: the variant's own status first, then (a stored state only) its product's.
        self::assertNull(SiteState::notOnSale(SiteState::read(self::row())));
        self::assertSame('Bin', SiteState::notOnSale(SiteState::read(self::row(['site_variant_status' => 'Bin', 'site_product_status' => 'Draft']))));
        self::assertSame('Draft', SiteState::notOnSale(SiteState::read(self::row(['site_product_status' => 'Draft']))),
            'a published variant under a product that is not published is not on sale');
        self::assertNull(SiteState::notOnSale(SiteState::read(self::row(['site_variant_status' => null, 'site_product_status' => null]))), 'not known is not "not on sale"');
        self::assertSame('Draft', SiteState::notOnSale(SiteState::read($never, $features, $evidence)));
        self::assertNull(SiteState::notOnSale(SiteState::read($never, null, null)));
    }

    /**
     * The review of build 2a, F1. A stored state is THE state, and read() gives it alone. But a bulk "create as new" skipped a
     * variant its features called a page heading, or whose features or record gave a status that is not Published, before the
     * state was stored, and it must not be less strict now: against() gives those two answers beside a stored state, and nothing
     * for any other state (read() has asked the features and the record of that one already).
     */
    public function testWhatTheFeaturesAndTheRecordSayAgainstAVariantCountsBesideItsStoredState(): void
    {
        $stored = SiteState::read(self::row());
        $no = ['heading' => false, 'status' => null];
        // Nothing against it: nothing said, "Published", "no heading".
        self::assertSame($no, SiteState::against($stored, null, null));
        self::assertSame($no, SiteState::against($stored, ['variant_status' => 'Published', 'is_placeholder' => false], ['variant_status' => 'Published']));
        self::assertSame($no, SiteState::against($stored, [], ['variant_status' => 7]), 'a value that is no text says nothing');
        // A page heading by the features, in each spelling the database gives the flag in.
        foreach ([true, 1, 'true', '1'] as $flag) {
            self::assertSame(['heading' => true, 'status' => null], SiteState::against($stored, ['variant_status' => 'Published', 'is_placeholder' => $flag], null), json_encode($flag));
        }
        // A status that is not Published: the features' first, else the record's (the order of the readers).
        self::assertSame(['heading' => false, 'status' => 'Draft'], SiteState::against($stored, ['variant_status' => 'Draft'], ['variant_status' => 'Bin']));
        self::assertSame(['heading' => false, 'status' => 'Bin'], SiteState::against($stored, ['variant_status' => 'null'], ['variant_status' => 'Bin']));
        self::assertSame(['heading' => false, 'status' => 'Bin'], SiteState::against($stored, null, ['variant_status' => ' Bin ']));
        self::assertSame($no, SiteState::against($stored, ['variant_status' => 'Published'], ['variant_status' => 'Bin']), 'features that say Published come before the record, as they always did');
        self::assertSame(['heading' => true, 'status' => 'Draft'], SiteState::against($stored, ['variant_status' => 'Draft', 'is_placeholder' => true], null), 'both; the seed says the heading first');
        // Whatever the stored state itself says or lacks, and however old it is: the two older readers are asked all the same.
        foreach ([['site_variant_status' => 'Bin'], ['site_variant_status' => null, 'site_product_status' => null], ['site_is_landing' => null], ['site_state_at' => '2020-01-01 00:00:00.000000']] as $over) {
            self::assertSame(['heading' => true, 'status' => 'Draft'], SiteState::against(SiteState::read(self::row($over)), ['variant_status' => 'Draft', 'is_placeholder' => true], null),
                json_encode($over));
        }
        // A state that is not a stored one: read() has asked the features and the record already; nothing is said twice.
        foreach ([SiteState::read([], ['variant_status' => 'Draft', 'is_placeholder' => true], null), SiteState::read([], null, ['variant_status' => 'Bin']), SiteState::read([], null, null)] as $state) {
            self::assertSame($no, SiteState::against($state, ['variant_status' => 'Draft', 'is_placeholder' => true], ['variant_status' => 'Bin']), (string) $state['source']);
        }
        // read() itself is as it was: the stored state alone (the check on arrival records that one).
        $s = SiteState::read(self::row(), ['variant_status' => 'Draft', 'is_placeholder' => true], ['variant_status' => 'Bin']);
        self::assertSame([SiteState::STORED, 'Published', null], [$s['source'], $s['variant_status'], $s['heading']]);
    }

    public function testAStoredStateOlderThanTheSettingAndAStateNoReaderGivesAreNotKnown(): void
    {
        $now = Clock::fromDb('2026-10-26 09:41:52.000000'); // 30 days after the state's date, to the second
        self::assertFalse(SiteState::tooOld(self::AT, 30, $now), 'as old as the setting allows');
        self::assertTrue(SiteState::tooOld(self::AT, 30, $now->modify('+1 second')));
        self::assertTrue(SiteState::tooOld(self::AT, 29, $now));
        self::assertFalse(SiteState::tooOld(self::AT, 365, $now->modify('+300 days')));
        self::assertFalse(SiteState::tooOld('2026-10-26 09:45:00.000000', 1, $now), 'a date a little ahead of this clock is not old');
        self::assertFalse(SiteState::tooOld('2026-09-26 09:41:52', 30, $now), 'a date without its fraction reads the same');
        self::assertTrue(SiteState::tooOld(null, 365, $now), 'no date is never fresh');
        self::assertTrue(SiteState::tooOld('not a date', 365, $now));

        // What makes a state one that cannot be relied on (the seed's last question, and the line under a row marked New).
        $never = [];
        $doubt = static fn (array $state, int $days = 30): ?string => SiteState::doubt($state, $days, $now);
        self::assertNull($doubt(SiteState::read(self::row())), 'stored, whole and fresh');
        self::assertSame('too_old', $doubt(SiteState::read(self::row()), 29));
        self::assertSame('not_known', $doubt(SiteState::read($never, null, null)), 'no reader gives a state');
        foreach (['site_variant_status', 'site_product_status', 'site_product_type', 'site_product_id', 'site_is_landing', 'site_is_default'] as $missing) {
            self::assertSame('not_known', $doubt(SiteState::read(self::row([$missing => null]))), "{$missing}: a stored state that lacks it is not known");
        }
        self::assertNull($doubt(SiteState::read(self::row(['site_product_link' => null]))), 'the product\'s address is read by nothing');
        // A state taken from the features or from a suggestion's record has no date: it is neither fresh nor old, and is relied on
        // exactly as before the columns were there (M66).
        self::assertNull($doubt(SiteState::read($never, ['variant_status' => 'Published'], null), 1));
        self::assertNull($doubt(SiteState::read($never, null, ['variant_status' => 'Published']), 1));
    }

    public function testThePageHeadingIsTheNormalizersOwnAnswerAndAMissingInputGivesNotKnown(): void
    {
        $profile = static fn (string $variantTitle = '', array $barcodes = [], ?int $units = 0): array => ['product_title' => 'Bar Juice 5000 Nic Salt', 'variant_title' => $variantTitle,
            'barcodes' => $barcodes === [] ? null : json_encode($barcodes), 'units_30d' => $units];
        $state = static fn (array $over = []): array => SiteState::read(self::row($over + ['site_product_type' => 'variable']));
        // The Normalizer's two cases: the website's landing row; and the default variant of a product with several published
        // variants that has the product's own name, no barcode and no sale in the month.
        self::assertTrue(SiteState::heading($state(['site_is_landing' => 1, 'site_is_default' => 0]), $profile('Blue Razz 10mg', ['5060769492206'], 4), 0));
        self::assertTrue(SiteState::heading($state(), $profile(), 2));
        self::assertTrue(SiteState::heading($state(), $profile('bar juice 5000  nic salt'), 3), 'the same name, typed another way');
        self::assertFalse(SiteState::heading($state(), $profile(), 1), 'the only published variant of its product is no heading');
        self::assertFalse(SiteState::heading($state(), $profile('Blue Razz 10mg'), 2));
        self::assertFalse(SiteState::heading($state(), $profile('', ['5060769492206']), 2));
        self::assertFalse(SiteState::heading($state(), $profile('', [], 3), 2));
        self::assertFalse(SiteState::heading($state(['site_is_default' => 0]), $profile(), 2));
        self::assertFalse(SiteState::heading($state(['site_product_type' => 'simple']), $profile(), 2));
        // It is the Normalizer's answer for every combination of what its rule reads, not a second rule of this class.
        $n = 0;
        foreach ([0, 1] as $landing) {
            foreach (['variable', 'simple', 'bundle'] as $type) {
                foreach ([0, 1] as $default) {
                    foreach ([0, 1, 2, 9] as $siblings) {
                        foreach (['', 'Bar Juice 5000 Nic Salt', 'Blue Razz 10mg'] as $variantTitle) {
                            foreach ([[], ['5060769492206']] as $barcodes) {
                                foreach ([0, 2, null] as $units) {
                                    $p = $profile($variantTitle, $barcodes, $units);
                                    $own = Normalizer::normalize(['site' => 'x', 'variant_id' => 1, 'product_id' => 77, 'product_title' => $p['product_title'], 'variant_title' => $variantTitle,
                                        'brand' => 'Bar Juice 5000', 'attributes' => [], 'barcodes' => $barcodes, 'price' => 4.99, 'sale_price' => null, 'units_30d' => (int) $units,
                                        'is_landing' => $landing, 'product_type' => $type, 'is_default' => $default], ['published_siblings' => $siblings])['is_placeholder'];
                                    self::assertSame($own, SiteState::heading($state(['site_is_landing' => $landing, 'site_product_type' => $type, 'site_is_default' => $default]), $p, $siblings),
                                        json_encode([$landing, $type, $default, $siblings, $variantTitle, $barcodes, $units]));
                                    $n++;
                                }
                            }
                        }
                    }
                }
            }
        }
        self::assertSame(864, $n);
        // A missing input: not known. Never "known not a heading", and never a heading either: also for the landing row, whose
        // other inputs would not have changed the answer (the class knows no part of the rule).
        foreach ([['site_is_landing' => 1], []] as $over) {
            foreach (['site_is_landing', 'site_product_type', 'site_is_default'] as $missing) {
                self::assertNull(SiteState::heading($state([$missing => null] + $over), $profile(), 2), $missing);
            }
            self::assertNull(SiteState::heading($state($over), $profile(), null), 'the published variants of its product could not be counted');
        }
        self::assertNull(SiteState::heading(SiteState::read([], ['variant_status' => 'Published', 'is_placeholder' => false], null), $profile(), 2),
            'a state that is not the stored one has no raw inputs: its heading is not worked out here');
        self::assertFalse(SiteState::headingInputs(SiteState::read(self::row(['site_product_id' => null]))), 'without the product\'s number its variants cannot be counted');
        self::assertTrue(SiteState::headingInputs(SiteState::read(self::row())));
    }
}
