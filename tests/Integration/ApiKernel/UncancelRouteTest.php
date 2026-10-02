<?php

declare(strict_types=1);

namespace CW\Tests\Integration\ApiKernel;

use CW\Api\Kernel;
use CW\Tests\Support\ApiKernelTestCase;

/** POST /v1/reservations/{ref}/uncancel {unit_ids} through the real kernel (F7, D46). */
final class UncancelRouteTest extends ApiKernelTestCase
{
    public function testTheRouteTakesCancelledUnitsBack(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'live');
        $sku = $this->item('strict', 3);
        $this->listing($site, 'V1', $sku);
        self::data($this->call('POST', '/v1/reservations/U1/commit', $key, ['lines' => [self::line('V1', 'a', 'b')]]));
        self::data($this->call('POST', '/v1/reservations/U1/cancel', $key, ['unit_ids' => ['a'], 'restockable' => true]));
        self::data($this->call('POST', '/v1/reservations/U1/cancel', $key, ['unit_ids' => ['b'], 'restockable' => false]));
        self::data($this->call('POST', '/v1/reservations/U2/commit', $key, ['lines' => [self::line('V1', 'c', 'd')]]));
        $this->assertBal(2, 2, 0, $sku);

        $r = $this->call('POST', '/v1/reservations/U1/uncancel', $key, ['unit_ids' => ['b', 'a', 'zz']], 'unc-U1');
        $d = self::data($r);
        self::assertSame(['order_ref', 'oversell', 'status', 'units'], array_keys($d), 'canonical (sorted) keys, A1');
        self::assertSame(['U1', 'committed'], [$d['order_ref'], $d['status']]);
        self::assertSame([['result' => 'uncancelled', 'unit_id' => 'a'], ['result' => 'uncancelled_from_verify', 'unit_id' => 'b'],
            ['result' => 'unknown_unit', 'unit_id' => 'zz']], $d['units']);
        self::assertSame([['available_after' => -1, 'kind' => 'uncancel_short', 'shortfall' => 1,
            'sku_code' => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku])]], $d['oversell']);
        self::assertSame('live', self::header($r, Kernel::MODE_HEADER));
        $this->assertBal(3, 4, 0, $sku);
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');

        // a replay (units in another order) is byte-identical; another body under the key is 422
        $again = $this->call('POST', '/v1/reservations/U1/uncancel', $key, ['unit_ids' => ['zz', 'a', 'b']], 'unc-U1');
        self::assertSame('true', self::header($again, 'Idempotent-Replayed'));
        self::assertSame($r->json(), $again->json());
        self::envelope($this->call('POST', '/v1/reservations/U1/uncancel', $key, ['unit_ids' => ['a']], 'unc-U1'), 422, 'idempotency_key_reused');
        $this->assertBal(3, 4, 0, $sku);
    }

    public function testBadBodiesUnknownAndUnpaidOrdersAndAnOffChannel(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'shadow');
        $this->listing($site, 'V1', $this->item('strict', 3));
        self::envelope($this->call('POST', '/v1/reservations/U1/uncancel', $key, (object) []), 400, 'bad_unit_ids');
        self::envelope($this->call('POST', '/v1/reservations/U1/uncancel', $key, ['unit_ids' => []]), 400, 'bad_unit_ids');
        self::envelope($this->call('POST', '/v1/reservations/U1/uncancel', $key, ['unit_ids' => ['a']], ''), 400, 'idempotency_key_required');
        self::envelope($this->call('POST', '/v1/reservations/NOPE/uncancel', $key, ['unit_ids' => ['a']], 'k-nope'), 404, 'unknown_order');
        self::data($this->call('POST', '/v1/reservations', $key, ['order_ref' => 'H1', 'lines' => [self::line('V1', 'h')]]), 201);
        self::envelope($this->call('POST', '/v1/reservations/H1/uncancel', $key, ['unit_ids' => ['h']], 'k-held'), 409, 'not_committed');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE idem_key LIKE 'k-%'"), 'refusals are not stored (D27)');

        self::$db->exec("UPDATE channel SET mode = 'off' WHERE id = ?", [$site->channelId]);
        $r = $this->call('POST', '/v1/reservations/H1/uncancel', $key, ['unit_ids' => ['h']]);
        self::envelope($r, 409, 'channel_off');
        self::assertSame('off', self::header($r, Kernel::MODE_HEADER));
        self::assertTrue($this->kernel()->router()->match('POST', '/v1/reservations/H1/uncancel')[0]->stockWrite);
    }
}
