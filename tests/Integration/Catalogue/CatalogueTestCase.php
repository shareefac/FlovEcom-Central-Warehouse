<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Catalogue;

use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\TestDb;

/**
 * Base of the item card and barcode tests (IM3, docs/decisions.md I100-I112): staff holding catalogue.edit, items, linked
 * listings with profiles, and the full invariant check after every test (stock, ..., IC1-IC3).
 */
abstract class CatalogueTestCase extends MappingTestCase
{
    protected ItemCards $cards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cards = new ItemCards(self::$db);
    }

    /** A person who may change item cards (stock_controller unless told otherwise). */
    protected function editor(string $role = 'stock_controller'): Caller
    {
        return $this->staffUser($role);
    }

    /**
     * A listing of $site linked to $sku (mapped) with a profile.
     *
     * @param list<string|int> $barcodes
     * @param array<string, mixed>|null $features
     */
    protected function linked(Caller $site, string $variant, ?int $sku, array $barcodes = [], int $u = 1, ?array $features = null, ?string $title = null,
        ?string $status = null): int
    {
        $id = $this->listing($site, $variant, $sku, $u, $status);
        self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, barcodes, features) VALUES (?, ?, NULL, ?, ?, ?)',
            [$id, $title ?? "Listing {$variant}", $features['brand_raw'] ?? null, json_encode($barcodes, JSON_THROW_ON_ERROR),
                $features === null ? null : json_encode($features, JSON_THROW_ON_ERROR)]);
        return $id;
    }

    /** @return array<string, mixed> the stored card row */
    protected function row(int $sku): array
    {
        return (array) self::$db->one('SELECT * FROM item_card WHERE sku_id = ?', [$sku]);
    }

    /**
     * Runs a bin/ tool against this slot's schema as the admin login (the tools' --db / --admin).
     *
     * @return array{code: int, out: string, err: string}
     */
    protected static function tool(string $name, string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    /** @return array<string, mixed> the sku_barcode row of a barcode, or [] */
    protected function barcode(string $key): array
    {
        return (array) self::$db->one('SELECT * FROM sku_barcode WHERE barcode = ?', [$key]);
    }
}
