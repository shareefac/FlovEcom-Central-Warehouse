<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;

/**
 * Matching runs and their proposals (plan §7.3 step 7; design A.4 match_run / match_proposal).
 *
 * A proposal is what a run suggests for one listing: an item, a new item, or nothing (Can't tell,
 * Conflict), with its band, lane, the AI answer and the evidence. Proposals are immutable except
 * their status: a listing has at most one `open` proposal (a unique key); a proposal of a later run
 * supersedes it, a decision settles it (DecisionService). Written under the listing's row lock, so
 * they serialise with the decisions on that listing. Nothing here links anything: with $suggest an
 * `unmapped` listing is moved to `suggested` through DecisionService (action `suggest`).
 */
final class Proposals
{
    public const BANDS = ['Key', 'Check', 'New item', "Can't tell", 'Conflict', 'Manual'];

    public function __construct(private readonly Db $db, private readonly DecisionService $decisions)
    {
    }

    /**
     * The match_run row of ($runId, $source), created on first use (append-only: an existing run is
     * returned as it is).
     *
     * @param array<string, mixed>|null $models
     * @param array<string, mixed>|null $detail
     */
    public function run(string $runId, string $source, ?string $promptSha = null, ?string $engine = null, ?array $models = null, ?array $detail = null): int
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $runId) !== 1 || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $source) !== 1) {
            throw new CwException('bad_run', 'run id must be 1-64 of [A-Za-z0-9._:-] and source a lower-case word', 400);
        }
        if ($promptSha !== null && preg_match('/^[0-9a-f]{64}$/', $promptSha) !== 1) {
            throw new CwException('bad_run', 'prompt sha must be sha256 hex', 400);
        }
        $find = fn (): mixed => $this->db->value('SELECT id FROM match_run WHERE run_id = ? AND source = ?', [$runId, $source]);
        $id = $find();
        if ($id !== null) {
            return (int) $id;
        }
        try {
            return $this->db->insert(
                'INSERT INTO match_run (run_id, source, prompt_sha, engine_version, model_summary, detail) VALUES (?, ?, ?, ?, ?, ?)',
                [$runId, $source, $promptSha, $engine === null ? null : mb_substr($engine, 0, 128),
                    $models === null ? null : Idempotency::json($models), $detail === null ? null : Idempotency::json($detail)],
            );
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 1062 && ($id = $find()) !== null) {
                return (int) $id;
            }
            throw $e;
        }
    }

    /**
     * Records the proposal of run $runId for one listing, in one transaction under the listing's
     * row lock: `exists` when this run already proposed for the listing (re-runs change nothing);
     * otherwise the listing's open proposal of another run is superseded and this one is inserted.
     * With $suggest, an unmapped listing without a pending decision then moves to `suggested`.
     *
     * @param array{proposed_sku_id?: ?int, proposed_new_item?: bool, band: string, lane?: ?string, ai_outcome?: ?string,
     *              ai_confidence?: ?int, ai_units_per_item?: ?int, ai_model?: ?string, closest_sku_id?: ?int,
     *              evidence?: ?array<string, mixed>, flags?: list<string>} $p
     * @return array{result: string, proposal_id: int, superseded: ?int, suggested: bool}
     */
    public function add(Caller $caller, int $listingId, int $runId, array $p, bool $suggest = true): array
    {
        $band = $p['band'] ?? null;
        if (!is_string($band) || !in_array($band, self::BANDS, true)) {
            throw new CwException('bad_band', 'band must be one of ' . implode(', ', self::BANDS), 400);
        }
        $newItem = (bool) ($p['proposed_new_item'] ?? false);
        $sku = $p['proposed_sku_id'] ?? null;
        if ($newItem && $sku !== null) {
            throw new CwException('bad_proposal', 'a new-item proposal names no item', 400);
        }
        $conf = $p['ai_confidence'] ?? null;
        if ($conf !== null && (!is_int($conf) || $conf < 0 || $conf > 100)) {
            $conf = null;
        }
        $units = $p['ai_units_per_item'] ?? null;
        if ($units !== null && (!is_int($units) || $units < 1 || $units > 65_535)) {
            $units = null;
        }
        $flags = array_values(array_unique(array_map('strval', $p['flags'] ?? [])));
        sort($flags, SORT_STRING);
        $str = static fn (mixed $v, int $max): ?string => is_string($v) && $v !== '' ? mb_substr($v, 0, $max) : null;

        return $this->db->transaction(function (Db $db) use ($caller, $listingId, $runId, $p, $band, $newItem, $sku, $conf, $units, $flags, $str, $suggest): array {
            $l = $db->one('SELECT id, status, map_version FROM channel_listing WHERE id = ? FOR UPDATE', [$listingId]);
            if ($l === null) {
                throw new CwException('unknown_listing', 'no such listing', 404);
            }
            $mine = $db->one('SELECT id, status FROM match_proposal WHERE match_run_id = ? AND listing_id = ?', [$runId, $listingId]);
            $superseded = null;
            if ($mine !== null) {
                $id = (int) $mine['id'];
                $result = 'exists';
            } else {
                $open = $db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listingId]);
                if ($open !== null) {
                    $db->exec("UPDATE match_proposal SET status = 'superseded' WHERE id = ?", [(int) $open]);
                    $superseded = (int) $open;
                }
                $id = $db->insert(
                    'INSERT INTO match_proposal (listing_id, match_run_id, proposed_sku_id, proposed_new_item, band, lane, ai_outcome, ai_confidence, '
                    . 'ai_units_per_item, ai_model, closest_sku_id, evidence, flags) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$listingId, $runId, $sku, $newItem ? 1 : 0, $band, $str($p['lane'] ?? null, 32), $str($p['ai_outcome'] ?? null, 32), $conf,
                        $units, $str($p['ai_model'] ?? null, 64), $p['closest_sku_id'] ?? null,
                        isset($p['evidence']) ? Idempotency::json($p['evidence']) : null, $flags === [] ? '[]' : Idempotency::json($flags)],
                );
                $result = 'created';
                Audit::write($db, $caller, 'mapping.propose', 'listing', (string) $listingId, null,
                    ['proposal_id' => $id, 'match_run_id' => $runId, 'band' => $band, 'proposed_sku_id' => $sku, 'new_item' => $newItem,
                        'superseded' => $superseded]);
            }
            $suggested = false;
            if ($suggest && $l['status'] === 'unmapped' && ($mine === null || $mine['status'] === 'open')
                && $db->value('SELECT id FROM match_decision WHERE pending_listing_id = ?', [$listingId]) === null) {
                $this->decisions->decide($caller, ['action' => 'suggest', 'listing_id' => $listingId,
                    'expected_map_version' => (int) $l['map_version'], 'proposal_id' => $id]);
                $suggested = true;
            }
            return ['result' => $result, 'proposal_id' => $id, 'superseded' => $superseded, 'suggested' => $suggested];
        });
    }
}
