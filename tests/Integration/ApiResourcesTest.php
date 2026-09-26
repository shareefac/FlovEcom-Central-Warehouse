<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Clock;
use CW\Tests\Support\ApiTestCase;
use DateTimeImmutable;

/** POST /v1/movements, PUT /v1/listings, POST /v1/heartbeat, GET /v1/purchasing over HTTP. */
final class ApiResourcesTest extends ApiTestCase
{
    public function testMovementsFromTheErpRelay(): void
    {
        [$site, $key] = $this->apiSite();
        $this->grant($site, 'goods_in', 'supplier_return', 'erp_sale');
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'V10', $sku, 10);

        // Goods-in: the same variant twice books the sum; an unknown variant lands in suspense.
        $r = self::assertEnvelope($this->call('POST', '/v1/movements', $key, ['type' => 'goods_in', 'doc_ref' => 'PINV-0001', 'lines' => [
            ['variant_id' => 'V1', 'qty' => 5, 'line_index' => 0],
            ['variant_id' => 'V1', 'qty' => 2, 'line_index' => 1],
            ['variant_id' => 'V10', 'qty' => 1, 'line_index' => 2],
            ['variant_id' => 'GHOST', 'qty' => 3, 'line_index' => 3],
        ]]), 200);
        self::assertSame(['booked', 'booked', 'booked', 'suspense'], array_column($r->data()['lines'], 'result'));
        $this->assertBal(17, 0, 0, $sku);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM goods_in_suspense WHERE doc_ref = 'PINV-0001'"));
        // Debit note and ERP sale take the sign from the type, whatever the sender sent.
        self::assertEnvelope($this->call('POST', '/v1/movements', $key, ['type' => 'supplier_return', 'doc_ref' => 'DN-1', 'lines' => [['variant_id' => 'V1', 'qty' => -2]]]), 200);
        self::assertEnvelope($this->call('POST', '/v1/movements', $key, ['type' => 'erp_sale', 'doc_ref' => 'SINV-1', 'lines' => [['variant_id' => 'V1', 'qty' => 1]]]), 200);
        $this->assertBal(14, 0, 0, $sku);
        // An unknown type, a missing doc_ref and an unknown warehouse are refused.
        self::assertEnvelope($this->call('POST', '/v1/movements', $key, ['type' => 'teleport', 'lines' => [['variant_id' => 'V1', 'qty' => 3]]]), 400, 'bad_type');
        self::assertEnvelope($this->call('POST', '/v1/movements', $key, ['type' => 'goods_in', 'lines' => [['variant_id' => 'V1', 'qty' => 3]]]), 400, 'doc_ref_required');
        self::assertEnvelope($this->call('POST', '/v1/movements', $key, ['type' => 'goods_in', 'doc_ref' => 'X', 'warehouse' => 'NOWHERE',
            'lines' => [['variant_id' => 'V1', 'qty' => 3]]]), 422, 'unknown_warehouse');
        // Counts, adjustments, write-offs and transfers are staff-only, whatever the site is granted (R17).
        foreach ([['type' => 'count', 'counted_at' => self::ago(600), 'lines' => [['variant_id' => 'V1', 'qty' => 9]]],
            ['type' => 'adjustment', 'lines' => [['variant_id' => 'V1', 'qty' => -1]]],
            ['type' => 'write_off', 'lines' => [['variant_id' => 'V1', 'qty' => 1]]],
            ['type' => 'transfer_out', 'lines' => [['variant_id' => 'V1', 'qty' => 1]]]] as $req) {
            self::assertEnvelope($this->call('POST', '/v1/movements', $key, $req), 403, 'movement_not_allowed');
        }
        $this->assertBal(14, 0, 0, $sku);
        self::assertSame('channel:vpg', self::$db->value("SELECT actor FROM stock_ledger WHERE movement_type = 'erp_sale'"));
        self::assertSame('127.0.0.1', self::$db->value("SELECT ip FROM audit_log WHERE action = 'movement.erp_sale'"));
    }

    /**
     * Least privilege (R17): a site that is not the ERP relay (no grant, the default) cannot book
     * stock at all, not even on items its own listings link to; one granted goods_in only cannot
     * send an ERP sale.
     */
    public function testASiteSendsOnlyTheMovementTypesItsChannelIsGranted(): void
    {
        [$site, $key] = $this->apiSite();
        [$other, $otherKey] = $this->apiSite('vapebig');
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->listing($other, 'B1', $sku);
        self::assertEnvelope($this->call('POST', '/v1/movements', $otherKey, ['type' => 'goods_in', 'doc_ref' => 'P-1',
            'lines' => [['variant_id' => 'B1', 'qty' => 5]]]), 403, 'movement_not_allowed');
        self::assertEnvelope($this->call('POST', '/v1/movements', $otherKey, ['type' => 'count', 'counted_at' => self::ago(60),
            'lines' => [['sku_id' => $sku, 'qty' => 99]]]), 403, 'movement_not_allowed');
        $this->grant($site, 'goods_in');
        self::assertEnvelope($this->call('POST', '/v1/movements', $key, ['type' => 'erp_sale', 'doc_ref' => 'S-1',
            'lines' => [['variant_id' => 'V1', 'qty' => 5]]]), 403, 'movement_not_allowed');
        self::assertEnvelope($this->call('POST', '/v1/movements', $key, ['type' => 'goods_in', 'doc_ref' => 'P-2',
            'lines' => [['variant_id' => 'V1', 'qty' => 1]]]), 200);
        $this->assertBal(11, 0, 0, $sku);
        self::assertNull(self::$db->value('SELECT counted_at FROM stock_balance WHERE sku_id = ?', [$sku]));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE path = '/v1/movements' AND response_status = 403"),
            'a refusal is not stored');
    }

    public function testLongNonAsciiDocumentRefsAreBooked(): void
    {
        [$site, $key] = $this->apiSite();
        $this->grant($site, 'goods_in');
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku);
        // audit_log.entity_id keeps 64 bytes of the doc_ref: 'é' straddles that boundary.
        $ref64 = str_repeat('A', 63) . "\u{e9}-1";
        self::assertEnvelope($this->call('POST', '/v1/movements', $key, ['type' => 'goods_in', 'doc_ref' => $ref64,
            'lines' => [['variant_id' => 'V1', 'qty' => 2]]]), 200);
        // The suspense dedupe key "goods_in:<doc_ref>#<line>" keeps 191 bytes: 'é' straddles that one.
        $ref191 = str_repeat('B', 181) . "\u{e9}" . str_repeat('C', 8);
        self::assertSame(191, strlen($ref191));
        $r = self::assertEnvelope($this->call('POST', '/v1/movements', $key, ['type' => 'goods_in', 'doc_ref' => $ref191,
            'lines' => [['variant_id' => 'V1', 'qty' => 1], ['variant_id' => 'GHOST', 'qty' => 1]]]), 200);
        self::assertSame(['booked', 'suspense'], array_column($r->data()['lines'], 'result'));
        $this->assertBal(3, 0, 0, $sku);
        self::assertSame($ref191, self::$db->value('SELECT doc_ref FROM goods_in_suspense'));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE entity_id = ?', [str_repeat('A', 63)]));
    }

    public function testListingsPutWritesProfilesOnly(): void
    {
        [$site, $key] = $this->apiSite();
        $sku = $this->item('strict', 5);
        $mapped = $this->listing($site, 'V1', $sku, 2);
        $push = ['listings' => [
            ['variant_id' => 'V1', 'product_title' => 'Elux Legend 3500', 'variant_title' => 'Blue Razz', 'brand' => 'Elux',
                'attributes' => ['strength' => '20mg', 'colour' => 'blue'], 'barcodes' => ['5056', '5055', '5056'], 'price' => '9.99',
                'perma_link' => 'https://example.test/elux', 'units_30d' => 12, 'units_365d' => 140],
            ['variant_id' => 777, 'product_title' => str_repeat('é', 600), 'price' => 4],
        ]];
        $r = self::assertEnvelope($this->call('PUT', '/v1/listings', $key, $push), 200);
        self::assertSame([2, 2, 0, 0], [$r->data()['received'], $r->data()['created'], $r->data()['updated'], $r->data()['unchanged']]);
        $p = self::$db->one('SELECT * FROM listing_profile WHERE listing_id = ?', [$mapped]);
        self::assertSame(['Elux Legend 3500', 'Elux', '9.99', 12], [$p['product_title'], $p['brand'], $p['price'], $p['units_30d']]);
        self::assertSame(['5055', '5056'], json_decode((string) $p['barcodes'], true));
        // The link is untouched; the unknown variant got an unmapped listing row.
        $link = self::$db->one('SELECT sku_id, status, units_per_item FROM channel_listing WHERE id = ?', [$mapped]);
        self::assertSame([$sku, 'mapped', 2], [$link['sku_id'], $link['status'], $link['units_per_item']]);
        $new = self::$db->one("SELECT l.status, l.sku_id, CHAR_LENGTH(p.product_title) AS len FROM channel_listing l JOIN listing_profile p ON p.listing_id = l.id WHERE l.external_variant_id = '777'");
        self::assertSame(['unmapped', null, 512], [$new['status'], $new['sku_id'], $new['len']]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_change WHERE listing_id IS NOT NULL'), 'no link change, no feed row');

        // Same push: unchanged. Barcodes in another order are the same profile.
        $push['listings'][0]['barcodes'] = ['5055', '5056'];
        $r = self::assertEnvelope($this->call('PUT', '/v1/listings', $key, $push), 200);
        self::assertSame([0, 0, 2], [$r->data()['created'], $r->data()['updated'], $r->data()['unchanged']]);
        // A price change is an update, not an identity change; a title change is both.
        $push['listings'][0]['price'] = 8.5;
        $push['listings'][1]['product_title'] = 'Renamed';
        $r = self::assertEnvelope($this->call('PUT', '/v1/listings', $key, $push), 200);
        self::assertSame(['777' => true, 'V1' => false], array_column($r->data()['listings'], 'identity_changed', 'variant_id'));
        self::assertSame(['777' => 'updated', 'V1' => 'updated'], array_column($r->data()['listings'], 'result', 'variant_id'));
        self::assertSame('8.50', self::$db->value('SELECT price FROM listing_profile WHERE listing_id = ?', [$mapped]));
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'listing.profiles'"));

        foreach ([
            ['listings' => []], ['listings' => ['a' => 1]], ['nope' => 1],
            ['listings' => [['product_title' => 'no id']]],
            ['listings' => [['variant_id' => 'A'], ['variant_id' => 'A']]],
            ['listings' => [['variant_id' => 'A', 'price' => -1]]],
            ['listings' => [['variant_id' => 'A', 'price' => 'cheap']]],
            ['listings' => [['variant_id' => 'A', 'barcodes' => 'x']]],
            ['listings' => [['variant_id' => 'A', 'attributes' => ['x', 'y']]]],
            ['listings' => [['variant_id' => 'A', 'units_30d' => -1]]],
            ['listings' => [['variant_id' => 'A', 'brand' => ['x']]]],
        ] as $bad) {
            self::assertEnvelope($this->call('PUT', '/v1/listings', $key, $bad), 400, 'bad_listings');
        }
        $many = ['listings' => array_map(static fn (int $i): array => ['variant_id' => 'M' . $i], range(1, 1001))];
        self::assertEnvelope($this->call('PUT', '/v1/listings', $key, $many), 413, 'bad_listings');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM listing_profile'));
    }

    public function testHeartbeat(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'shadow');
        $sku = $this->item('strict', 3);
        $r = self::assertEnvelope($this->call('POST', '/v1/heartbeat', $key, [
            'outbox_depth' => 4, 'outbox_oldest_age_sec' => 30, 'dead_letters' => 0, 'last_seq' => 12,
            'site_mode' => 'live', 'connector_version' => '1.0.3', 'php' => '7.4',
        ], 'hb-1'), 200);
        self::assertSame('shadow', $r->data()['mode'], 'CW\'s mode for the channel: the site takes the lower one');
        self::assertSame((int) self::$db->value('SELECT MAX(seq) FROM stock_change'), $r->data()['feed_seq']);
        $row = self::$db->one('SELECT * FROM channel_health WHERE channel_id = ?', [$site->channelId]);
        self::assertSame([4, 30, 0, 12, 'live', '1.0.3', '127.0.0.1'],
            [$row['outbox_depth'], $row['outbox_oldest_age_sec'], $row['dead_letters'], $row['last_seq'], $row['site_mode'],
                $row['connector_version'], $row['remote_ip']]);
        self::assertSame('7.4', json_decode((string) $row['payload'], true)['php']);
        // An empty heartbeat is fine; bad fields are refused.
        self::assertEnvelope($this->call('POST', '/v1/heartbeat', $key, (object) []), 200);
        foreach ([['site_mode' => 'on'], ['outbox_depth' => -1], ['dead_letters' => '3'], ['connector_version' => str_repeat('v', 33)]] as $bad) {
            self::assertEnvelope($this->call('POST', '/v1/heartbeat', $key, $bad), 400, 'bad_request');
        }
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM channel_health'));
        unset($sku);
    }

    public function testPurchasingReportsStockAndDemandBySite(): void
    {
        // /v1/purchasing runs in php-fpm on the real clock (90-day window from UTC_TIMESTAMP), so this
        // test books its sales and count on the real clock too; a pinned 26 Sep date would drift out of
        // the window after 90 days.
        $real = new DateTimeImmutable('now', Clock::utc());
        $this->now = $real->setTime((int) $real->format('H'), (int) $real->format('i'), (int) $real->format('s'));
        $countAt = $this->now->modify('-1 hour');
        [$vpg, $key] = $this->apiSite('vpg');
        [$big, $bigKey] = $this->apiSite('vapebig');
        $a = $this->item('strict', 20, 'Item A');
        $b = $this->item('legacy', 0, 'Item B');
        $this->listing($vpg, 'A1', $a);
        $this->listing($vpg, 'A10', $a, 10);
        $this->listing($big, 'BA', $a);
        $this->commit($vpg, '1', [self::line('A1', 'u1', 'u2')]);
        $this->commit($vpg, '2', [self::line('A10', 'u3')]);
        $this->commit($big, '3', [self::line('BA', 'x1')]);
        $this->reserve($big, '4', [self::line('BA', 'x2')]);
        $this->res->cancel($vpg, '1', ['u2'], false, $this->key());
        $this->book('count', $a, 19, 'MAIN', $countAt->format('Y-m-d\\TH:i:s\\Z'));

        $r = self::assertEnvelope($this->call('GET', '/v1/purchasing', $key), 200);
        $items = array_column($r->data()['items'], null, 'sku_id');
        self::assertNull($r->data()['next_after_sku']);
        $ia = $items[$a];
        self::assertSame(['strict', 19, 12, 1, 6, 1], [$ia['policy'], $ia['on_hand'], $ia['allocated'], $ia['held'], $ia['available'], $ia['non_sellable_on_hand']]);
        self::assertSame(['vapebig' => 1, 'vpg' => 11], $ia['units_90d']);
        self::assertSame(['legacy', 0, []], [$items[$b]['policy'], $items[$b]['on_hand'], $items[$b]['units_90d']]);
        // counted_at: the item's latest count at a sellable warehouse (R11); never counted = null
        self::assertSame([$countAt->format('Y-m-d\\TH:i:s.000000\\Z'), null], [$ia['counted_at'], $items[$b]['counted_at']]);
        self::assertSame('{}', json_encode(json_decode($r->raw)->data->items[1]->units_90d), 'no sales is an empty object');
        // Paged by sku id; any authenticated site may read it.
        $page = self::assertEnvelope($this->call('GET', '/v1/purchasing?limit=1', $bigKey), 200)->data();
        self::assertSame([$a], array_column($page['items'], 'sku_id'));
        self::assertSame($a, $page['next_after_sku']);
        $page = self::assertEnvelope($this->call('GET', '/v1/purchasing?limit=1&after_sku=' . $a, $bigKey), 200)->data();
        self::assertSame([$b], array_column($page['items'], 'sku_id'));
    }
}
