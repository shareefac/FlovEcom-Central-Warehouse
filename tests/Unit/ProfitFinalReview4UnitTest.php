<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Profit\SnapshotBody;
use PHPUnit\Framework\TestCase;

/**
 * The final re-check of the fix round (build/A/codex_final4_astra_p.txt, p-HIGH "service name", CONFIRMED by the lead; final2/FIX_BRIEF_A5.md
 * F1; docs/decisions.md PR155), shown failing first on 5e1fe37: a parcel's service name and the delivery method (both the shop's
 * shiping_master.shipm_providor, connector snapshot.php) are kept only when they are one of the names of the real parcels (every name seen
 * 5 times or more in the 50 real Vape and Go day files, 1 Oct - 19 Nov, 65,769 parcels), compared without case and with white space
 * collapsed, stored in the list's own spelling; any other text is "" (the method: null), never a refusal. The word filter kept
 * "Collection Service: 12 The Point, Preston". Also F3's wiring: bin/migrate.php runs the grants inside the Migrator's run (its bounded wait).
 */
final class ProfitFinalReview4UnitTest extends TestCase
{
    /** The 13 names, as the lead read them from the real day files (FIX_BRIEF_A5.md F1). */
    private const REAL = ['Royal Mail Tracked 48 (2-4 Working Days)', 'DPD', 'Royal Mail Tracked 24(1-2 Working Days)', 'DPD Pickup (2 day Service)',
        'Royal Mail Local Collect (1-2 Working days)', 'Royal Mail Special Next Day Delivery', 'DPD Weekend Delivery', 'EVRI International', 'DPD Sunday Delivery',
        'In Store Collection Preston', 'DPD 2 Day Service', 'Return label', 'Royal Mail International Tracked'];

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-20 00:00:00', new \DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private static function body(array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1763586245123, 'order_no' => '1567469', 'status' => 'completed', 'placed_at' => '2026-11-19T21:04:05Z',
            'paid_at' => '2026-11-19T21:05:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'sub_of' => null,
            'lines' => [['line_id' => '8812345', 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1299, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
                'free' => false]], 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 1299, 'parcels' => []];
    }

    /** @return array{0: mixed, 1: mixed} what CW keeps of a name sent as the delivery method and as a parcel's service */
    private static function kept(mixed $name): array
    {
        $b = SnapshotBody::parse(self::body(['delivery' => ['method_id' => '12', 'method' => $name, 'charged_inc' => 399, 'refund_inc' => 0],
            'parcels' => [['parcel_ref' => 'odl:991122', 'tracking_no' => null, 'carrier' => 'royal_mail', 'service_code' => 'TPL48HV', 'service' => $name,
                'status' => 'generated', 'label_at' => '2026-11-19T22:00:00Z', 'shipped_at' => null, 'manual' => false, 'partial' => false]]]), self::now());
        return [$b['delivery']['method'], $b['parcels'][0]['service']];
    }

    public function testAServiceNameIsOneOfTheRealNamesOrNothing(): void
    {
        // An address, a person's name, a carrier with a name, and a carrier's name not on the list: never kept.
        foreach (['Collection Service: 12 The Point, Preston', 'Collection: 12 The Point, Preston', 'Jane Smith', 'DPD Jane', 'Royal Mail Tracked 48',
            'In Store Collection Preston 12', 'DPD 2 Day Service Jane', 'Return label to 12 High Street'] as $other) {
            self::assertSame([null, ''], self::kept($other), $other);
            self::assertNull(SnapshotBody::serviceName($other), $other);
        }
        foreach (self::REAL as $real) {
            self::assertSame($real, SnapshotBody::serviceName($real), $real);
            self::assertSame([$real, $real], self::kept($real), $real);
        }
        // Another case and other spacing: the list's own spelling is stored.
        self::assertSame(['DPD Pickup (2 day Service)', 'DPD Pickup (2 day Service)'], self::kept('dpd pickup (2 DAY service)'));
        self::assertSame(['Royal Mail Tracked 48 (2-4 Working Days)', 'Royal Mail Tracked 48 (2-4 Working Days)'],
            self::kept("  royal MAIL   tracked 48\t(2-4 working DAYS) "));
        self::assertSame(self::REAL, SnapshotBody::SERVICE_NAMES, 'the list is the 13 real names, as the lead read them');
    }

    /** Never a refusal: a name too long, with a control character, or not a text at all is "" (the method: null), and the order is taken. */
    public function testAServiceNameNeverRefusesTheSnapshot(): void
    {
        foreach ([str_repeat('Jane Smith 1 High Street ', 10), "DPD\x01", 42, ['DPD'], true] as $other) {
            self::assertSame([null, ''], self::kept($other), json_encode($other) ?: '?');
        }
        self::assertSame([null, ''], self::kept(null));
        self::assertSame([null, ''], self::kept(''));
    }

    /**
     * F3 (p-M, the migration's lock wait): bin/migrate.php gives the grants step to the Migrator's run, so the grants run inside it, with the
     * session's lock wait still LOCK_WAIT_SEC (MigratorTest holds that a step given to migrate() sees the bound and that it is put back after).
     */
    public function testMigratePhpRunsTheGrantsInsideTheMigratorsRun(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../bin/migrate.php');
        self::assertSame(1, substr_count($src, 'Grants::apply('), 'one grants call');
        self::assertMatchesRegularExpression('/\$grants = static function \(\)[^{]*\{[^}]*Grants::apply\(/s', $src, 'the grants are a step');
        self::assertMatchesRegularExpression('/\$migrator->migrate\(\$grants\)/', $src, 'given to the Migrator\'s run');
    }
}
