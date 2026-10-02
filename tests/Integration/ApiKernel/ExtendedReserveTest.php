<?php

declare(strict_types=1);

namespace CW\Tests\Integration\ApiKernel;

use CW\Tests\Support\ApiKernelTestCase;

/** A17 (F8): a 200 `extended` reserve carries the same per-line payload (lines[], kind, ...) as a fresh 201. */
final class ExtendedReserveTest extends ApiKernelTestCase
{
    public function testAnExtendedHoldAnswersWithTheFreshHoldsLines(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'live');
        $strict = $this->item('strict', 10);
        $legacy = $this->item('legacy', 4);
        $pack = $this->item('backorder', 7);
        $this->listing($site, 'V-STRICT', $strict);
        $this->listing($site, 'V-QUAR', $legacy, 1, 'quarantined'); // a legacy line is never refused (R8)
        $this->listing($site, 'V-PACK', $pack, 2);
        $this->listing($site, 'V-NEW', null);
        $order = ['order_ref' => 'X1', 'lines' => [
            self::line('V-STRICT', 's1', 's2'), self::line('V-QUAR', 'q1'), self::line('V-PACK', 'p1'), self::line('V-NEW', 'n1'),
        ]];

        $fresh = self::data($this->call('POST', '/v1/reservations', $key, $order), 201);
        self::assertSame(['held', 'held', 1], [$fresh['result'], $fresh['status'], $fresh['attempt']]);
        $byVariant = array_column($fresh['lines'], null, 'variant_id');
        self::assertSame(['strict', 'held', 8, false], [$byVariant['V-STRICT']['kind'], $byVariant['V-STRICT']['result'],
            $byVariant['V-STRICT']['available'], $byVariant['V-STRICT']['quarantined']]);
        self::assertSame(['legacy', true], [$byVariant['V-QUAR']['kind'], $byVariant['V-QUAR']['quarantined']]);
        self::assertSame(['backorder', 2, 2], [$byVariant['V-PACK']['kind'], $byVariant['V-PACK']['units_per_item'], $byVariant['V-PACK']['available']]);
        self::assertSame(['unlinked', 'unlinked', null, null], [$byVariant['V-NEW']['kind'], $byVariant['V-NEW']['result'],
            $byVariant['V-NEW']['sku_code'], $byVariant['V-NEW']['available']]);
        $ledger = $this->ledgerCount();

        // The same order again (another key, lines and units in another order): extended, same lines.
        $again = ['order_ref' => 'X1', 'lines' => [
            self::line('V-NEW', 'n1'), self::line('V-PACK', 'p1'), self::line('V-QUAR', 'q1'), self::line('V-STRICT', 's2', 's1'),
        ]];
        $ext = $this->call('POST', '/v1/reservations', $key, $again, 'ext-1');
        $d = self::data($ext);
        self::assertSame(['extended', 'held', 1], [$d['result'], $d['status'], $d['attempt']]);
        self::assertSame(array_keys($fresh), array_keys($d), 'the same top-level keys as a fresh hold');
        self::assertSame($fresh['lines'], $d['lines'], 'the same lines, in the same order, with the same values');
        foreach ($d['lines'] as $i => $line) {
            self::assertSame(array_keys($fresh['lines'][$i]), array_keys($line));
        }
        self::assertSame($ledger, $this->ledgerCount(), 'an extension moves no bucket');
        $this->assertBal(10, 0, 2, $strict);

        // Values are current: more stock and a new policy show on the next extension.
        $this->book('goods_in', $strict, 5);
        $this->ok($this->stock->setPolicy(self::staff(), $pack, 'strict', $this->key('policy')));
        $d = self::data($this->call('POST', '/v1/reservations', $key, $order));
        $now = array_column($d['lines'], null, 'variant_id');
        self::assertSame([13, 'held'], [$now['V-STRICT']['available'], $now['V-STRICT']['result']]);
        self::assertSame(['strict', 'held', 2], [$now['V-PACK']['kind'], $now['V-PACK']['result'], $now['V-PACK']['available']]);
        self::assertSame($byVariant['V-NEW'], $now['V-NEW']);

        // A replay of the extension answers what was stored.
        $replay = $this->call('POST', '/v1/reservations', $key, $again, 'ext-1');
        self::assertSame('true', self::header($replay, 'Idempotent-Replayed'));
        self::assertSame($ext->json(), $replay->json());
    }

    public function testExtensionInProcessKeepsTheHoldsSnapshot(): void
    {
        $site = $this->site('vpg', 'live');
        $sku = $this->item('strict', 9);
        $listing = $this->listing($site, 'V1', $sku, 3);
        $fresh = $this->ok($this->reserve($site, '4001', [self::line('V1', 'a')]));
        self::assertSame(201, $fresh->status);

        // The listing's u changes after the hold: the extension reports the hold as it sits (u 3).
        self::$db->exec('UPDATE channel_listing SET units_per_item = 1 WHERE id = ?', [$listing]);
        $ext = $this->ok($this->reserve($site, '4001', [self::line('V1', 'a')]));
        self::assertSame([200, 'extended'], [$ext->status, $ext->body['result']]);
        self::assertSame($fresh->body['lines'], $ext->body['lines']);
        self::assertSame([3, 2], [$ext->body['lines'][0]['units_per_item'], $ext->body['lines'][0]['available']]);
        $this->assertBal(9, 0, 3, $sku);
    }
}
