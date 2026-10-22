<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Mapping\BulkDecisions;
use PHPUnit\Framework\TestCase;

/**
 * The words of a name as a set (BulkDecisions::nameWords, docs/decisions.md NF2), which Store Products' "Create new warehouse products
 * for these" compares with the item names: the same in every PHP, with or without the intl extension (Codex review on dc7e49c,
 * finding 6: the accents were folded by intl's Normalizer only, which the web server's PHP lacks).
 */
final class NameWordsTest extends TestCase
{
    public function testAccentsAreFoldedWithoutIntl(): void
    {
        self::assertSame('creme brulee nandu cafe aeon strasse lodz', BulkDecisions::foldAccents('crème brûlée ñandú café æon straße łódź'));
        self::assertSame(BulkDecisions::nameWords('Hayati Crème Brûlée'), BulkDecisions::nameWords('Brulee Creme Hayati'));
    }

    public function testTheSameWordsInAnyOrderAreOneNameAndAnotherWordIsAnother(): void
    {
        self::assertSame('4000 apple hayati max pro', BulkDecisions::nameWords('Hayati Pro Max 4000 Apple'));
        self::assertSame(BulkDecisions::nameWords('Hayati Pro Max 4000 Apple'), BulkDecisions::nameWords('APPLE - hayati, Pro Max 4000 (Apple)'));
        self::assertNotSame(BulkDecisions::nameWords('Hayati Pro Max 4000 Apple'), BulkDecisions::nameWords('Hayati Pro Max 4000 Apple Ice'));
        self::assertSame('', BulkDecisions::nameWords(' - / '));
    }
}
