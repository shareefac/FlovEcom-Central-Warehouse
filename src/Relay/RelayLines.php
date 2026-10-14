<?php

declare(strict_types=1);

namespace CW\Relay;

use CW\Caller;
use CW\Db;
use CW\Idempotency;

/**
 * One row per line of an ERPNext document a store relays (relay_line, 0036; docs/decisions.md PF1): the plan's key "document name +
 * line index, never per variant" (§9), for the three stock kinds of POST /v1/movements and the two queue kinds of /v1/relay/*.
 *
 * claim() runs inside the call's transaction, after its Idempotency-Key and BEFORE any stock lock: INSERT … ON DUPLICATE KEY UPDATE
 * on the UNIQUE key (channel, kind, document, line). A new line is claimed (outcome `claimed`) and the caller books or queues it, then
 * settle()s it in the same transaction; a line that is already there (sent before under another key) is not booked again: its first
 * outcome comes back as `already_recorded`, or `conflict` when the same document line now carries other content (line_hash). Two copies
 * at the same moment queue on the key (the second waits for the first's commit, then finds it). The claims of one call are taken in
 * line order, so two calls never wait for each other in a circle.
 */
final class RelayLines
{
    /** The kinds a line can be (the three stock kinds of POST /v1/movements, then the two queue kinds). */
    public const KINDS = ['goods_in', 'supplier_return', 'erp_sale', 'erp_reconciliation', 'erp_mode'];

    public function __construct(private readonly Db $db)
    {
    }

    /** The canonical hash of a line as the call sent it (keys sorted; line_index included). @param array<string, mixed> $line */
    public static function hash(array $line): string
    {
        return hash('sha256', Idempotency::canonicalJson($line));
    }

    /**
     * Claims one line. Returns null when this call claimed it (the caller books it and settle()s it), else what became of it the first
     * time: ['result' => 'already_recorded'|'conflict', 'line_index', 'first' => outcome, 'reason'?].
     *
     * @param array<string, mixed> $line the normalised line (its line_index is the key)
     * @return array<string, mixed>|null
     */
    public function claim(Caller $caller, string $kind, string $docRef, array $line, string $idemKey): ?array
    {
        if (!$caller->isChannel() || !in_array($kind, self::KINDS, true)) {
            throw new \LogicException('relay lines are claimed for a store, by one of ' . implode(', ', self::KINDS));
        }
        if (!$this->db->inTransaction()) {
            throw new \LogicException('a relay line is claimed inside the call\'s transaction');
        }
        $idx = (int) $line['line_index'];
        $hash = self::hash($line);
        $n = $this->db->exec(
            'INSERT INTO relay_line (channel_id, kind, doc_ref, line_index, line_hash, idem_key) VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE id = id',
            [$caller->channelId, $kind, $docRef, $idx, $hash, $idemKey],
        );
        if ($n === 1) {
            return null;
        }
        $row = $this->db->one('SELECT id, line_hash, CAST(outcome AS CHAR) AS outcome, reason, queue_id FROM relay_line '
            . 'WHERE channel_id = ? AND kind = ? AND doc_ref = ? AND line_index = ?', [$caller->channelId, $kind, $docRef, $idx])
            ?? throw new \RuntimeException("relay line {$kind}:{$docRef}#{$idx} vanished");
        $same = hash_equals((string) $row['line_hash'], $hash);
        return ['line_index' => $idx, 'result' => $same ? 'already_recorded' : 'conflict', 'first' => (string) $row['outcome']]
            + ($row['reason'] === null ? [] : ['reason' => (string) $row['reason']]);
    }

    /** Writes what became of a line this call claimed (same transaction). */
    public function settle(Caller $caller, string $kind, string $docRef, int $lineIndex, string $outcome, ?int $skuId = null, ?int $units = null,
        ?int $queueId = null, ?string $reason = null): void
    {
        $n = $this->db->exec(
            "UPDATE relay_line SET outcome = ?, sku_id = ?, units = ?, queue_id = ?, reason = ? WHERE channel_id = ? AND kind = ? AND doc_ref = ? "
            . "AND line_index = ? AND outcome = 'claimed'",
            [$outcome, $skuId, $units, $queueId, $reason === null ? null : mb_substr($reason, 0, 64), $caller->channelId, $kind, $docRef, $lineIndex],
        );
        if ($n !== 1) {
            throw new \LogicException("relay line {$kind}:{$docRef}#{$lineIndex} was not claimed by this call");
        }
    }
}
