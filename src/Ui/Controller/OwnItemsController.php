<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Items\ItemOwners;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Settings > Own items (CW-1153; docs/decisions.md VB4): the items that are a shop's own goods (Vape Big's own items: its own stock, bought
 * with its own money, not ours), on the settings shell like Reasons. Everyone who reads the settings looks (reference.view): each item with
 * what it holds and its buying price, and the history of every period (who added or removed it, when and why). An admin or a reviewer
 * (settings.manage, checked again by ItemOwners inside its transaction) adds an item from now or removes it from now, each with a reason.
 * Nothing is rewritten: a removed item keeps its past (sales paid while it was the shop's own stay the shop's own).
 */
final class OwnItemsController
{
    public const PATH = '/ui/reference/own-items';
    private const NOTICES = ['added', 'removed'];

    public function index(Context $ctx): HtmlResponse
    {
        $n = (string) ($ctx->req->param('notice') ?? '');
        return $this->page($ctx, 200, null, null, in_array($n, self::NOTICES, true) ? Words::OWN_ITEMS['notice_' . $n] : null);
    }

    public function add(Context $ctx): HtmlResponse
    {
        $typed = ['code' => (string) ($ctx->req->field('code') ?? ''), 'reason' => (string) ($ctx->req->field('reason') ?? '')];
        try {
            (new ItemOwners($ctx->db))->add($ctx->caller(), $typed['code'], $typed['reason']);
        } catch (CwException $e) {
            return $this->page($ctx, $e->httpStatus, $e, $typed, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH, ['notice' => 'added']));
    }

    public function remove(Context $ctx): HtmlResponse
    {
        try {
            (new ItemOwners($ctx->db))->remove($ctx->caller(), (string) ($ctx->req->field('code') ?? ''), (string) ($ctx->req->field('reason') ?? ''));
        } catch (CwException $e) {
            return $this->page($ctx, $e->httpStatus, $e, null, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH, ['notice' => 'removed']));
    }

    /** @param array<string, string>|null $typed */
    private function page(Context $ctx, int $status, ?CwException $error, ?array $typed, ?string $notice): HtmlResponse
    {
        $owners = new ItemOwners($ctx->db);
        $shop = ItemOwners::shop($ctx->db);
        $canEdit = $ctx->me()->can('settings.manage');
        $items = $shop === null ? [] : $owners->items($shop['id']);
        $units = 0;
        $valueE4 = 0;
        $unpriced = 0;
        foreach ($items as $i) {
            $units += $i['on_hand'];
            if ($i['unit_cost_e4'] === null) {
                $unpriced++;
            } else {
                $valueE4 += max(0, $i['on_hand']) * $i['unit_cost_e4'];
            }
        }
        return $ctx->page('own_items', [
            'shop' => $shop,
            'items' => $items,
            'units' => $units,
            'value' => \CW\Profit\ProfitViews::gbp(intdiv($valueE4 + 50, 100)),
            'unpriced' => $unpriced,
            'history' => $owners->history(),
            'canEdit' => $canEdit && $shop !== null,
            'lookOnly' => $canEdit ? null : Words::OWN_ITEMS['look_only'],
            'typed' => $typed ?? ['code' => '', 'reason' => ''],
            // the service's own plain words (its codes are its own: a code of the same name elsewhere says something else)
            'error' => $error?->getMessage(),
        ], $status, ['title' => Words::PAGE_TITLE['own_items'], 'notice' => $notice]);
    }
}
