<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Expenses\ExpenseHistory;
use CW\Expenses\ExpenseTypes;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Settings > Expense types (CW-1150; docs/decisions.md EX10): the types an expense is put under and the groups of the regular costs, on the
 * settings shell like Reasons. Everyone looks (reference.view); an admin or a reviewer (settings.manage) adds one, renames one or switches
 * one off or on, each with its reason, kept with who and when (ExpenseTypes re-checks it). Nothing is ever deleted.
 */
final class ExpenseTypesController
{
    public const PATH = '/ui/reference/expense-types';
    private const NOTICES = ['added', 'changed', 'unchanged'];

    public function index(Context $ctx): HtmlResponse
    {
        $n = (string) ($ctx->req->param('notice') ?? '');
        return $this->page($ctx, 200, null, null, in_array($n, self::NOTICES, true) ? Words::EXPENSE_TYPES['notice_' . $n] : null);
    }

    public function add(Context $ctx): HtmlResponse
    {
        $typed = ['kind' => (string) ($ctx->req->field('kind') ?? ''), 'name' => (string) ($ctx->req->field('name') ?? ''), 'reason' => (string) ($ctx->req->field('reason') ?? '')];
        try {
            (new ExpenseTypes($ctx->db))->add($ctx->caller(), $typed['kind'], $typed['name'], $typed['reason']);
        } catch (CwException $e) {
            return $this->page($ctx, $e->httpStatus, $e, $typed, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH, ['notice' => 'added']));
    }

    /** POST {id}/change: do=rename (name) | off | on, with a reason and the version the page showed. */
    public function change(Context $ctx): HtmlResponse
    {
        $do = (string) ($ctx->req->field('do') ?? '');
        $seen = (string) ($ctx->req->field('seen') ?? '');
        $reason = (string) ($ctx->req->field('reason') ?? '');
        try {
            if (!in_array($do, ['rename', 'off', 'on'], true) || preg_match('/^[1-9]\d{0,8}$/D', $seen) !== 1) {
                throw new CwException('bad_form', Words::ERROR['bad_form'], 400);
            }
            $t = new ExpenseTypes($ctx->db);
            $changed = $do === 'rename' ? $t->rename($ctx->caller(), $ctx->id(), (int) $seen, (string) ($ctx->req->field('name') ?? ''), $reason)
                : $t->setActive($ctx->caller(), $ctx->id(), (int) $seen, $do === 'on', $reason);
        } catch (CwException $e) {
            return $this->page($ctx, $e->httpStatus, $e, null, null);
        }
        return HtmlResponse::redirect(Html::url(self::PATH, ['notice' => $changed ? 'changed' : 'unchanged']));
    }

    /** @param array<string, string>|null $typed */
    private function page(Context $ctx, int $status, ?CwException $error, ?array $typed, ?string $notice): HtmlResponse
    {
        $types = new ExpenseTypes($ctx->db);
        $canEdit = $ctx->me()->can('settings.manage');
        $all = $types->all();
        // The history (its reasons may name amounts) only for those who see the expenses or change settings (the Codex check r2, H7; EX28):
        // the types themselves for everyone.
        $seeHistory = $canEdit || $ctx->me()->can(ExpensesController::PERM_VIEW);
        $history = [];
        foreach ($seeHistory ? $all : [] as $t) {
            $history[$t['id']] = ExpenseHistory::of($ctx->db, 'type', $t['id']);
        }
        return $ctx->page('expense_types', [
            'kinds' => array_map(static fn (string $k): array => ['kind' => $k, 'types' => array_values(array_filter($all, static fn (array $t): bool => $t['kind'] === $k))],
                ExpenseTypes::KINDS),
            'history' => $history,
            'seeHistory' => $seeHistory,
            'canEdit' => $canEdit,
            'lookOnly' => $canEdit ? null : Words::EXPENSE_TYPES['look_only'],
            'typed' => $typed ?? ['kind' => 'expense', 'name' => '', 'reason' => ''],
            'error' => $error === null ? null : ExpensesController::plain($error),
        ], $status, ['title' => Words::PAGE_TITLE['expense_types'], 'notice' => $notice]);
    }
}
