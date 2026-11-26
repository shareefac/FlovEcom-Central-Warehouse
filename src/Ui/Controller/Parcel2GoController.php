<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Profit\DeliveryBills;
use CW\Profit\P2gKey;
use CW\Profit\P2gKeyDrop;
use CW\Profit\Parcel2Go;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Profit > Delivery bills > Parcel2Go (CW-1160, 0085; docs/decisions.md PGL11): the two accounts (switched on or off, read up to when, their
 * labels by state and their money by where it went) and the newest labels with their price and where it went; the known limit said (a refund
 * paid back to a card is not seen: the owner, 26 Nov 2026, Q1). Finance and the Delivery bills job look (delivery.view). The KEY page (the
 * Delivery bills job only: staff 1 and 2; checked again in P2gKeyDrop): the Client ID and secret the owner created on Parcel2Go pasted for
 * an account, write-only (never shown back, never logged; the job takes it into a file only root reads, P2gKeyDrop).
 */
final class Parcel2GoController
{
    public const PATH = '/ui/profit/delivery/parcel2go';
    public const PERM_VIEW = DeliveryBills::PERM_VIEW;
    public const PERM_KEY = DeliveryBills::PERM_UPLOAD;
    public const LIST_LIMIT = 200;

    public function lines(Context $ctx): HtmlResponse
    {
        $db = $ctx->db;
        $names = [];
        foreach ($db->all('SELECT id, name FROM channel') as $c) {
            $names[(int) $c['id']] = (string) $c['name'];
        }
        $accounts = [];
        foreach (Parcel2Go::accounts($db) as $key => $a) {
            $s = Parcel2Go::status($db, $key);
            $ok = $db->value("SELECT MAX(finished_at) FROM p2g_run WHERE account_id = ? AND kind = 'pull' AND state = 'ok'", [$a['id']]);
            $accounts[] = ['key' => $key, 'name' => $a['name'], 'on' => $a['on'], 'from' => $a['from'], 'main' => $names[$a['main']] ?? '', 'read_at' => $ok === null ? null : (string) $ok,
                'lines' => $s['lines'], 'states' => $s['states'], 'money' => array_map(static fn (array $m): array => $m + ['shop' => $m['channel_id'] === null ? null
                    : ($names[$m['channel_id']] ?? (string) $m['channel_id'])], $s['money'])];
        }
        $lines = $db->all("SELECT l.account_id, l.order_line_id, l.paid_day, l.service_slug, l.country_iso3, l.price_state, l.refuse_code, a.name AS account, "
            . "(SELECT GROUP_CONCAT(p.tracking_no ORDER BY p.parcel_no SEPARATOR ' ') FROM p2g_parcel p WHERE p.account_id = l.account_id AND p.order_line_id = l.order_line_id) AS tracking, "
            . "(SELECT COALESCE(SUM(r.net_pence), 0) FROM p2g_charge c JOIN carrier_bill_row r ON r.invoice_id = c.invoice_id AND r.row_no = c.row_no JOIN carrier_invoice i "
            . "ON i.id = c.invoice_id AND i.status = 'live' WHERE c.account_id = l.account_id AND c.order_line_id = l.order_line_id) AS net "
            . 'FROM p2g_line l JOIN carrier_account a ON a.id = l.account_id ORDER BY l.paid_at IS NULL, l.paid_at DESC, l.order_line_id DESC LIMIT ' . self::LIST_LIMIT);
        $where = [];
        foreach ($db->all("SELECT c.account_id, c.order_line_id, a.dest, a.how, a.channel_id, a.order_ref FROM p2g_charge c JOIN carrier_invoice i ON i.id = c.invoice_id "
            . "AND i.status = 'live' JOIN carrier_allocation a ON a.invoice_id = c.invoice_id AND a.row_no = c.row_no AND a.ended_at IS NULL ORDER BY a.id") as $w) {
            $k = $w['account_id'] . ':' . $w['order_line_id'];
            $where[$k][$w['dest'] . '|' . $w['how'] . '|' . ($w['channel_id'] ?? '') . '|' . ($w['order_ref'] ?? '')] = ['dest' => (string) $w['dest'], 'how' => (string) $w['how'],
                'shop' => $w['channel_id'] === null ? null : ($names[(int) $w['channel_id']] ?? ''), 'channel_id' => $w['channel_id'] === null ? null : (int) $w['channel_id'],
                'order_ref' => $w['order_ref'] === null ? null : (string) $w['order_ref']];
        }
        foreach ($lines as $i => $l) {
            $lines[$i]['where'] = array_values($where[$l['account_id'] . ':' . $l['order_line_id']] ?? []);
        }
        return $ctx->page('profit_parcel_labels', ['accounts' => $accounts, 'lines' => $lines, 'canKey' => $ctx->me()->can(self::PERM_KEY)], 200,
            ['title' => Words::DELIVERY['p2g_title']]);
    }

    public function keyPage(Context $ctx): HtmlResponse
    {
        return $this->keyForm($ctx, 200, null, null);
    }

    /** POST key: the pasted Client ID and secret dropped for the job (P2gKeyDrop::drop). Nothing typed is ever shown back. */
    public function key(Context $ctx): HtmlResponse
    {
        $account = (string) ($ctx->req->field('account') ?? '');
        try {
            if (!in_array($account, P2gKey::ACCOUNTS, true)) {
                throw new CwException('bad_form', Words::DELIVERY['p2g_key_no_account'], 400, ['field' => 'account']);
            }
            P2gKeyDrop::drop($ctx->db, $ctx->caller(), P2gKeyDrop::dir(), $account, (string) ($ctx->req->field('client_id') ?? ''),
                (string) ($ctx->req->field('client_secret') ?? ''));
        } catch (CwException $e) {
            if (in_array($e->errorCode, ['staff_required', 'admin_cannot_post', 'role_not_allowed'], true)) {
                return $ctx->error(403, $e->errorCode, $e->getMessage(), [self::PATH, Words::DELIVERY['p2g_title']]);
            }
            return $this->keyForm($ctx, $e->httpStatus, $e->errorCode === 'p2g_key_refused' ? Words::DELIVERY['p2g_key_bad'] : $e->getMessage(), null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/key', ['saved' => $account]));
    }

    private function keyForm(Context $ctx, int $status, ?string $error, ?string $notice): HtmlResponse
    {
        $saved = (string) ($ctx->req->param('saved') ?? '');
        if ($notice === null && in_array($saved, P2gKey::ACCOUNTS, true)) {
            $notice = Words::DELIVERY['p2g_key_saved'];
        }
        $dir = P2gKeyDrop::dir();
        $accounts = [];
        foreach (Parcel2Go::accounts($ctx->db) as $key => $a) {
            $accounts[] = ['key' => $key, 'name' => $a['name'], 'state' => P2gKeyDrop::state($ctx->db, $dir, $key)];
        }
        return $ctx->page('profit_parcel_keys', ['accounts' => $accounts, 'error' => $error, 'notice' => $notice], $status,
            ['title' => Words::DELIVERY['p2g_key_title']]);
    }
}
