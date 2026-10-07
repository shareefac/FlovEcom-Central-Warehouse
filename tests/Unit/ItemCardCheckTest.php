<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Catalogue\CardProposals;
use CW\Catalogue\ItemCardCsv;
use CW\Catalogue\ItemCards;
use CW\CwException;
use PHPUnit\Framework\TestCase;

/**
 * What a person may type into an item card (IM3; docs/decisions.md I101, I110): ml to 0.1, mg/ml to 0.01 or a percentage, the
 * product types and their spellings ("disposable" is not one), yes / no answers, the ECID shape, every problem reported at once;
 * and the CSV cell rule (the export's formula apostrophe is read past).
 */
final class ItemCardCheckTest extends TestCase
{
    public function testValuesAreStoredInOneForm(): void
    {
        $v = ItemCards::check(['liquid_ml' => '10', 'nicotine_mg' => '20', 'product_type' => 'e-liquid', 'duty_liable' => 'Yes', 'single_use' => 'no',
            'ecid' => ' 12345-16-12345 ', 'manufacturer' => '  Elux   Tech ', 'brand' => 'Elux', 'flavour' => 'Blue  Razz Ice', 'discontinued' => '']);
        self::assertSame(['liquid_ml' => '10.0', 'nicotine_mg' => '20.00', 'product_type' => 'e_liquid', 'duty_liable' => 1, 'single_use' => 0,
            'ecid' => '12345-16-12345', 'manufacturer' => 'Elux Tech', 'brand' => 'Elux', 'flavour' => 'Blue Razz Ice', 'discontinued' => 0], $v);
        self::assertSame(['liquid_ml' => '2.5'], ItemCards::check(['liquid_ml' => '2,5 ml']));
        self::assertSame(['liquid_ml' => '0.7'], ItemCards::check(['liquid_ml' => '0.70']));
        self::assertSame(['liquid_ml' => '5000.0'], ItemCards::check(['liquid_ml' => '5000']));
        self::assertSame(['nicotine_mg' => '17.00'], ItemCards::check(['nicotine_mg' => '1.7%']));
        self::assertSame(['nicotine_mg' => '20.00'], ItemCards::check(['nicotine_mg' => '2 %']));
        self::assertSame(['nicotine_mg' => '5.50'], ItemCards::check(['nicotine_mg' => '5.5 mg/ml']));
        self::assertSame(['nicotine_mg' => '0.00'], ItemCards::check(['nicotine_mg' => '0mg']));
        self::assertSame(['nicotine_mg' => '2.50'], ItemCards::check(['nicotine_mg' => '0.25%']));
        self::assertSame(['liquid_ml' => null, 'nicotine_mg' => null, 'duty_liable' => null, 'product_type' => null, 'flavour' => null],
            ItemCards::check(['liquid_ml' => '', 'nicotine_mg' => ' ', 'duty_liable' => '', 'product_type' => '', 'flavour' => '']), 'empty = not known');
        self::assertSame(['discontinued' => 0], ItemCards::check(['discontinued' => '']), 'discontinued is yes or no');
        self::assertSame(['discontinued' => 1, 'duty_liable' => 1, 'single_use' => 0], ItemCards::check(['discontinued' => 'y', 'duty_liable' => 'TRUE', 'single_use' => '0']));
        self::assertSame(['ecid' => 'GB-12345-ABC'], ItemCards::check(['ecid' => 'gb-12345-abc']));
    }

    public function testProductTypesAndTheirSpellings(): void
    {
        $types = ['e_liquid' => ['e_liquid', 'E-Liquid', 'eliquid', 'nic salt', 'Nicotine Salt'], 'shortfill' => ['Shortfill', 'short fill'],
            'nic_shot' => ['nic shot', 'Nicotine shot', 'booster'], 'prefilled_pod' => ['prefilled pod', 'Pre-filled pod', 'Prefilled pods', 'pre-filled pods'],
            'device_kit' => ['device / kit', 'Device/Kit', 'kit', 'pod kit'], 'single_use' => ['single-use vape', 'Single use', 'single_use'],
            'coil' => ['coil', 'Coils'], 'tank' => ['Tank'], 'accessory' => ['accessories', 'Accessory']];
        foreach ($types as $type => $spellings) {
            foreach ($spellings as $s) {
                self::assertSame($type, ItemCards::check(['product_type' => $s])['product_type'], $s);
            }
        }
        $e = self::invalid(['product_type' => 'Disposable']);
        self::assertStringContainsString('"disposable" is not one', $e->detail['errors']['product_type']);
        foreach (['pod', 'Pods', 'pod_s'] as $empty) {
            self::invalid(['product_type' => $empty]); // a bare "pod" is as often an EMPTY refillable pod: the person says which (I123)
        }
    }

    public function testEveryProblemAtOnce(): void
    {
        $e = self::invalid(['liquid_ml' => '2.55', 'nicotine_mg' => '101', 'duty_liable' => 'maybe', 'ecid' => 'AB', 'brand' => str_repeat('x', 129),
            'flavour' => "two\nlines", 'single_use' => 'perhaps', 'product_type' => 'vape']);
        self::assertSame(['liquid_ml', 'nicotine_mg', 'duty_liable', 'ecid', 'brand', 'flavour', 'single_use', 'product_type'], array_keys($e->detail['errors']));
        self::assertStringContainsString('0.1 ml', $e->detail['errors']['liquid_ml']);
        self::assertStringContainsString('at most 100 mg/ml', $e->detail['errors']['nicotine_mg']);
        self::assertStringContainsString('The brand is at most 128 characters', $e->detail['errors']['brand']);
        self::assertSame(['liquid_ml' => '0.0'], ItemCards::check(['liquid_ml' => '0']), '0 = no tank (a device / kit only: ItemCards::plan)');
        self::invalid(['liquid_ml' => '5000.1']);
        self::invalid(['liquid_ml' => '-1']);
        self::invalid(['liquid_ml' => '1e3']);
        self::invalid(['nicotine_mg' => '20.001']);
        self::invalid(['nicotine_mg' => '1.7777%']);
        self::invalid(['ecid' => '12345--16']);
        self::invalid(['manufacturer' => "\xC3\x28"]);
        try {
            ItemCards::check(['colour' => 'red']);
            self::fail('an unknown field');
        } catch (CwException $e) {
            self::assertSame([400, 'bad_field'], [$e->httpStatus, $e->errorCode]);
        }
    }

    public function testWhatTheScreensShow(): void
    {
        self::assertSame('10 ml', CardProposals::shown('liquid_ml', '10.0'));
        self::assertSame('2.5 ml', CardProposals::shown('liquid_ml', '2.5'));
        self::assertSame('0 ml (no tank)', CardProposals::shown('liquid_ml', '0.0'));
        self::assertSame('100 ml', CardProposals::shown('liquid_ml', '100.0'));
        self::assertSame('20 mg/ml', CardProposals::shown('nicotine_mg', '20.00'));
        self::assertSame('0 mg/ml', CardProposals::shown('nicotine_mg', '0.00'));
        self::assertSame('prefilled pod', CardProposals::shown('product_type', 'prefilled_pod'));
        self::assertSame('yes', CardProposals::shown('duty_liable', 1));
        self::assertSame('no', CardProposals::shown('duty_liable', 0));
        self::assertSame('', ItemCards::yesNoLabel(null));
    }

    public function testTheExportsFormulaApostropheIsReadPast(): void
    {
        self::assertSame('=HYPERLINK("x")', ItemCardCsv::cell('\'=HYPERLINK("x")'));
        self::assertSame('-5', ItemCardCsv::cell("'-5"));
        self::assertSame(' @x', ItemCardCsv::cell("' @x"));
        self::assertSame("'Tis mint", ItemCardCsv::cell("'Tis mint"), 'an apostrophe that protects nothing is part of the text');
        self::assertSame('Blue Razz', ItemCardCsv::cell('Blue Razz'));
    }

    /** @param array<string, string> $input */
    private static function invalid(array $input): CwException
    {
        try {
            ItemCards::check($input);
        } catch (CwException $e) {
            self::assertSame([422, 'card_invalid'], [$e->httpStatus, $e->errorCode]);
            return $e;
        }
        self::fail('expected 422 card_invalid for ' . json_encode($input));
    }
}
