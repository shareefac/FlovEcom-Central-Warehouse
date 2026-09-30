<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Mapping\BarcodeSeeder;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\TestDb;

/**
 * sku_barcode is seeded from the listing each item was minted from (design S3; review finding, data
 * lens: 0 rows on staging, so every item showed "Barcodes: none" beside a listing sharing its barcode).
 * Only usable GTINs, as their keys; idempotent; a barcode on two items is marked unusable, not moved.
 */
final class BarcodeSeederTest extends MappingTestCase
{
    public function testUsableCodesOfTheOriginListingAreSeededOnceAndClashesAreMarked(): void
    {
        $vpg = $this->site('vpg');
        $lead = $this->staffUser('mapping_lead');
        $a = $this->minted($lead, $vpg, 'A', ['4006381333931', '0005012345678900', 'Black Grey', '1746', '4006381333932']);
        $b = $this->minted($lead, $vpg, 'B', ['5012345678900']); // the same GTIN as A's second code
        $c = $this->minted($lead, $vpg, 'C', []);
        $seeder = new BarcodeSeeder(self::$db);

        $dry = $seeder->seed(Caller::system('test'), null, true);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM sku_barcode'), 'a dry run writes nothing');
        self::assertSame(['items' => 3, 'with_barcodes' => 2, 'unusable_codes' => 3], array_intersect_key($dry, ['items' => 1, 'with_barcodes' => 1, 'unusable_codes' => 1]));

        $r = $seeder->seed(Caller::system('test'));
        self::assertSame(['items' => 3, 'with_barcodes' => 2, 'added' => 2, 'already' => 0, 'unusable_codes' => 3, 'clashes' => 1], $r);
        self::assertSame([
            ['barcode' => '4006381333931', 'sku_id' => $a, 'is_usable' => 1, 'source' => 'origin_listing'],
            ['barcode' => '5012345678900', 'sku_id' => $a, 'is_usable' => 0, 'source' => 'origin_listing'],
        ], self::$db->all('SELECT barcode, sku_id, is_usable, source FROM sku_barcode ORDER BY barcode'));
        self::assertStringContainsString(sprintf('also on CW-%06d', $b), (string) self::$db->value("SELECT note FROM sku_barcode WHERE barcode = '5012345678900'"));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'sku_barcode.seed'"));

        $again = $seeder->seed(Caller::system('test'));
        self::assertSame([0, 2, 1], [$again['added'], $again['already'], $again['clashes']], 'idempotent');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM sku_barcode'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM sku_barcode WHERE sku_id = ?', [$c]));

        // The tool, end to end.
        self::$db->exec('DELETE FROM sku_barcode');
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/seed_barcodes.php", '--db=' . TestDb::name(), '--admin'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($p), $err);
        self::assertStringContainsString('items=3 with_barcodes=2 added=2 already=0 unusable_codes=3 clashes=1 rows_now=2', $out);
    }

    /** @param list<string> $barcodes */
    private function minted(\CW\Caller $lead, \CW\Caller $site, string $variant, array $barcodes): int
    {
        $id = $this->listing($site, $variant, null);
        self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, barcodes) VALUES (?, ?, ?)',
            [$id, "Product {$variant}", json_encode($barcodes, JSON_THROW_ON_ERROR)]);
        return (int) $this->ds->mintAndLink($lead, $id, 0, ['name' => "Product {$variant}"], 'seed-test')['sku_id'];
    }
}
