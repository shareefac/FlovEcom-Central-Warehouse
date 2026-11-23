<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\ClickAndDropReport;
use CW\Tests\Support\XlsWriter;
use PHPUnit\Framework\TestCase;

/**
 * Click & Drop's "Manifested Orders" report read into labels (docs/decisions.md DL48), from SYNTHETIC files in its real column layout (made-up
 * names, addresses, emails and phones; a real report is never read by a test): the columns by name in either report's order, every field in
 * its known shape only, the personal columns never returned, a tracking number twice, a missing column refused by its name.
 */
final class ClickAndDropReportTest extends TestCase
{
    /** The Manifested Orders report's columns, in its order. */
    private const MANIFESTED = ['Order number', 'Batch number', 'Manifest number', 'Channel', 'Channel reference', 'Despatch date', 'Manifest date', 'Customer',
        'Company', 'Address line 1', 'Address line 2', 'City', 'Postcode', 'Country', 'Weight (kg)', 'Package size', 'Shipping service', 'Service code',
        'Tracking number', 'Tracking status', 'Sales order number', 'Department', 'Notification email address', 'Notification mobile number', 'Tags'];
    /** The Despatched Orders report's columns (no Company, no Department: the others move). */
    private const DESPATCHED = ['Order number', 'Batch number', 'Manifest number', 'Channel', 'Channel reference', 'Despatch date', 'Manifest date', 'Customer',
        'Address line 1', 'Address line 2', 'City', 'Postcode', 'Country', 'Weight (kg)', 'Package size', 'Shipping service', 'Service code', 'Tracking number',
        'Tracking status', 'Sales order number', 'Notification email address', 'Notification mobile number', 'Tags'];
    private const API = 'Test Shop Ltd - API (Test Shop Ltd)';
    private const WOO = 'Test Shop Ltd - WooCommerce (Test Shop Ltd)';
    private const MANUAL = 'Manual Order Entry (Test Shop Ltd)';
    private const CHANNELS = [self::API => 'api', self::WOO => 'woocommerce', self::MANUAL => 'manual'];
    /** Made-up personal values: none may come back. */
    private const PERSONAL = ['Testy McTestface', 'Fakeco Widgets', '1 Imaginary Road', 'Flat 99', 'Nowhereton', 'ZZ9 9ZZ', 'Neverland', 'nobody@example.invalid',
        '07000000000', 'Dept of Tests', 'tag-secret'];

    /** An Excel date of a UK clock time. */
    private static function serial(string $at): float
    {
        $t = new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
        $epoch = new \DateTimeImmutable('1899-12-30 00:00:00', new \DateTimeZone('UTC'));
        return ($t->getTimestamp() - $epoch->getTimestamp()) / 86400;
    }

    /**
     * A report row in a layout.
     *
     * @param list<string> $layout
     * @param array<string, mixed> $over
     * @return list<mixed>
     */
    private static function row(array $layout, array $over = []): array
    {
        $v = $over + ['Order number' => 779226.0, 'Batch number' => 'B774249', 'Manifest number' => 'M2317', 'Channel' => self::API, 'Channel reference' => '1381674',
            'Despatch date' => self::serial('2026-11-23 17:53:57'), 'Manifest date' => self::serial('2026-11-23 17:54:24'), 'Customer' => 'Testy McTestface',
            'Company' => 'Fakeco Widgets', 'Address line 1' => '1 Imaginary Road', 'Address line 2' => 'Flat 99', 'City' => 'Nowhereton', 'Postcode' => 'ZZ9 9ZZ',
            'Country' => 'Neverland', 'Weight (kg)' => 0.5, 'Package size' => 'Parcel', 'Shipping service' => 'Tracked 48 High Volume', 'Service code' => 'TPL48HV',
            'Tracking number' => 'JV000000001GB', 'Tracking status' => 'We\'re expecting your parcel', 'Sales order number' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee0001',
            'Department' => 'Dept of Tests', 'Notification email address' => 'nobody@example.invalid', 'Notification mobile number' => '07000000000', 'Tags' => 'tag-secret'];
        return array_map(static fn (string $c): mixed => $v[$c] ?? null, $layout);
    }

    /** @return list<list<mixed>> */
    private static function rows(array $layout): array
    {
        return [
            $layout,
            self::row($layout),
            // Special Delivery: 10-digit sales order kept, weight 0.1 kg
            self::row($layout, ['Tracking number' => 'as000000002gb', 'Service code' => 'sd1', 'Weight (kg)' => 0.1, 'Sales order number' => '5155465848',
                'Manifest number' => 'M2281', 'Manifest date' => self::serial('2026-11-05 22:13:15'), 'Order number' => 779227.0, 'Channel reference' => 1358046.0]),
            // Hayati: WooCommerce, # and digits
            self::row($layout, ['Tracking number' => 'OX000000003GB', 'Channel' => self::WOO, 'Channel reference' => '#2833', 'Service code' => 'TPM24HV',
                'Weight (kg)' => 0.225, 'Package size' => 'Large letter']),
            // Manual: a bare 4-digit reference kept as it is (the rules decide later), weight 1.0
            self::row($layout, ['Tracking number' => 'OX000000004GB', 'Channel' => self::MANUAL, 'Channel reference' => '2900', 'Weight (kg)' => 1.0,
                'Manifest number' => 2318.0, 'Manifest date' => '24/11/2026 09:05:00']),
            // a free-text reference, an unknown channel, an odd package, no weight, a manifest number with no date
            self::row($layout, ['Tracking number' => 'JV000000005GB', 'Channel' => 'Some Other Shop Channel', 'Channel reference' => 'man return Test Person',
                'Package size' => 'Box', 'Weight (kg)' => 'heavy', 'Manifest date' => null, 'Service code' => 'tpl 48']),
            // no tracking number: skipped; a bad one: skipped; the first one again: skipped
            self::row($layout, ['Tracking number' => null]),
            self::row($layout, ['Tracking number' => 'not a number!']),
            self::row($layout, ['Tracking number' => 'JV000000001GB', 'Channel reference' => '9999999']),
            // empty channel and package
            self::row($layout, ['Tracking number' => 'JV000000006GB', 'Channel' => '', 'Package size' => '', 'Order number' => 'x']),
        ];
    }

    public function testTheReportIsReadByNameIntoLabelsInTheirShapesOnly(): void
    {
        foreach (['manifested' => self::MANIFESTED, 'despatched' => self::DESPATCHED] as $name => $layout) {
            $bytes = XlsWriter::file(self::rows($layout));
            $r = ClickAndDropReport::read($bytes, self::CHANNELS);
            self::assertSame(9, $r['rows'], $name);
            self::assertSame(3, $r['skipped'], "{$name}: no tracking, a bad one, a second one");
            self::assertSame(hash('sha256', $bytes), $r['sha256']);
            $by = array_column($r['labels'], null, 'tracking_no');
            self::assertSame(['JV000000001GB', 'AS000000002GB', 'OX000000003GB', 'OX000000004GB', 'JV000000005GB', 'JV000000006GB'], array_keys($by), $name);
            self::assertSame(['tracking_no' => 'JV000000001GB', 'source_id' => 779226, 'reference' => '1381674', 'channel_code' => 'api', 'service_code' => 'TPL48HV',
                'package' => 'parcel', 'weight_g' => 500, 'created_on' => null, 'manifested_at' => '2026-11-23 17:54:24.000000', 'manifest_no' => 2317,
                'manifest_day' => '2026-11-23', 'sales_order' => null], $by['JV000000001GB'], "{$name}: the first row kept, its uuid-like sales order not");
            self::assertSame(['AS000000002GB', 779227, '1358046', 'SD1', 100, 2281, '2026-11-05 22:13:15.000000', '2026-11-05', '5155465848'],
                [$by['AS000000002GB']['tracking_no'], $by['AS000000002GB']['source_id'], $by['AS000000002GB']['reference'], $by['AS000000002GB']['service_code'],
                    $by['AS000000002GB']['weight_g'], $by['AS000000002GB']['manifest_no'], $by['AS000000002GB']['manifested_at'], $by['AS000000002GB']['manifest_day'],
                    $by['AS000000002GB']['sales_order']], $name);
            self::assertSame(['#2833', 'woocommerce', 225, 'large_letter', 'TPM24HV'], [$by['OX000000003GB']['reference'], $by['OX000000003GB']['channel_code'],
                $by['OX000000003GB']['weight_g'], $by['OX000000003GB']['package'], $by['OX000000003GB']['service_code']], $name);
            self::assertSame(['2900', 'manual', 1000, 2318, '2026-11-24 09:05:00.000000', '2026-11-24'], [$by['OX000000004GB']['reference'],
                $by['OX000000004GB']['channel_code'], $by['OX000000004GB']['weight_g'], $by['OX000000004GB']['manifest_no'], $by['OX000000004GB']['manifested_at'],
                $by['OX000000004GB']['manifest_day']], $name);
            self::assertSame([null, 'other', 'other', null, null, null, null, null], [$by['JV000000005GB']['reference'], $by['JV000000005GB']['channel_code'],
                $by['JV000000005GB']['package'], $by['JV000000005GB']['weight_g'], $by['JV000000005GB']['manifest_no'], $by['JV000000005GB']['manifested_at'],
                $by['JV000000005GB']['manifest_day'], $by['JV000000005GB']['service_code']], "{$name}: free text, unknown words, a manifest with no date");
            self::assertSame([null, null, null], [$by['JV000000006GB']['channel_code'], $by['JV000000006GB']['package'], $by['JV000000006GB']['source_id']], $name);
        }
    }

    public function testThePersonalColumnsAreNeverReturned(): void
    {
        $r = ClickAndDropReport::read(XlsWriter::file(self::rows(self::MANIFESTED)), self::CHANNELS);
        $keys = ['tracking_no', 'source_id', 'reference', 'channel_code', 'service_code', 'package', 'weight_g', 'created_on', 'manifested_at', 'manifest_no',
            'manifest_day', 'sales_order'];
        foreach ($r['labels'] as $l) {
            self::assertSame($keys, array_keys($l), 'exactly the kept fields');
        }
        $all = json_encode($r, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ([...self::PERSONAL, 'Test Shop', 'B774249', 'expecting', 'Tracked 48 High Volume', 'Test Person'] as $p) {
            self::assertStringNotContainsString($p, $all, 'never returned: ' . $p);
        }
    }

    public function testAMissingColumnIsRefusedByItsName(): void
    {
        foreach (['Tracking number', 'Sales order number', 'Manifest date', 'Weight (kg)'] as $gone) {
            $layout = array_values(array_filter(self::MANIFESTED, static fn (string $c): bool => $c !== $gone));
            try {
                ClickAndDropReport::read(XlsWriter::file(self::rows($layout)), self::CHANNELS);
                self::fail("no {$gone}: refused");
            } catch (CwException $e) {
                self::assertSame('bad_file', $e->errorCode);
                self::assertSame("Click & Drop report not read: it has no column '{$gone}': is it Click & Drop's Manifested Orders report? Nothing was saved.",
                    $e->getMessage());
            }
        }
        $twice = [...self::MANIFESTED, 'Tracking number'];
        try {
            ClickAndDropReport::read(XlsWriter::file([$twice, self::row($twice)]), self::CHANNELS);
            self::fail('a column twice: refused');
        } catch (CwException $e) {
            self::assertStringContainsString("the column 'Tracking number' is there twice", $e->getMessage());
            self::assertStringNotContainsString('JV000000001GB', $e->getMessage());
        }
    }

    public function testAHeaderOnlyReportHasNoLabels(): void
    {
        $r = ClickAndDropReport::read(XlsWriter::file([self::MANIFESTED]), self::CHANNELS);
        self::assertSame([0, 0, []], [$r['rows'], $r['skipped'], $r['labels']]);
    }

    public function testAFileThatIsNotAReportIsRefused(): void
    {
        foreach (['garbage' => random_bytes(2048), 'text' => "Order number\tTracking number\n", 'cut' => substr(XlsWriter::file(self::rows(self::MANIFESTED)), 0, 700)]
            as $why => $bytes) {
            try {
                ClickAndDropReport::read($bytes, self::CHANNELS);
                self::fail("{$why}: refused");
            } catch (CwException $e) {
                self::assertSame('bad_file', $e->errorCode, $why);
                self::assertStringStartsWith('Click & Drop report not read: ', $e->getMessage());
            }
        }
    }
}
