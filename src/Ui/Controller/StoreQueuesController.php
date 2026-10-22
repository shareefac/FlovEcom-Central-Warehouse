<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Queues\Corrections;
use CW\Queues\LandingOrders;
use CW\Queues\ModeChanges;
use CW\Queues\Oversells;
use CW\Queues\QueueRules;
use CW\Queues\Suspense;
use CW\Relay\RelayIntake;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Router;
use CW\Ui\Words;

/**
 * Approvals › From the stores (pack F; docs/decisions.md PF5-PF9): the four queues of what the stores send, each a segment:
 *   Lines to place      goods_in_suspense            pick the item and book the line, or close it         queues.suspense
 *   ERPNext corrections count_review (ERPNext's)     confirm the figure (books the difference), or close   queues.corrections
 *   Mode changes        policy_review (ERPNext's)    accept the change, or keep what CW has                queues.modes
 *   Oversold            oversell_event               mark it handled with how and a note; add notes       queues.oversell
 *   Website bugs        landing_order (0050, LP8)    mark it handled with how and a note                  queues.oversell
 * Everyone who reads records looks (queues.view, the GET routes); each POST needs its action's permission (the route) and is checked
 * again by its service (CW\Queues\*) inside its transaction. Every answer needs a note. A row is settled once: a second answer is
 * refused (409) and says so; a second click of the same booking shows its first answer. Plain words: Words::QUEUES, SUSPENSE,
 * CORRECTIONS, MODES, OVERSOLD, QUEUE_STATE, QUEUE_NOTICE, QUEUE_ERROR, RELAY_KIND, RELAY_REASON, OVERSELL_KIND, OVERSELL_RESOLUTION.
 */
final class StoreQueuesController
{
    public const PATH = '/ui/queues';

    public static function routes(Router $r): void
    {
        $c = new self();
        $r->add('GET', self::PATH . '/suspense', 'queues.view', $c->suspense(...));
        $r->add('POST', self::PATH . '/suspense/{id}/pick', 'queues.suspense', $c->suspensePick(...));
        $r->add('POST', self::PATH . '/suspense/{id}/dismiss', 'queues.suspense', $c->suspenseDismiss(...));
        $r->add('GET', self::PATH . '/corrections', 'queues.view', $c->corrections(...));
        $r->add('POST', self::PATH . '/corrections/{id}/confirm', 'queues.corrections', $c->correctionConfirm(...));
        $r->add('POST', self::PATH . '/corrections/{id}/dismiss', 'queues.corrections', $c->correctionDismiss(...));
        $r->add('GET', self::PATH . '/modes', 'queues.view', $c->modes(...));
        $r->add('POST', self::PATH . '/modes/{id}/accept', 'queues.modes', $c->modeAccept(...));
        $r->add('POST', self::PATH . '/modes/{id}/dismiss', 'queues.modes', $c->modeDismiss(...));
        $r->add('GET', self::PATH . '/oversold', 'queues.view', $c->oversold(...));
        $r->add('POST', self::PATH . '/oversold/{id}/handle', 'queues.oversell', $c->oversoldHandle(...));
        $r->add('POST', self::PATH . '/oversold/{id}/note', 'queues.oversell', $c->oversoldNote(...));
        $r->add('GET', self::PATH . '/landing', 'queues.view', $c->landing(...));
        $r->add('POST', self::PATH . '/landing/{id}/handle', 'queues.oversell', $c->landingHandle(...));
    }

    // ------------------------------------------------------------------------------------------
    // Lines to place (goods-in suspense)
    // ------------------------------------------------------------------------------------------

    public function suspense(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $state = QueueRules::status($ctx->req->param('status'));
        $rows = (new Suspense($ctx->db))->list($state);
        return $this->page($ctx, 'queue_suspense', 'queue_suspense', Suspense::PERM, $state, $rows, self::suspenseRow(...), $status, $error);
    }

    public function suspensePick(Context $ctx): HtmlResponse
    {
        $units = trim((string) ($ctx->req->field('units') ?? '1'));
        try {
            if (preg_match('/^\d{1,5}$/D', $units === '' ? '1' : $units) !== 1) {
                throw new CwException('bad_units', 'one unit of the line is a whole number of the item\'s units', 400, ['field' => 'units']);
            }
            $r = (new Suspense($ctx->db))->pick($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('item') ?? ''), (int) ($units === '' ? '1' : $units),
                (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return $this->suspense($ctx, $e->httpStatus, $e);
        }
        $notice = $r['result'] === 'queued' ? 'sent_to_corrections' : ($r['replayed'] ? 'booked_before' : 'booked');
        return HtmlResponse::redirect(Html::url(self::PATH . '/suspense', ['notice' => $notice, 'item' => $r['sku_code']]));
    }

    public function suspenseDismiss(Context $ctx): HtmlResponse
    {
        try {
            (new Suspense($ctx->db))->dismiss($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return $this->suspense($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/suspense', ['notice' => 'closed']));
    }

    // ------------------------------------------------------------------------------------------
    // ERPNext corrections (count_review, source erp_reconciliation)
    // ------------------------------------------------------------------------------------------

    public function corrections(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $state = QueueRules::status($ctx->req->param('status'));
        $rows = (new Corrections($ctx->db))->list($state);
        return $this->page($ctx, 'queue_corrections', 'queue_corrections', Corrections::PERM, $state, $rows, self::correctionRow(...), $status, $error);
    }

    public function correctionConfirm(Context $ctx): HtmlResponse
    {
        try {
            $r = (new Corrections($ctx->db))->confirm($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return $this->corrections($ctx, $e->httpStatus, $e);
        }
        $notice = $r['replayed'] ? 'confirmed_before' : ($r['delta'] === 0 ? 'confirmed_nothing' : 'confirmed');
        return HtmlResponse::redirect(Html::url(self::PATH . '/corrections', ['notice' => $notice]));
    }

    public function correctionDismiss(Context $ctx): HtmlResponse
    {
        try {
            (new Corrections($ctx->db))->dismiss($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return $this->corrections($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/corrections', ['notice' => 'closed']));
    }

    // ------------------------------------------------------------------------------------------
    // Mode changes (policy_review, source erp_update_product)
    // ------------------------------------------------------------------------------------------

    public function modes(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $state = QueueRules::status($ctx->req->param('status'));
        $rows = (new ModeChanges($ctx->db))->list($state);
        return $this->page($ctx, 'queue_modes', 'queue_modes', ModeChanges::PERM, $state, $rows, self::modeRow(...), $status, $error);
    }

    public function modeAccept(Context $ctx): HtmlResponse
    {
        try {
            $r = (new ModeChanges($ctx->db))->accept($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return $this->modes($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/modes', ['notice' => $r['applied'] === 'mode' ? 'accepted_mode' : 'accepted_policy']));
    }

    public function modeDismiss(Context $ctx): HtmlResponse
    {
        try {
            (new ModeChanges($ctx->db))->dismiss($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return $this->modes($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/modes', ['notice' => 'kept']));
    }

    // ------------------------------------------------------------------------------------------
    // Oversold (oversell_event)
    // ------------------------------------------------------------------------------------------

    public function oversold(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $state = QueueRules::status($ctx->req->param('status'));
        $rows = (new Oversells($ctx->db))->list($state);
        return $this->page($ctx, 'queue_oversold', 'queue_oversold', Oversells::PERM, $state, $rows, self::oversoldRow(...), $status, $error,
            ['resolutions' => Oversells::RESOLUTIONS]);
    }

    public function oversoldHandle(Context $ctx): HtmlResponse
    {
        try {
            (new Oversells($ctx->db))->handle($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('resolution') ?? ''), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return $this->oversold($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/oversold', ['notice' => 'handled']));
    }

    public function oversoldNote(Context $ctx): HtmlResponse
    {
        try {
            (new Oversells($ctx->db))->addNote($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return $this->oversold($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/oversold', ['notice' => 'noted']) . '#oversold-' . $ctx->id());
    }

    // ------------------------------------------------------------------------------------------
    // Website bugs (landing_order: an order for a landing page, docs/decisions.md LP8)
    // ------------------------------------------------------------------------------------------

    public function landing(Context $ctx, int $status = 200, ?CwException $error = null): HtmlResponse
    {
        $state = QueueRules::status($ctx->req->param('status'));
        $rows = (new LandingOrders($ctx->db))->list($state);
        return $this->page($ctx, 'queue_landing', 'queue_landing', LandingOrders::PERM, $state, $rows, self::landingRow(...), $status, $error,
            ['resolutions' => LandingOrders::RESOLUTIONS]);
    }

    public function landingHandle(Context $ctx): HtmlResponse
    {
        try {
            (new LandingOrders($ctx->db))->handle($ctx->caller(), $ctx->id(), (string) ($ctx->req->field('resolution') ?? ''), (string) ($ctx->req->field('note') ?? ''));
        } catch (CwException $e) {
            return $this->landing($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/landing', ['notice' => 'handled']));
    }

    // ------------------------------------------------------------------------------------------
    // The rows in words
    // ------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $r a Suspense::list() row @return array<string, mixed> */
    public static function suspenseRow(array $r): array
    {
        $names = [];
        if ($r['external_variant_id'] !== null) {
            $names[] = Words::say('SUSPENSE', 'variant', (string) $r['external_variant_id']);
        }
        if ($r['erp_item_code'] !== null) {
            $names[] = Words::say('SUSPENSE', 'erp_item', (string) $r['erp_item_code']);
        }
        $correction = (string) $r['movement_type'] === RelayIntake::KIND_RECONCILIATION;
        return $r + [
            'title' => Words::say('SUSPENSE', 'title', Words::of('RELAY_KIND', (string) $r['movement_type']), abs((int) $r['qty']),
                (string) ($r['description'] ?? '') !== '' ? (string) $r['description'] : Words::SUSPENSE['no_description']),
            'from' => Words::say('QUEUES', 'from', $r['store']['name'] ?? Words::QUEUES['store_unknown']),
            'document' => Words::say('SUSPENSE', 'document', (string) $r['doc_ref'], (int) $r['line_index']),
            'names' => $names === [] ? null : Words::say('SUSPENSE', 'names', Words::andList($names)),
            'why' => Words::say('SUSPENSE', 'why', Words::of('RELAY_REASON', (string) $r['reason'])),
            'received' => Words::say('QUEUES', 'received', Html::when((string) $r['created_at'])),
            'correction' => $correction,
            'picked' => $r['sku_code'] === null ? null : Words::say('SUSPENSE', 'picked', (string) $r['sku_name'], (string) $r['sku_code']),
            'closed' => self::closed($r),
        ];
    }

    /** @param array<string, mixed> $r a Corrections::list() row @return array<string, mixed> */
    public static function correctionRow(array $r): array
    {
        $e = $r['effect'];
        $effect = [];
        if ($e !== null && $e['newer']) {
            $effect[] = Words::CORRECTIONS['newer'];
        } elseif ($e !== null) {
            $effect[] = Words::say('CORRECTIONS', 'then', (int) $e['shelf'], (int) $e['allocated']);
            if ((int) $e['carried'] !== 0) {
                $effect[] = Words::say('CORRECTIONS', 'carried', (int) $e['carried'] > 0 ? '+' . (int) $e['carried'] : (string) (int) $e['carried']);
            }
            $effect[] = $e['for_sale'] ? Words::say('CORRECTIONS', 'target_for_sale', (int) $e['target'], (int) $r['proposed_qty'], max(0, (int) $e['allocated']))
                : Words::say('CORRECTIONS', 'target_shelf', (int) $e['target']);
            $effect[] = match (true) {
                $e['delta'] > 0 => Words::say('CORRECTIONS', 'will_add', (int) $e['delta']),
                $e['delta'] < 0 => Words::say('CORRECTIONS', 'will_take', -(int) $e['delta']),
                default => Words::CORRECTIONS['no_difference'],
            };
        }
        return $r + [
            'title' => Words::say('CORRECTIONS', 'title', (string) $r['sku_name'], (int) $r['proposed_qty']),
            'from' => Words::say('QUEUES', 'from', $r['store']['name'] ?? Words::QUEUES['store_unknown']),
            'set_at' => Words::say('CORRECTIONS', 'set_at', Html::when($r['at']), (string) $r['warehouse']),
            'document' => Words::say('CORRECTIONS', 'document', (string) $r['ref']),
            'effect_lines' => $effect,
            'received' => Words::say('QUEUES', 'received', Html::when((string) $r['created_at'])),
            'closed' => self::closed($r),
        ];
    }

    /** @param array<string, mixed> $r a ModeChanges::list() row @return array<string, mixed> */
    public static function modeRow(array $r): array
    {
        $d = $r['detail'];
        $store = $r['store']['name'] ?? Words::QUEUES['store_unknown'];
        $cwMode = is_string($d['cw_mode'] ?? null) ? $d['cw_mode'] : null;
        return $r + [
            'title' => Words::say('MODES', 'title', (string) ($r['sku_name'] ?? ''), (string) $r['site_mode']),
            'from' => Words::say('QUEUES', 'from', $store),
            'variant' => $r['external_variant_id'] === null ? null : Words::say('MODES', 'variant', (string) $r['external_variant_id']),
            'low' => is_int($d['low_stock'] ?? null) ? Words::say('MODES', 'low_stock', (int) $d['low_stock']) : null,
            'cw' => $cwMode === null ? Words::MODES['cw_none'] : Words::say('MODES', 'cw_now', $cwMode),
            'will' => $r['applies'] === 'mode' ? Words::say('MODES', 'will_mode', (string) $r['site_mode'], $store)
                : Words::say('MODES', 'will_policy', Words::of('POLICY', (string) $r['proposed_policy'])),
            'received' => Words::say('QUEUES', 'received', Html::when((string) $r['created_at'])),
            'closed' => $r['resolution'] === 'superseded' ? Words::MODES['superseded'] : self::closed($r),
        ];
    }

    /** @param array<string, mixed> $r an Oversells::list() row @return array<string, mixed> */
    public static function oversoldRow(array $r): array
    {
        return $r + [
            'title' => Words::say('OVERSOLD', 'title', (int) $r['shortfall'], (string) $r['sku_name']),
            'from' => Words::say('QUEUES', 'from', $r['store']['name'] ?? Words::QUEUES['store_unknown']),
            'order' => $r['order_ref'] === null ? Words::OVERSOLD['no_order'] : Words::say('OVERSOLD', 'order', (string) $r['order_ref'], (string) $r['warehouse']),
            'what' => Words::say('OVERSOLD', 'kind', Words::of('OVERSELL_KIND', (string) $r['kind'])),
            'available' => Words::say('OVERSOLD', 'available', (int) $r['available_after']),
            'received' => Words::say('QUEUES', 'received', Html::when((string) $r['created_at'])),
            'note_lines' => array_map(static fn (array $n): string => Words::say('OVERSOLD', 'note_by', (string) ($n['by_name'] ?? Words::QUEUES['someone']),
                Html::when($n['created_at']), $n['note']), $r['notes']),
            'closed' => $r['status'] === 'open' ? null : Words::say('OVERSOLD', 'closed', Words::of('OVERSELL_RESOLUTION', (string) $r['resolution']),
                Html::when((string) $r['resolved_at']), (string) ($r['resolved_by_name'] ?? Words::QUEUES['someone']), (string) ($r['note'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $r a LandingOrders::list() row @return array<string, mixed> */
    public static function landingRow(array $r): array
    {
        $title = (string) ($r['product_title'] ?? '') !== '' ? (string) $r['product_title'] : ((string) ($r['variant_title'] ?? '') !== '' ? (string) $r['variant_title']
            : Words::LISTING['no_title']);
        return $r + [
            'title' => Words::say('WEBSITE_BUG', 'title', (string) $r['order_ref'], $title),
            'from' => Words::say('QUEUES', 'from', $r['store']['name'] ?? Words::QUEUES['store_unknown']),
            'option' => Words::say('WEBSITE_BUG', 'option', (string) $r['external_variant_id']),
            'units' => Words::say('WEBSITE_BUG', (int) $r['units'] === 1 ? 'unit' : 'units', (int) $r['units']),
            'paid_line' => Words::WEBSITE_BUG[(int) $r['paid'] === 1 ? 'paid' : 'not_paid'],
            'still' => $r['listing_status'] === 'landing' ? null : Words::WEBSITE_BUG['not_landing_now'],
            'received' => Words::say('QUEUES', 'received', Html::when((string) $r['created_at'])),
            'link' => '/ui/review/listing/' . (int) $r['listing_id'],
            'closed' => $r['status'] === 'open' ? null : Words::say('WEBSITE_BUG', 'closed', Words::of('LANDING_RESOLUTION', (string) $r['resolution']),
                Html::when((string) $r['resolved_at']), (string) ($r['resolved_by_name'] ?? Words::QUEUES['someone']), (string) ($r['note'] ?? '')),
        ];
    }

    /** "Done on 7 Oct 2026, 10:26 by Hari: booked on the right item." for a settled row. @param array<string, mixed> $r */
    private static function closed(array $r): ?string
    {
        if ($r['status'] === 'open') {
            return null;
        }
        if ($r['resolved_by'] === null) {
            return Words::say('QUEUES', 'closed_cw', Words::of('QUEUE_STATE', (string) $r['status']), Html::when((string) $r['resolved_at']));
        }
        return Words::say('QUEUES', 'closed', Words::of('QUEUE_STATE', (string) $r['status']), Html::when((string) $r['resolved_at']),
            (string) ($r['resolved_by_name'] ?? Words::QUEUES['someone']), (string) ($r['note'] ?? ''));
    }

    /**
     * One queue page: its rows in words (at most a page; `more` when there are more), the status filter, whether this person may answer
     * (else "You can look; <who> change this."), the notice of the last answer and the error of a refused one.
     *
     * @param list<array<string, mixed>> $rows
     * @param \Closure(array<string, mixed>): array<string, mixed> $words
     * @param array<string, mixed> $more
     */
    private function page(Context $ctx, string $template, string $page, string $perm, ?string $state, array $rows, \Closure $words, int $status,
        ?CwException $error, array $more = []): HtmlResponse
    {
        $canAct = $ctx->me()->can($perm);
        $n = $ctx->req->param('notice');
        $notice = $n !== null && isset(Words::QUEUE_NOTICE[$n]) ? Words::say('QUEUE_NOTICE', $n, (string) ($ctx->req->param('item') ?? '')) : null;
        return $ctx->page($template, [
            'rows' => array_map($words, array_slice($rows, 0, QueueRules::PAGE)),
            'more' => count($rows) > QueueRules::PAGE,
            'pageSize' => QueueRules::PAGE,
            'state' => $state ?? 'all',
            'states' => [...QueueRules::STATUSES, 'all'],
            'action' => self::PATH . '/' . substr($page, strlen('queue_')),
            'canAct' => $canAct,
            'lookOnly' => $canAct ? null : Words::whoCan($perm),
            'noteMin' => QueueRules::NOTE_MIN,
            'noteMax' => QueueRules::NOTE_MAX,
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ] + $more, $status, ['title' => Words::SEGMENT[$page], 'notice' => $notice]);
    }

    /** A refusal in words: by its code (Words::QUEUE_ERROR), else the service's message as a sentence. */
    public static function plain(CwException $e): string
    {
        return Words::QUEUE_ERROR[$e->errorCode] ?? Words::say('QUEUE_ERROR', 'other', rtrim($e->getMessage(), '.'));
    }
}
