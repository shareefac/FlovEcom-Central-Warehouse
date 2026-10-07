<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Catalogue\ItemCompliance;
use CW\CwException;
use CW\Db;
use CW\OpResult;
use CW\Receiving\SellingModes;
use CW\SiteWriter\SiteModes;
use CW\SiteWriter\SiteView;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;

/**
 * The selling-mode switch on the item page (IM10, docs/decisions.md I158): "Selling mode on the websites" shows, per website, whether
 * CW's site stock writer is on there, the item's listings, the mode, back-order flag and low-stock threshold CW writes (with CW's
 * meaning of the label) and who set it last; modes.set changes the mode of a legacy item on the ticked websites (or all of
 * them) with a reason (CW\SiteWriter\SiteModes::set; one effect per form, FormOnce; a stamp of what the page showed: 409 when someone
 * changed it meanwhile). A counted item follows its policy on every site: no form, the page says so.
 */
final class SellingModeController
{
    public const NOTICES = [
        'selling_mode_set' => 'Selling mode saved. Every website whose site stock writer is on gets it on its next feed poll.',
        'selling_mode_unchanged' => 'Nothing changed: the ticked websites already had this selling mode.',
    ];

    public function set(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $mode = $ctx->req->field('mode') ?? '';
        $sites = [];
        if (($ctx->req->field('all_sites') ?? '') === '1') {
            $sites = ['*'];
        } else {
            foreach ($ctx->req->fieldsMatching('/^site_[a-z][a-z0-9_]{0,31}$/D') as $name => $v) {
                if ($v === '1') {
                    $sites[] = substr($name, 5);
                }
            }
        }
        $threshold = $ctx->req->field('threshold') ?? '';
        $reason = $ctx->req->field('reason') ?? '';
        $stamp = $ctx->req->field('stamp') ?? '';
        try {
            $r = FormOnce::run($ctx, 'ui.selling_mode.set', ['mode' => $mode, 'sites' => $sites, 'threshold' => $threshold, 'reason' => $reason, 'stamp' => $stamp],
                static function (Db $db) use ($ctx, $id, $mode, $sites, $threshold, $reason, $stamp): OpResult {
                    $s = (new SiteModes($db))->set($ctx->caller(), $id, $mode, $sites, $threshold, $reason, $stamp);
                    return OpResult::of(303, ['redirect' => Html::url('/ui/items/' . $id, ['notice' => $s['changed'] === [] ? 'selling_mode_unchanged' : 'selling_mode_set'])
                        . '#selling-mode']);
                });
        } catch (CwException $e) {
            return (new ItemController())->page($ctx, $e->httpStatus, $e, null, ['selling_mode' => ['mode' => $mode, 'sites' => $sites, 'threshold' => $threshold,
                'reason' => $reason, 'form_key' => in_array($e->errorCode, ['bad_form_key', 'idempotency_key_reused'], true) ? FormOnce::newKey()
                    : ($ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey())]]);
        }
        return FormOnce::redirect($r);
    }

    /**
     * The section's data (ItemController::page).
     *
     * @param array<string, mixed> $sku the sku row (sell_policy, merged_into_sku_id)
     * @param array<string, mixed> $typed what a refused form had (mode, sites, threshold, reason, form_key)
     * @return array<string, mixed>
     */
    public static function vars(Context $ctx, int $id, array $sku, array $typed = []): array
    {
        $db = $ctx->db;
        $modes = new SiteModes($db);
        $rows = $modes->current([$id]);
        $receiptSites = $modes->receiptChannels();
        $blocked = (new ItemCompliance($db))->selling([$id])[$id] ?? [];
        $policy = (string) $sku['sell_policy'];
        $listings = [];
        foreach ($db->all("SELECT channel_id, external_variant_id, status, units_per_item FROM channel_listing WHERE sku_id = ? AND status IN ('mapped', 'quarantined') "
            . 'ORDER BY channel_id, id', [$id]) as $l) {
            $listings[(int) $l['channel_id']][] = ['variant' => (string) $l['external_variant_id'], 'quarantined' => $l['status'] === 'quarantined',
                'units' => (int) $l['units_per_item']];
        }
        $names = [];
        foreach ($db->all('SELECT id, display_name FROM staff_user WHERE id IN (SELECT updated_by FROM item_channel_mode WHERE sku_id = ? AND updated_by IS NOT NULL)', [$id]) as $p) {
            $names[(int) $p['id']] = (string) $p['display_name'];
        }
        $sites = [];
        foreach ($db->all('SELECT id, code, name, mode, site_writer FROM channel ORDER BY id') as $c) {
            $cid = (int) $c['id'];
            $row = $rows[SiteModes::key($id, $cid)] ?? null;
            $ls = $listings[$cid] ?? [];
            // What CW writes there once the site's writer is on (the switch itself is shown beside it).
            $w = SiteView::rule(['writer_on' => true, 'linked' => $ls !== [], 'status' => ($ls[0]['quarantined'] ?? false) ? 'quarantined' : 'mapped',
                'policy' => $policy, 'qty' => null, 'blocked' => $blocked, 'site' => $row === null ? null : ['mode' => $row['mode'], 'threshold' => $row['threshold']]]);
            $sites[] = [
                'code' => (string) $c['code'], 'name' => (string) $c['name'], 'channel_mode' => (string) $c['mode'], 'writer' => (int) $c['site_writer'] === 1,
                'receipts' => isset($receiptSites[$cid]), 'listings' => $ls,
                'mode' => $w['writer'] ? $w['mode'] : null, 'backorders' => $w['backorders'] ?? null, 'why' => $w['why'], 'meaning' => $w['meaning'] ?? null,
                'threshold' => $row['threshold'] ?? null, 'previous' => $row['previous'] ?? null,
                'set_by' => $row === null ? null : ($names[(int) $row['updated_by']] ?? $row['updated_actor']),
                'set_source' => $row['source'] ?? null,
                'set_document' => $row['document_id'] ?? null,
                'set_at' => $row['updated_at'] ?? null,
                'checked' => in_array((string) $c['code'], $typed['sites'] ?? [], true),
            ];
        }
        $legacy = $policy === 'legacy' && $sku['merged_into_sku_id'] === null;
        return [
            'sites' => $sites,
            'policy' => $policy,
            'legacy' => $legacy,
            'blocked' => $blocked !== [],
            'canSet' => $legacy && $ctx->me()->can('modes.set'),
            'stamp' => SiteModes::stamp($rows),
            'formKey' => $typed['form_key'] ?? FormOnce::newKey(),
            'modes' => array_map(static fn (string $m): array => ['value' => $m, 'meaning' => SellingModes::MEANING[$m]], SellingModes::MODES),
            'typed' => ['mode' => $typed['mode'] ?? '', 'all' => in_array('*', $typed['sites'] ?? [], true), 'threshold' => $typed['threshold'] ?? '',
                'reason' => $typed['reason'] ?? ''],
        ];
    }
}
