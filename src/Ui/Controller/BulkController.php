<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Mapping\BulkDecisions;
use CW\Mapping\DecisionService;
use CW\Mapping\Proposals;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Queries;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Bulk action on the website products ticked on a list (docs/decisions.md M46-M53, U106-U112): POST /ui/review/bulk from a
 * match-strength list of Products > Mapping (source `review`) or from Store Products (source `store`), and the batch page
 * /ui/review/batches/{id}, which is also the result screen.
 *
 * The form carries, for every row of the page, the map_version and the suggestion it showed (ver_<listing>, prop_<listing>), and
 * the ticked ones (pick_<listing>); a plain form, so it works without app.js. "Not a match", Ignore and Send back for matching
 * never act on the first press: the page answers with a second step that states the count and lists the rows (Ignore asks for
 * the note there), and only its button acts. Everything else is CW\Mapping\BulkDecisions' (row by row through DecisionService).
 * A refusal of the whole request (a strength the setting leaves out, more rows than the setting allows, a job that may not)
 * changes nothing and says so in words.
 */
final class BulkController
{
    public function run(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $source = $req->field('source') === 'store' ? 'store' : 'review';
        $action = (string) ($req->field('action') ?? '');
        $back = self::back($ctx, $source);
        if (!in_array($action, BulkDecisions::ACTIONS, true)) {
            return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
        }
        $rows = [];
        foreach ($req->fieldsMatching('/^pick_[1-9][0-9]{0,9}$/D') as $name => $v) {
            if ($v !== '1') {
                continue;
            }
            $id = (int) substr($name, 5);
            $ver = $req->field('ver_' . $id);
            $prop = $req->field('prop_' . $id) ?? '';
            if ($ver === null || preg_match('/^[0-9]{1,10}$/D', $ver) !== 1 || ($prop !== '' && UiRequest::id($prop) === null)) {
                return $ctx->error(400, 'bad_form', Words::ERROR['bad_form'], $back);
            }
            $rows[] = ['listing_id' => $id, 'proposal_id' => UiRequest::id($prop), 'map_version' => (int) $ver];
        }
        $channel = self::channel($ctx, $req->field('channel'));
        $band = $source === 'review' ? $req->field('queue') : null;
        $context = ['source' => $source, 'channel_id' => $channel['id'] ?? null, 'band' => $band, 'reason' => $req->field('reason')];
        if ($rows === []) {
            return $ctx->error(422, 'nothing_ticked', Words::BULK_ERROR['nothing_ticked'], $back);
        }
        $max = BulkDecisions::maxRows($ctx->db);
        if (count($rows) > $max) {
            return $ctx->error(422, 'too_many_rows', Words::say('BULK_ERROR', 'too_many_rows', count($rows), $max), $back);
        }
        $negative = in_array($action, BulkDecisions::NEGATIVE, true);
        if ($negative && $req->field('confirm') !== '1') {
            return $this->confirmPage($ctx, $source, $action, $rows, $band, $channel, null, 200);
        }
        if ($action === 'ignore' && trim((string) $req->field('reason')) === '') {
            return $this->confirmPage($ctx, $source, $action, $rows, $band, $channel, Words::BULK_ERROR['reason_required'], 422);
        }
        try {
            $r = (new BulkDecisions($ctx->db, $ctx->decisions()))->run($ctx->caller(), $action, $rows, $context);
        } catch (CwException $e) {
            $text = match ($e->errorCode) {
                'too_many_rows' => Words::say('BULK_ERROR', 'too_many_rows', (int) ($e->detail['asked'] ?? count($rows)), (int) ($e->detail['max'] ?? $max)),
                'band_not_allowed' => $band !== null && in_array($band, Proposals::BANDS, true)
                    ? Words::say('BULK_ERROR', 'band_not_allowed', Words::of('BAND', $band)) : Words::BULK_ERROR['action_not_here'],
                'bad_reason' => Words::say('MATCH_ERROR', 'reason_long', DecisionService::MAX_REASON),
                default => Words::BULK_ERROR[$e->errorCode] ?? Words::say('MATCH_ERROR', 'other', ucfirst(rtrim($e->getMessage(), '.')) . '.'),
            };
            if ($e->errorCode === 'reason_required' || $e->errorCode === 'bad_reason') {
                return $this->confirmPage($ctx, $source, $action, $rows, $band, $channel, $text, $e->httpStatus);
            }
            return $ctx->error($e->httpStatus, $e->errorCode, $text, $back);
        }
        return HtmlResponse::redirect('/ui/review/batches/' . $r['batch_id']);
    }

    /** The batch page: what was asked, what became of every row (done, sent to Second approval, skipped with why), and the way back. */
    public function show(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $b = $ctx->db->one('SELECT b.*, u.display_name, c.code AS channel_code, c.name AS channel_name FROM mapping_batch b '
            . 'LEFT JOIN staff_user u ON u.id = b.staff_user_id LEFT JOIN channel c ON c.id = b.channel_id WHERE b.id = ?', [$id]);
        if ($b === null) {
            return $ctx->error(404, 'unknown_batch', Words::BULK_ERROR['unknown_batch'], ['/ui/review', Words::SEGMENT['review']]);
        }
        $batch = DecisionService::SCREEN_BATCH_PREFIX . $id;
        $rows = [];
        $count = ['done' => 0, 'pending_second' => 0, 'skipped' => 0];
        $why = [];
        foreach ($ctx->db->all(
            'SELECT r.seq, r.listing_id, r.outcome, r.skip_code, r.detail, r.decision_id, d.state AS decision_state, d.action AS decision_action, '
            . 's.code AS sku_code, ch.name AS channel_name, cl.external_variant_id, lp.product_title, lp.variant_title '
            . 'FROM mapping_batch_row r LEFT JOIN match_decision d ON d.id = r.decision_id LEFT JOIN sku s ON s.id = d.sku_id '
            . 'LEFT JOIN channel_listing cl ON cl.id = r.listing_id LEFT JOIN channel ch ON ch.id = cl.channel_id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = r.listing_id WHERE r.batch_id = ? ORDER BY r.seq',
            [$id],
        ) as $r) {
            $outcome = (string) $r['outcome'];
            $count[$outcome]++;
            $detail = Html::json($r['detail']);
            $code = $r['skip_code'] === null ? null : (string) $r['skip_code'];
            if ($code !== null) {
                $why[$code] = ($why[$code] ?? 0) + 1;
            }
            $rows[] = [
                'seq' => (int) $r['seq'],
                'listing_id' => (int) $r['listing_id'],
                'title' => self::s(trim(((string) $r['product_title']) . ' ' . ((string) $r['variant_title']))),
                'store' => self::s($r['channel_name']),
                'variant' => self::s($r['external_variant_id']),
                'outcome' => $outcome,
                'why' => $code === null ? null : self::why($code, $detail),
                'needs' => array_map(static fn (string $n): string => Words::of('NEEDS_SECOND', $n), Html::strings($detail['needs_second'] ?? null)),
                'sku_code' => self::s($r['sku_code']),
                // What the decision is now: it may have been approved or cancelled since (the result is a record, not a promise).
                'state_now' => $r['decision_state'] === null ? null : (string) $r['decision_state'],
                'link' => '/ui/review/listing/' . (int) $r['listing_id'],
            ];
        }
        $action = (string) $b['action'];
        $asked = (int) $b['rows_asked'];
        $from = (string) $b['source'];
        $list = $from === 'store'
            ? [Html::url('/ui/review/store', ['channel' => $b['channel_code']]), Words::MENU['store_products']]
            : [Html::url('/ui/review', ['queue' => $b['band'], 'channel' => $b['channel_code']]), Words::of('BAND_TITLE', (string) $b['band'])];
        return $ctx->page('bulk_result', [
            'batch' => ['id' => $id, 'ref' => $batch, 'action' => $action, 'by' => self::s($b['display_name']), 'at' => (string) $b['created_at'],
                'store' => self::s($b['channel_name']), 'band' => $b['band'] === null ? null : (string) $b['band'], 'reason' => self::s($b['reason']),
                'source' => $from],
            'summary' => Words::say('BULK', 'summary', Words::say('BULK_DONE', $action, $count['done']), $count['pending_second'], $count['skipped']),
            'count' => $count,
            'missing' => max(0, $asked - count($rows)),
            'asked' => $asked,
            'why' => $why,
            'skipped' => array_values(array_filter($rows, static fn (array $r): bool => $r['outcome'] === 'skipped')),
            'rows' => $rows,
            'list' => $list,
            'pending_link' => $count['pending_second'] > 0 ? Html::url('/ui/review', ['queue' => 'pending', 'channel' => $b['channel_code']]) : null,
        ], 200, ['title' => Words::say('BULK', 'title', $id), 'active' => 'review']);
    }

    /**
     * The second step of "Not a match", Ignore and Send back for matching: the count, the rows, the note (Ignore needs one), and the
     * one button that acts; the same rows go back in hidden fields. Nothing is saved before its button.
     *
     * @param list<array{listing_id: int, proposal_id: ?int, map_version: int}> $rows
     * @param array{id: int, code: string, name: string}|null $channel
     */
    private function confirmPage(Context $ctx, string $source, string $action, array $rows, ?string $band, ?array $channel, ?string $error, int $status): HtmlResponse
    {
        $ids = array_map(static fn (array $r): int => $r['listing_id'], $rows);
        $titles = [];
        foreach ($ctx->db->all('SELECT cl.id, ch.name AS store, cl.external_variant_id, lp.product_title, lp.variant_title FROM channel_listing cl '
            . 'JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $l) {
            $titles[(int) $l['id']] = ['title' => self::s(trim(((string) $l['product_title']) . ' ' . ((string) $l['variant_title']))),
                'store' => self::s($l['store']), 'variant' => self::s($l['external_variant_id'])];
        }
        $n = count($rows);
        $keep = [];
        foreach (['queue', 'lane', 'min', 'q', 'page', 'state', 'sort'] as $k) {
            $v = $ctx->req->field($k);
            if ($v !== null && $v !== '') {
                $keep[$k] = mb_substr($v, 0, 100);
            }
        }
        if ($channel !== null) {
            $keep['channel'] = $channel['code'];
        }
        return $ctx->page('bulk_confirm', [
            'source' => $source,
            'action' => $action,
            'count' => $n,
            'title' => Words::say('BULK_CONFIRM', $action . '_title', $n),
            'text' => Words::BULK_CONFIRM[$action . '_text'],
            'button' => Words::say('BULK_CONFIRM', $action . '_button', $n),
            'needs_reason' => $action === 'ignore',
            'reason' => mb_substr(trim((string) $ctx->req->field('reason')), 0, DecisionService::MAX_REASON),
            'max_reason' => DecisionService::MAX_REASON,
            'rows' => array_map(static fn (array $r): array => $r + ($titles[$r['listing_id']] ?? ['title' => null, 'store' => null, 'variant' => null]), $rows),
            'keep' => $keep,
            'error' => $error,
            'back' => self::back($ctx, $source),
            'band' => $band,
        ], $status, ['title' => Words::say('BULK_CONFIRM', $action . '_title', $n), 'active' => $source === 'store' ? 'store_products' : 'review']);
    }

    /**
     * Why a row was skipped, in words (Words::BULK_SKIP), with what the record says: the hold's reason, the spot check, the vetoes and
     * flags in words, the state found, a refusal's own words.
     *
     * @param array<string, mixed> $detail
     */
    public static function why(string $code, array $detail): string
    {
        $base = Words::BULK_SKIP[$code] ?? Words::BULK_SKIP['refused'];
        return match ($code) {
            'held' => sprintf($base, (string) ($detail['reason'] ?? '')),
            'in_spot_check', 'spot_check_failed' => sprintf($base, (string) ($detail['sample'] ?? '')),
            'vetoed' => sprintf($base, Words::andList(array_map(static fn (string $v): string => Words::of('VETO', $v), Html::strings($detail['vetoes'] ?? null)))),
            'flagged' => sprintf($base, Words::andList(array_map(static fn (string $f): string => Words::of('FLAG', $f), Html::strings($detail['flags'] ?? null)))),
            'changed', 'not_waiting', 'not_ignored' => sprintf($base, is_string($detail['status'] ?? null) ? Words::of('LISTING_STATUS', $detail['status']) : Words::BULK['state_unknown']),
            'band_not_allowed' => sprintf($base, is_string($detail['band'] ?? null) ? Words::of('BAND', $detail['band']) : Words::BULK['state_unknown']),
            'refused' => sprintf($base, is_string($detail['code'] ?? null) && isset(Words::MATCH_ERROR[$detail['code']]) && $detail['code'] !== 'other'
                ? Words::MATCH_ERROR[$detail['code']] : ucfirst(rtrim((string) ($detail['message'] ?? ''), '.')) . '.'),
            default => $base,
        };
    }

    /** The list a refused or confirmed request goes back to ([path, its name]). @return array{0: string, 1: string} */
    private static function back(Context $ctx, string $source): array
    {
        $req = $ctx->req;
        $channel = self::channel($ctx, $req->field('channel'));
        if ($source === 'store') {
            return [Html::url('/ui/review/store', ['channel' => $channel['code'] ?? null, 'state' => self::opt($req->field('state'), Queries::STORE_STATES),
                'sort' => self::opt($req->field('sort'), array_keys(Queries::STORE_SORTS)), 'q' => self::text($req->field('q')),
                'page' => UiRequest::id($req->field('page'))]), Words::MENU['store_products']];
        }
        $band = self::opt($req->field('queue'), Proposals::BANDS);
        if ($band === null) {
            return ['/ui/review', Words::SEGMENT['review']];
        }
        return [Html::url('/ui/review', ['queue' => $band, 'channel' => $channel['code'] ?? null, 'lane' => self::opt($req->field('lane'), Queries::LANES),
            'min' => preg_match('/^[0-9]{1,6}$/D', (string) $req->field('min')) === 1 && (int) $req->field('min') > 0 ? (int) $req->field('min') : null,
            'q' => self::text($req->field('q')), 'page' => UiRequest::id($req->field('page'))]), Words::of('BAND_TITLE', $band)];
    }

    /** @return array{id: int, code: string, name: string}|null the store a code names */
    private static function channel(Context $ctx, ?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }
        foreach ($ctx->queries()->channels() as $c) {
            if ($c['code'] === $code) {
                return $c;
            }
        }
        return null;
    }

    /** @param list<string> $allowed */
    private static function opt(?string $v, array $allowed): ?string
    {
        return $v !== null && in_array($v, $allowed, true) ? $v : null;
    }

    private static function text(?string $v): ?string
    {
        $v = $v === null ? '' : mb_substr(trim($v), 0, 100);
        return $v === '' ? null : $v;
    }

    private static function s(mixed $v): ?string
    {
        return $v === null || $v === '' || !is_scalar($v) ? null : mb_substr((string) $v, 0, 500);
    }
}
