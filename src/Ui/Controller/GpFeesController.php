<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Expenses\ExpenseAccess;
use CW\OpResult;
use CW\Profit\GpFees;
use CW\Profit\GpInvoices;
use CW\Profit\ProfitMath;
use CW\Profit\ProfitViews;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Profit › Card fees (CW-1174, 0084; docs/decisions.md GPF10-GPF12): Global Payments' card fees per account and month (CW's card takings,
 * the fees counted, estimated or from the month's invoice) and the invoices entered; the Expenses job enters a month's invoice, changes its
 * figures (with a reason) or deletes it (with a reason). Finance and the Expenses job look (expenses.view); the job writes (expenses.edit,
 * checked again in the service). The page says in plain words how the estimate works, why an invoice goes on the calendar month, and that
 * the fixed charges of an account with no card payments are a regular cost.
 */
final class GpFeesController
{
    public const PATH = '/ui/profit/card-fees';
    public const PERM_VIEW = ExpenseAccess::PERM_VIEW;
    public const PERM_EDIT = ExpenseAccess::PERM_EDIT;
    private const NOTICES = ['added', 'changed', 'unchanged', 'deleted'];
    private const FIELDS = ['account', 'month', 'merchant_no', 'invoice_date', 'sales', 'net', 'vat', 'total', 'note', 'use_rate', 'gap_ok'];

    public function list(Context $ctx): HtmlResponse
    {
        return $this->listPage($ctx, 200, null, [], self::notice($ctx));
    }

    /** POST new: one month's invoice entered (one effect per form, FormOnce), then its page. */
    public function add(Context $ctx): HtmlResponse
    {
        $typed = self::typed($ctx);
        try {
            $r = FormOnce::run($ctx, 'ui.gp_invoice.add', $typed, static function (Db $db) use ($ctx, $typed): OpResult {
                $x = (new GpInvoices($db))->add($ctx->caller(), $typed);
                return OpResult::of(303, ['redirect' => Html::url(self::PATH . '/' . $x['id'], ['notice' => 'added'])]);
            });
        } catch (CwException $e) {
            return ExpensesController::deniedWrite($e) ? ExpensesController::denied($ctx, $e) : $this->listPage($ctx, $e->httpStatus, $e, $typed, null);
        }
        return FormOnce::redirect($r);
    }

    public function show(Context $ctx): HtmlResponse
    {
        return $this->one($ctx, $ctx->id(), 200, null, null, self::notice($ctx));
    }

    /** POST {id}/change: the invoice's figures changed with a reason (the version the page showed), its month spread again. */
    public function change(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $typed = self::typed($ctx);
        try {
            $r = (new GpInvoices($ctx->db))->change($ctx->caller(), $id, self::seen($ctx), $typed, (string) ($ctx->req->field('reason') ?? ''));
        } catch (CwException $e) {
            return ExpensesController::deniedWrite($e) ? ExpensesController::denied($ctx, $e) : $this->one($ctx, $id, $e->httpStatus, $e, $typed, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => $r['changed'] ? 'changed' : 'unchanged']));
    }

    /** POST {id}/delete: the invoice deleted by a mark with a reason ("Yes, delete it" ticked); its month back to estimates. */
    public function delete(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            if ($ctx->req->field('sure') !== '1') {
                throw new CwException('not_sure', Words::PROFIT['gp_not_sure'], 422, ['field' => 'sure']);
            }
            (new GpInvoices($ctx->db))->delete($ctx->caller(), $id, self::seen($ctx), (string) ($ctx->req->field('reason') ?? ''));
        } catch (CwException $e) {
            return ExpensesController::deniedWrite($e) ? ExpensesController::denied($ctx, $e) : $this->one($ctx, $id, $e->httpStatus, $e, null, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => 'deleted']));
    }

    // ---- the pages ---------------------------------------------------------------------------------------------------------------------

    /** @param array<string, string> $typed */
    private function listPage(Context $ctx, int $status, ?CwException $error, array $typed, ?string $notice): HtmlResponse
    {
        $db = $ctx->db;
        $names = [];
        foreach ($db->all('SELECT id, name FROM channel') as $c) {
            $names[(int) $c['id']] = (string) $c['name'];
        }
        $today = ProfitViews::today();
        $accounts = [];
        foreach (GpFees::readConfig($db) as $acct => $c) {
            $months = [];
            foreach ($db->all("SELECT LEFT(day, 7) AS m, COUNT(*) AS n, SUM(base_pence) AS b, SUM(fee_pence) AS f, SUM(IF(state = 'estimate', fee_pence, 0)) AS est "
                . "FROM gp_fee_order WHERE account = ? AND state <> 'off' GROUP BY m ORDER BY m DESC", [$acct]) as $r) {
                $months[(string) $r['m']] = ['month' => (string) $r['m'], 'orders' => (int) $r['n'], 'takings' => (int) $r['b'], 'fees' => (int) $r['f'], 'est' => (int) $r['est'],
                    'invoice' => null];
            }
            foreach ($db->all('SELECT id, month, net_pence, sales_pence, rate_e4, spread_state, other_pence FROM gp_invoice WHERE account = ? AND deleted_at IS NULL', [$acct]) as $i) {
                $m = (string) $i['month'];
                $months[$m] ??= ['month' => $m, 'orders' => 0, 'takings' => 0, 'fees' => 0, 'est' => 0, 'invoice' => null];
                $months[$m]['invoice'] = ['id' => (int) $i['id'], 'net' => (int) $i['net_pence'], 'sales' => (int) $i['sales_pence'],
                    'rate' => ProfitMath::ratePercent((int) $i['rate_e4']), 'due' => $i['spread_state'] === 'due', 'other' => (int) $i['other_pence']];
            }
            krsort($months, SORT_STRING);
            $accounts[] = ['account' => (string) $acct, 'on' => $c['on'], 'from' => $c['from'], 'until' => $c['until'],
                'rate' => $c['rate'] === null ? null : ProfitMath::ratePercent($c['rate']), 'merchant' => $c['merchant'] !== '',
                'shops' => array_map(static fn (int $id): string => $names[$id] ?? (string) $id, $c['shops']), 'months' => array_values($months)];
        }
        $defaults = ['account' => '', 'month' => substr($today, 0, 7), 'merchant_no' => '', 'invoice_date' => '', 'sales' => '', 'net' => '', 'vat' => '0.00', 'total' => '',
            'note' => '', 'use_rate' => '1', 'gap_ok' => ''];
        return $ctx->page('profit_card_fees', ['accounts' => $accounts, 'canEdit' => $ctx->me()->can(self::PERM_EDIT), 'typed' => $typed + $defaults,
            'error' => $error === null ? null : ExpensesController::plain($error), 'errorField' => $error?->detail['field'] ?? null, 'notice' => $notice,
            'formKey' => ExpensesController::formKey($ctx, $error), 'gap' => $error !== null && $error->errorCode === 'gp_takings_gap' ? $error->detail : null],
            $status, ['title' => Words::MENU['profit_card_fees']]);
    }

    /** @param array<string, string>|null $typed */
    private function one(Context $ctx, int $id, int $status, ?CwException $error, ?array $typed, ?string $notice): HtmlResponse
    {
        $inv = $ctx->db->one('SELECT * FROM gp_invoice WHERE id = ?', [$id]);
        if ($inv === null) {
            return $ctx->error(404, 'not_found', 'there is no such invoice', [self::PATH, Words::MENU['profit_card_fees']]);
        }
        $history = $ctx->db->all('SELECT action, version, reason, actor, at, after_json FROM gp_invoice_history WHERE invoice_id = ? ORDER BY id DESC', [$id]);
        $money = static fn (int $p): string => ProfitViews::gbp($p);
        $form = $typed ?? ['merchant_no' => (string) $inv['merchant_no'], 'invoice_date' => (string) $inv['invoice_date'], 'sales' => $money((int) $inv['sales_pence']),
            'net' => $money((int) $inv['net_pence']), 'vat' => $money((int) $inv['vat_pence']), 'total' => $money((int) $inv['total_pence']),
            'note' => (string) ($inv['note'] ?? ''), 'use_rate' => '', 'gap_ok' => ''];
        return $ctx->page('profit_card_fee', ['inv' => $inv, 'history' => $history, 'typed' => $form, 'canEdit' => $ctx->me()->can(self::PERM_EDIT),
            'error' => $error === null ? null : ExpensesController::plain($error), 'errorField' => $error?->detail['field'] ?? null, 'notice' => $notice,
            'cw' => GpInvoices::cwTakings($ctx->db, (string) $inv['account'], (string) $inv['month'])],
            $status, ['title' => Words::say('PROFIT', 'gp_one_title', Words::PROFIT['gp_account_' . $inv['account']] ?? (string) $inv['account'], (string) $inv['month'])]);
    }

    /** @return array<string, string> what the form sent, only its fields */
    private static function typed(Context $ctx): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = (string) ($ctx->req->field($f) ?? '');
        }
        return $out;
    }

    private static function seen(Context $ctx): int
    {
        $s = (string) ($ctx->req->field('seen') ?? '');
        if (preg_match('/^[1-9]\d{0,8}$/D', $s) !== 1) {
            throw new CwException('bad_form', Words::ERROR['bad_form'], 400);
        }
        return (int) $s;
    }

    private static function notice(Context $ctx): ?string
    {
        $n = (string) ($ctx->req->param('notice') ?? '');
        return in_array($n, self::NOTICES, true) ? Words::PROFIT['gp_notice_' . $n] : null;
    }

    /** The months an account's invoice may be entered for, newest first (from its first month to this month, not after its last day's). */
    public static function months(array $account, string $today): array
    {
        if (!$account['on']) {
            return [];
        }
        $out = [];
        $end = $account['until'] !== null && substr((string) $account['until'], 0, 7) < substr($today, 0, 7) ? substr((string) $account['until'], 0, 7) : substr($today, 0, 7);
        for ($m = $end; $m >= substr((string) $account['from'], 0, 7); $m = self::prev($m)) {
            $out[] = $m;
            if (count($out) >= 24) {
                break;
            }
        }
        return $out;
    }

    private static function prev(string $m): string
    {
        $y = (int) substr($m, 0, 4);
        $mo = (int) substr($m, 5, 2) - 1;
        return $mo === 0 ? sprintf('%04d-12', $y - 1) : sprintf('%04d-%02d', $y, $mo);
    }
}
