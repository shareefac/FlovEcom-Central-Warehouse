<?php

declare(strict_types=1);

namespace CW\Tests\Integration\ApiKernel;

use CW\Tests\Support\ApiKernelTestCase;

/**
 * The relay's two queue routes through the real /v1 kernel (pack F; docs/decisions.md PF2-PF4): they need the store's grant (403
 * relay_not_allowed, not stored), an Idempotency-Key (400), and the store switched on (409 channel_off: it keeps its messages);
 * their answers are stored and replayed like every POST.
 */
final class RelayRoutesTest extends ApiKernelTestCase
{
    public function testTheQueueRoutesAreGuardedLikeEveryRelayedMessage(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'shadow');
        $sku = $this->item('strict', 4);
        $this->listing($site, 'V1', $sku);
        $reco = ['doc_ref' => 'MAT-RECO-1', 'lines' => [['variant_id' => 'V1', 'qty' => 2]]];
        $mode = ['doc_ref' => 'vpg-mode-9', 'lines' => [['variant_id' => 'V1', 'mode' => 'In-Stock']]];

        self::envelope($this->call('POST', '/v1/relay/reconciliations', $key, $reco), 403, 'relay_not_allowed');
        self::envelope($this->call('POST', '/v1/relay/modes', $key, $mode), 403, 'relay_not_allowed');
        $this->grant($site, 'erp_reconciliation', 'erp_mode');
        self::envelope($this->call('POST', '/v1/relay/modes', $key, $mode, ''), 400, 'idempotency_key_required');

        $first = $this->call('POST', '/v1/relay/reconciliations', $key, $reco, 'reco-key-1');
        $d = self::data($first);
        self::assertSame('recorded', $d['result']);
        self::assertSame('queued', $d['lines'][0]['result']);
        self::assertSame('shadow', self::header($first, 'X-CW-Channel-Mode'));
        $replay = $this->call('POST', '/v1/relay/reconciliations', $key, $reco, 'reco-key-1');
        self::assertSame($first->json(), $replay->json(), 'the same key replays the stored answer');
        self::assertSame('queued', self::data($this->call('POST', '/v1/relay/modes', $key, $mode))['lines'][0]['result'], 'strict -> backorder is a change');

        self::$db->exec("UPDATE channel SET mode = 'off' WHERE id = ?", [$site->channelId]);
        self::envelope($this->call('POST', '/v1/relay/modes', $key, ['doc_ref' => 'vpg-mode-10', 'lines' => [['variant_id' => 'V1', 'mode' => 'Out-Of-Stock']]]),
            409, 'channel_off');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM policy_review'));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE path LIKE '/v1/relay/%' AND response_status >= 400"));
        self::envelope($this->call('GET', '/v1/relay/modes', $key), 405, 'method_not_allowed');
    }
}
