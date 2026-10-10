<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\StockOps\OtherAccounts;
use CW\StockOps\StockOps;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Stock › Transfers › Balance owed (/ui/stock/accounts; pack A1, owner answers Q6/Q7; docs/decisions.md SO8): each warehouse that
 * holds another account's stock (the owner's VPG 2 room; its account's name comes from the Warehouses page), with what it holds now
 * (units, products, about what it is worth at the suggested release prices), what was released from it this month, and the balance
 * owed to the account; its releases and payments, newest first. The people who record payments (accounts.pay) record one (amount,
 * the day it was paid, the bank reference) or reverse one recorded by mistake, with a reason; everyone who reads stock records looks.
 */
final class OtherAccountsController
{
    /** @param array<string, string> $typed what a refused payment form sent */
    public function index(Context $ctx, int $status = 200, ?CwException $error = null, ?int $errorFor = null, array $typed = []): HtmlResponse
    {
        $accounts = $ctx->otherAccounts();
        $me = $ctx->me();
        $canPay = OtherAccounts::mayPay($me->roles);
        $rows = [];
        foreach ($accounts->accounts() as $a) {
            $entries = array_map(static fn (array $e): array => $e + [
                'what' => match ($e['kind']) {
                    'release' => Words::say('ACCOUNTS', 'entry_release', (string) ($e['number'] ?? '')),
                    'release_reversal' => Words::say('ACCOUNTS', 'entry_release_reversal', (string) ($e['number'] ?? '')),
                    'payment' => Words::ACCOUNTS['entry_payment'],
                    default => Words::ACCOUNTS['entry_payment_reversal'],
                },
                'href' => $e['document_id'] === null ? null : StockOps::PATHS['release'] . '/' . $e['document_id'],
                'reversible' => $canPay && $e['kind'] === 'payment' && $e['reversed_by'] === null,
            ], $accounts->entries($a['id']));
            $balance = $a['balance'];
            $rows[] = $a + [
                'entries' => $entries,
                'balance_text' => bccomp($balance, '0', 2) < 0 ? Words::say('ACCOUNTS', 'credit', Html::money(bcsub('0', $balance, 2))) : Html::money($balance),
                'error' => $errorFor === $a['id'] && $error !== null ? self::plain($error) : null,
                'typed' => ($errorFor === $a['id'] ? $typed : []) + ['amount' => '', 'paid_on' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d'),
                    'reference' => '', 'note' => ''],
            ];
        }
        $notice = match ($ctx->req->param('notice')) {
            'paid' => Words::say('ACCOUNTS', 'paid', Html::money((string) ($ctx->req->param('amount') ?? '0')), Html::money((string) ($ctx->req->param('balance') ?? '0'))),
            'reversed' => Words::say('ACCOUNTS', 'reversed', Html::money((string) ($ctx->req->param('balance') ?? '0'))),
            default => null,
        };
        return $ctx->page('stock_accounts', [
            'accounts' => $rows,
            'canPay' => $canPay,
            'canRelease' => OtherAccounts::mayRelease($me->roles),
            'canWarehouses' => $me->can('reference.view'),
            'lookOnly' => $canPay ? null : Words::whoCan(OtherAccounts::PERMISSION),
            'error' => $errorFor === null && $error !== null ? self::plain($error) : null,
        ], $status, ['title' => Words::ACCOUNTS['title'], 'notice' => $notice]);
    }

    /** POST /ui/stock/accounts/{id}/payments: a payment to the account of warehouse {id}. */
    public function pay(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        $typed = ['amount' => (string) ($req->field('amount') ?? ''), 'paid_on' => (string) ($req->field('paid_on') ?? ''),
            'reference' => (string) ($req->field('reference') ?? ''), 'note' => (string) ($req->field('note') ?? '')];
        try {
            $r = $ctx->otherAccounts()->recordPayment($ctx->caller(), $id, $typed['amount'], $typed['paid_on'], $typed['reference'],
                $typed['note'] === '' ? null : $typed['note']);
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e, $id, $typed);
        }
        return HtmlResponse::redirect(Html::url('/ui/stock/accounts', ['notice' => 'paid', 'amount' => OtherAccounts::amount($typed['amount']),
            'balance' => $r['balance']]) . '#account-' . $id);
    }

    /** POST /ui/stock/accounts/payments/{id}/reverse: reverses a payment recorded by mistake, with a reason. */
    public function reverse(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $wh = UiRequest::id((string) ($ctx->db->value('SELECT warehouse_id FROM other_account_entry WHERE id = ?', [$id]) ?? ''));
        try {
            $r = $ctx->otherAccounts()->reversePayment($ctx->caller(), $id, (string) ($ctx->req->field('reason') ?? ''));
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e, $wh);
        }
        return HtmlResponse::redirect(Html::url('/ui/stock/accounts', ['notice' => 'reversed', 'balance' => $r['balance']]) . '#account-' . $r['warehouse_id']);
    }

    public static function plain(CwException $e): string
    {
        if ($e->errorCode === 'more_than_owed') {
            return Words::say('ACCOUNT_ERROR', 'more_than_owed', Html::money((string) ($e->detail['balance'] ?? '0')));
        }
        return Words::ACCOUNT_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage());
    }
}
