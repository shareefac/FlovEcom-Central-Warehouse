<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Mapping\SiteSelling;
use PHPUnit\Framework\TestCase;

/**
 * The website's own selling mode on a pushed listing (T-556 option A, 0068): read leniently (never a refusal), and what the item page
 * shows of a stored row. Pure.
 */
final class SiteSellingTest extends TestCase
{
    public function testParseIsLenientAndAnObjectThatNamesTheModeIsAState(): void
    {
        $none = ['stock_mode' => null, 'allow_backorders' => null, 'low_stock_threshold' => null];
        foreach ([
            'nothing' => [null, null, 0],
            'empty' => [[], null, 0],
            'a text' => ['In-Stock', null, 1],
            'a list' => [['In-Stock', 1, 3], null, 1],
            'no stock_mode' => [['allow_backorders' => 1], null, 1],
            'the three' => [['stock_mode' => 'From-Warehouse', 'allow_backorders' => 0, 'low_stock_threshold' => 3], ['stock_mode' => 'From-Warehouse', 'allow_backorders' => 0,
                'low_stock_threshold' => 3], 0],
            'none set there' => [['stock_mode' => null, 'allow_backorders' => null, 'low_stock_threshold' => null], $none, 0],
            'texts and spaces' => [['stock_mode' => ' In-Stock ', 'allow_backorders' => '1', 'low_stock_threshold' => ' -4 '], ['stock_mode' => 'In-Stock', 'allow_backorders' => 1,
                'low_stock_threshold' => -4], 0],
            'yes and no' => [['stock_mode' => '', 'allow_backorders' => true], array_replace($none, ['allow_backorders' => 1]), 0],
            'wrong types' => [['stock_mode' => 7, 'allow_backorders' => 2, 'low_stock_threshold' => 2.5], $none, 3],
            'too long' => [['stock_mode' => str_repeat('a', SiteSelling::MODE_MAX + 1), 'low_stock_threshold' => 2147483648], $none, 2],
            'not text' => [['stock_mode' => "\xff"], $none, 1],
            'others left alone' => [['stock_mode' => 'Out-Of-Stock', 'colour' => 'red'], ['stock_mode' => 'Out-Of-Stock'] + $none, 0],
        ] as $name => [$in, $state, $ignored]) {
            self::assertSame(['state' => $state, 'ignored' => $ignored], SiteSelling::parse($in), $name);
        }
    }

    public function testShownIsNullUntilARowIsThere(): void
    {
        self::assertNull(SiteSelling::shown(['stock_mode' => null, 'allow_backorders' => null, 'low_stock_threshold' => null, 'as_of' => null]));
        self::assertSame(['mode' => 'In-Stock', 'backorders' => 1, 'threshold' => 0, 'as_of' => '2026-11-16 16:20:00.000000'],
            SiteSelling::shown(['stock_mode' => 'In-Stock', 'allow_backorders' => '1', 'low_stock_threshold' => '0', 'as_of' => '2026-11-16 16:20:00.000000']));
        self::assertSame(['mode' => null, 'backorders' => null, 'threshold' => null, 'as_of' => '2026-11-16 16:20:00.000000'],
            SiteSelling::shown(['stock_mode' => null, 'allow_backorders' => null, 'low_stock_threshold' => null, 'as_of' => '2026-11-16 16:20:00.000000']));
    }
}
