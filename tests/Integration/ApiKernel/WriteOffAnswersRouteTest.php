<?php

declare(strict_types=1);

namespace CW\Tests\Integration\ApiKernel;

use CW\Clock;
use CW\Tests\Support\ApiKernelTestCase;

/**
 * CW-1180 NR10 through the real API kernel: a not-restockable cancel written off at once, its take-back and an uncancel after a count answer
 * with results the shops' connectors (0.4.17, unit_sweep.php CW_US_EXPECTED_RESULTS) already know, and the precise outcome in an ADDITIVE
 * per-unit field `outcome` those connectors ignore; the way before (VERIFY) answers exactly as it always did, with no outcome. A replay is the
 * stored answer, outcome included.
 */
final class WriteOffAnswersRouteTest extends ApiKernelTestCase
{
    /** The per-unit results the shops' connectors expect (connector 0.4.17, CW_US_EXPECTED_RESULTS): anything else is logged as an anomaly. */
    private const CONNECTOR_EXPECTS = [
        'cancel' => ['cancelled', 'cancelled_to_verify', 'already_cancelled'],
        'uncancel' => ['uncancelled', 'uncancelled_from_verify', 'uncancelled_after_verify', 'not_cancelled'],
    ];

    /** @param array<string, mixed> $d */
    private static function knownToTheConnector(string $kind, array $d): void
    {
        foreach ($d['units'] as $u) {
            self::assertContains($u['result'], self::CONNECTOR_EXPECTS[$kind], "{$kind} {$u['unit_id']}: a result the shops' connectors know");
        }
    }

    public function testTheAnswersAreOnesTheConnectorsKnowWithThePreciseOutcomeBesideThem(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'live');
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        self::data($this->call('POST', '/v1/reservations/W1/commit', $key, ['lines' => [self::line('V1', 'a', 'b', 'c', 'd')]]));

        // written off at once: result "cancelled" (as a restockable cancel), outcome "written_off"
        $r = $this->call('POST', '/v1/reservations/W1/cancel', $key, ['unit_ids' => ['a'], 'restockable' => false], 'cnl-W1-a');
        $d = self::data($r);
        self::assertSame([['outcome' => 'written_off', 'result' => 'cancelled', 'unit_id' => 'a']], $d['units']);
        self::knownToTheConnector('cancel', $d);
        $this->assertBal(9, 3, 0, $sku);
        // a replay is the stored answer, its outcome included, byte for byte
        $again = $this->call('POST', '/v1/reservations/W1/cancel', $key, ['unit_ids' => ['a'], 'restockable' => false], 'cnl-W1-a');
        self::assertSame('true', self::header($again, 'Idempotent-Replayed'));
        self::assertSame($r->json(), $again->json());
        // a restockable cancel: no outcome, as before
        $d = self::data($this->call('POST', '/v1/reservations/W1/cancel', $key, ['unit_ids' => ['b'], 'restockable' => true]));
        self::assertSame([['result' => 'cancelled', 'unit_id' => 'b']], $d['units']);

        // taken back exactly: result "uncancelled", outcome "write_off_taken_back"
        $d = self::data($this->call('POST', '/v1/reservations/W1/uncancel', $key, ['unit_ids' => ['a', 'b']]));
        self::assertSame([['outcome' => 'write_off_taken_back', 'result' => 'uncancelled', 'unit_id' => 'a'], ['result' => 'uncancelled', 'unit_id' => 'b']],
            $d['units']);
        self::assertSame([], $d['oversell']);
        self::knownToTheConnector('uncancel', $d);
        $this->assertBal(10, 4, 0, $sku);

        // written off, the item counted at MAIN since, then taken back: "uncancelled_after_verify" (the shop's "a person should look"
        // warning), outcome "after_write_off"; nothing put back on the shelf, a review for a person
        self::data($this->call('POST', '/v1/reservations/W1/cancel', $key, ['unit_ids' => ['c'], 'restockable' => false]));
        $this->book('count', $sku, 9, 'MAIN', Clock::iso(Clock::db($this->now)));
        $d = self::data($this->call('POST', '/v1/reservations/W1/uncancel', $key, ['unit_ids' => ['c']]));
        self::assertSame([['outcome' => 'after_write_off', 'result' => 'uncancelled_after_verify', 'unit_id' => 'c']], $d['units']);
        self::knownToTheConnector('uncancel', $d);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'uncancel_after_write_off' AND status = 'open'"));

        // the way before (VERIFY): its old results, no outcome
        self::notRestockable('verify');
        $d = self::data($this->call('POST', '/v1/reservations/W1/cancel', $key, ['unit_ids' => ['d'], 'restockable' => false]));
        self::assertSame([['result' => 'cancelled_to_verify', 'unit_id' => 'd']], $d['units']);
        $d = self::data($this->call('POST', '/v1/reservations/W1/uncancel', $key, ['unit_ids' => ['d']]));
        self::assertSame([['result' => 'uncancelled_from_verify', 'unit_id' => 'd']], $d['units']);
    }
}
