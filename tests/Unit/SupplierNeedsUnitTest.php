<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Suppliers\Suppliers;
use PHPUnit\Framework\TestCase;

/** Suppliers::missing() with the detail switches (0062, docs/decisions.md SD1, SD2). */
final class SupplierNeedsUnitTest extends TestCase
{
    public function testTheSwitchesLeaveOutTheirDetailsOnly(): void
    {
        $name = ['name' => 'OV', 'country' => 'GB', 'is_overseas' => 0];
        $all = ['address_line1', 'postcode', 'email_or_phone', 'payment_terms', 'dd_checked_on', 'dd_checked_by', 'dd_next_review_on'];
        self::assertSame($all, Suppliers::missing($name), 'no switches given: every detail, as before 0062');
        $off = ['address' => false, 'contact' => false, 'payment_terms' => false, 'due_diligence' => false];
        self::assertSame([], Suppliers::missing($name, $off));
        self::assertSame(['name'], Suppliers::missing(['name' => ' '] + $name, $off), 'the name is always needed');
        self::assertSame(['payment_terms'], Suppliers::missing($name, ['payment_terms' => true] + $off));
        self::assertSame(['dd_checked_on', 'dd_checked_by', 'dd_next_review_on'], Suppliers::missing($name, ['due_diligence' => true] + $off));
        self::assertSame(['address_line1', 'postcode'], Suppliers::missing($name, ['address' => true] + $off));
        self::assertSame(['import_route'], Suppliers::missing(['is_overseas' => 1] + $name, $off), 'an overseas supplier still needs its route');
        self::assertSame($all, Suppliers::missing($name, []), 'a switch not given counts as on');
    }
}
